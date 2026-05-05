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
            $db = \OC::$server->get(IDBConnection::class);
            $this->fileCacheUpdater = new FileCacheUpdater($db, $this->logger);
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

    public function fopen($path, $mode) {
        // Only intercept read modes
        if (!str_contains($mode, 'r')) {
            return $this->storage->fopen($path, $mode);
        }

        // Get fileId from cache
        $cache = $this->storage->getCache();
        $fileId = $cache->getId($path);

        if (!$fileId) {
            return $this->storage->fopen($path, $mode);
        }

        // Check if file is sparse
        $sparseStatus = $this->getFileCacheUpdater()->getFileSparseStatus($fileId);
        if ($sparseStatus === null || !$sparseStatus['is_sparse']) {
            return $this->storage->fopen($path, $mode);
        }
        
        $isVault = $sparseStatus['is_vault'];

        // FILE IS SPARSE. 
        // 1. Block internal background processes (Preview, Search, Scanner)
        // 2. Stream from S3 for WebDAV Desktop Clients (since they panic on 302 redirects)

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        foreach ($backtrace as $trace) {
            $class = $trace['class'] ?? '';
            // Block Previews and Full Text Search from reading sparse files
            if (str_contains($class, 'Preview') || str_contains($class, 'FullTextSearch')) {
                $this->logger->debug("S3ShadowMigrator: blocked internal read of sparse file '{$path}' by {$class}", ['app' => 's3shadowmigrator']);
                return false;
            }
        }

        // If it wasn't blocked, and it's sparse, returning the local file would serve NULL bytes.
        // We must transparently stream from S3.
        try {
            $storageId = $this->storage->getId();
            $username = str_starts_with($storageId, 'home::') ? substr($storageId, strlen('home::')) : '';
            if (empty($username)) {
                return false;
            }

            $s3Config = \OCA\S3ShadowMigrator\Service\S3ConfigHelper::getS3Config($this->config, $this->db);
            $bucketName = $s3Config['bucket'];
            $s3Key = $username . '/' . ltrim($path, '/');
            
            // Ensure S3 client and stream wrapper are registered
            $this->getS3Client();
            
            $s3Url = "s3://{$bucketName}/{$s3Key}";
            
            if ($isVault) {
                $this->logger->info("S3ShadowMigrator: proxying and decrypting Vault file '{$path}' from S3", ['app' => 's3shadowmigrator']);
                $vaultKeyHex = $this->config->getAppValue('s3shadowmigrator', 'vault_key', '');
                
                $tempEncFile = tempnam(sys_get_temp_dir(), 's3v_');
                $tempDecFile = tempnam(sys_get_temp_dir(), 's3d_');
                
                // Copy S3 stream to local temp
                copy($s3Url, $tempEncFile);
                
                // Read 32-byte Hex IV
                $ivHex = file_get_contents($tempEncFile, false, null, 0, 32);
                
                // Decrypt using OpenSSL CLI (skip first 32 bytes)
                $cmd = sprintf(
                    'tail -c +33 %s | openssl enc -d -aes-256-cbc -K %s -iv %s -out %s',
                    escapeshellarg($tempEncFile),
                    escapeshellarg($vaultKeyHex),
                    escapeshellarg($ivHex),
                    escapeshellarg($tempDecFile)
                );
                exec($cmd, $output, $returnVar);
                
                unlink($tempEncFile); // Cleanup encrypted temp
                
                if ($returnVar !== 0) {
                    $this->logger->error("S3ShadowMigrator: failed to decrypt Vault file {$path}", ['app' => 's3shadowmigrator']);
                    unlink($tempDecFile);
                    return false;
                }
                
                $fp = fopen($tempDecFile, $mode);
                unlink($tempDecFile); // Linux trick: file deleted from directory but readable until closed
                return $fp;
            }

            $this->logger->info("S3ShadowMigrator: proxying streaming read for sparse file '{$path}' from S3", ['app' => 's3shadowmigrator']);
            return fopen($s3Url, $mode);
        } catch (\Exception $e) {
            $this->logger->error("S3ShadowMigrator: failed to open S3 stream for sparse file '{$path}': " . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return false;
        }
    }
}
