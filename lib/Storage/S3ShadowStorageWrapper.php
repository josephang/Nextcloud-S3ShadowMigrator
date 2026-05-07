<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Storage;

use OC\Files\Storage\Wrapper\Wrapper;
use OCA\S3ShadowMigrator\Db\FileCacheUpdater;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Aws\S3\S3Client;
use OCP\IConfig;

class S3ShadowStorageWrapper extends Wrapper {
    private ?FileCacheUpdater $fileCacheUpdater = null;
    private LoggerInterface $logger;
    private IConfig $config;
    private IDBConnection $db;
    private ?S3Client $s3Client = null;

    public function __construct($parameters) {
        parent::__construct($parameters);
        $this->logger = \OC::$server->get(LoggerInterface::class);
        $this->config = \OC::$server->get(IConfig::class);
        $this->db     = \OC::$server->get(IDBConnection::class);
    }

    private function getFileCacheUpdater(): FileCacheUpdater {
        if ($this->fileCacheUpdater === null) {
            $this->fileCacheUpdater = new FileCacheUpdater($this->db, $this->logger);
        }
        return $this->fileCacheUpdater;
    }

    private function getS3Client(): S3Client {
        if ($this->s3Client === null) {
            $s3Config = \OCA\S3ShadowMigrator\Service\S3ConfigHelper::getS3Config($this->config, $this->db);
            $this->s3Client = \OCA\S3ShadowMigrator\Service\S3ConfigHelper::createS3Client($s3Config);
            $this->s3Client->registerStreamWrapper();
        }
        return $this->s3Client;
    }

    /**
     * Checks oc_s3shadow_files for a valid, active S3 key for this file.
     * Returns the s3_key string if the file should be served from S3,
     * or null if it should be served from local disk.
     *
     * Detection logic (ETag-free):
     *   - Row must exist in oc_s3shadow_files
     *   - status must NOT be 'uploading' (mid-upload = serve local)
     *   - s3_key must be non-empty
     */
    private function getActiveS3Key(int $fileId): ?array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('s3_key', 'is_vault', 'status')
               ->from('s3shadow_files')
               ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId)));
            $row = $qb->executeQuery()->fetch();

            if (!$row || empty($row['s3_key']) || ($row['status'] ?? '') === 'uploading') {
                return null;
            }

            return [
                's3_key'   => $row['s3_key'],
                'is_vault' => (bool)$row['is_vault'],
                'status'   => $row['status'] ?? 'active',
            ];
        } catch (\Exception $e) {
            $this->logger->warning('S3ShadowMigrator: getActiveS3Key failed for ID ' . $fileId . ': ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return null;
        }
    }

    /**
     * Determines if a physical file is actually a 0-byte sparse stub.
     * Sparse stubs still report their original apparent size to filesize(),
     * but occupy 0 blocks (or < 64KB) on the physical disk.
     */
    private function isPhysicallySparse(string $localPath): bool {
        $size = filesize($localPath);
        if ($size === 0) {
            return true;
        }
        
        $stat = @stat($localPath);
        if ($stat !== false && isset($stat['blocks'])) {
            $allocatedBytes = (int)$stat['blocks'] * 512;
            if ($allocatedBytes < 65536 && $size > $allocatedBytes) {
                // Double check for null bytes at the start to prevent false positives on tiny files
                $f = @fopen($localPath, 'r');
                if ($f) {
                    $head = fread($f, 8);
                    fclose($f);
                    if ($head === str_repeat("\0", strlen($head))) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public function fopen($path, $mode) {
        // Only intercept read modes
        if (!str_contains($mode, 'r')) {
            return $this->storage->fopen($path, $mode);
        }

        // Get fileId from cache
        $cache = $this->storage->getCache();
        $cacheEntry = $cache->get($path);

        if (!$cacheEntry) {
            return $this->storage->fopen($path, $mode);
        }

        $fileId = (int)$cacheEntry->getId();
        $s3Info = $this->getActiveS3Key($fileId);

        if ($s3Info === null) {
            // Not a sparse/migrated file — serve from local disk
            return $this->storage->fopen($path, $mode);
        }

        // Detect if the local file is actually 0-bytes (sparse) or has real content.
        // If the local file has real content (e.g. mid-upload or user wrote a new file),
        // serve from disk. Only proxy to S3 if local is empty/sparse.
        $localPath = $this->storage->getLocalFile($path);
        if ($localPath !== false && file_exists($localPath)) {
            if (!$this->isPhysicallySparse($localPath)) {
                // Local file has actual content — serve it directly
                return $this->storage->fopen($path, $mode);
            }
        }

        $s3Key   = $s3Info['s3_key'];
        $isVault = $s3Info['is_vault'];

        // Block background processes (Preview, Search) from reading sparse files
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        foreach ($backtrace as $trace) {
            $class = $trace['class'] ?? '';
            if (str_contains($class, 'Preview') || str_contains($class, 'FullTextSearch')) {
                $this->logger->debug("S3ShadowMigrator: blocked internal read of sparse file '{$path}' by {$class}", ['app' => 's3shadowmigrator']);
                return false;
            }
        }

        // Proxy from S3
        try {
            $s3Config   = \OCA\S3ShadowMigrator\Service\S3ConfigHelper::getS3Config($this->config, $this->db);
            $bucketName = $s3Config['bucket'];
            $this->getS3Client(); // registers stream wrapper
            $s3Url = "s3://{$bucketName}/{$s3Key}";

            if ($isVault) {
                $this->logger->info("S3ShadowMigrator: proxying and decrypting Vault file '{$path}' from S3", ['app' => 's3shadowmigrator']);
                $vaultKeyHex  = $this->config->getAppValue('s3shadowmigrator', 'vault_key', '');
                $tempEncFile  = tempnam(sys_get_temp_dir(), 's3v_');
                $tempDecFile  = tempnam(sys_get_temp_dir(), 's3d_');
                copy($s3Url, $tempEncFile);
                $ivHex = file_get_contents($tempEncFile, false, null, 0, 32);
                $cmd = sprintf(
                    'tail -c +33 %s | openssl enc -d -aes-256-cbc -K %s -iv %s -out %s',
                    escapeshellarg($tempEncFile), escapeshellarg($vaultKeyHex),
                    escapeshellarg($ivHex), escapeshellarg($tempDecFile)
                );
                exec($cmd, $output, $returnVar);
                unlink($tempEncFile);
                if ($returnVar !== 0) {
                    $this->logger->error("S3ShadowMigrator: failed to decrypt Vault file {$path}", ['app' => 's3shadowmigrator']);
                    unlink($tempDecFile);
                    return false;
                }
                $fp = fopen($tempDecFile, $mode);
                unlink($tempDecFile);
                return $fp;
            }

            $this->logger->info("S3ShadowMigrator: proxying streaming read for sparse file '{$path}' from S3", ['app' => 's3shadowmigrator']);
            return fopen($s3Url, $mode);

        } catch (\Exception $e) {
            $this->logger->error("S3ShadowMigrator: failed to open S3 stream for sparse file '{$path}': " . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return false;
        }
    }

    public function getDirectDownload(string $path): array|false {
        $cache      = $this->storage->getCache();
        $cacheEntry = $cache->get($path);
        if (!$cacheEntry) {
            return $this->storage->getDirectDownload($path);
        }

        $url = $this->buildPresignedUrl((int)$cacheEntry->getId(), $path);
        return $url !== null ? $url : $this->storage->getDirectDownload($path);
    }

    public function getDirectDownloadById(string $fileId): array|false {
        $cache      = $this->storage->getCache();
        $cacheEntry = $cache->get((int)$fileId);
        if (!$cacheEntry) {
            return $this->storage->getDirectDownloadById($fileId);
        }

        $url = $this->buildPresignedUrl((int)$fileId, $cacheEntry->getPath());
        return $url !== null ? $url : $this->storage->getDirectDownloadById($fileId);
    }

    /**
     * Builds a pre-signed S3 URL for direct client-side download/streaming.
     * Returns null if the file should not be redirected (not sparse, vault, mid-upload).
     */
    private function buildPresignedUrl(int $fileId, string $path): ?array {
        $s3Info = $this->getActiveS3Key($fileId);
        if ($s3Info === null || $s3Info['is_vault']) {
            return null; // Vault files must decrypt server-side; non-sparse served locally
        }

        // Only redirect if local file is actually empty (sparse)
        $localPath = $this->storage->getLocalFile($path);
        if ($localPath !== false && file_exists($localPath)) {
            if (!$this->isPhysicallySparse($localPath)) {
                return null; // Has real local content, don't redirect
            }
        }

        try {
            $s3Config  = \OCA\S3ShadowMigrator\Service\S3ConfigHelper::getS3Config($this->config, $this->db);
            $s3Client  = $this->getS3Client();
            $cmd = $s3Client->getCommand('GetObject', [
                'Bucket' => $s3Config['bucket'],
                'Key'    => $s3Info['s3_key'],
            ]);
            $presignedRequest = $s3Client->createPresignedRequest($cmd, '+1 hour');
            $this->logger->info("S3ShadowMigrator: direct download URL for '{$s3Info['s3_key']}'", ['app' => 's3shadowmigrator']);
            return ['url' => (string)$presignedRequest->getUri()];
        } catch (\Exception $e) {
            $this->logger->error("S3ShadowMigrator: failed to generate direct URL: " . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return null;
        }
    }
}
