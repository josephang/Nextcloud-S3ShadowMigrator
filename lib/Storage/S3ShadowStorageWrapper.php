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
    private ?S3Client $s3Client = null;

    public function __construct($parameters) {
        parent::__construct($parameters);
        $this->logger = \OC::$server->get(LoggerInterface::class);
        $this->config = \OC::$server->get(IConfig::class);
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
            $region   = $this->config->getAppValue('s3shadowmigrator', 's3_region', 'us-east-1');
            $endpoint = $this->config->getAppValue('s3shadowmigrator', 's3_endpoint', '');
            $key      = $this->config->getAppValue('s3shadowmigrator', 's3_key', '');
            $secret   = $this->config->getAppValue('s3shadowmigrator', 's3_secret', '');

            $s3Config = [
                'version'                 => 'latest',
                'region'                  => $region,
                'use_path_style_endpoint' => true,
                'credentials'             => [
                    'key'    => $key,
                    'secret' => $secret,
                ],
            ];

            if (!empty($endpoint)) {
                $s3Config['endpoint'] = $endpoint;
            }

            $this->s3Client = new S3Client($s3Config);
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
        if (!$this->getFileCacheUpdater()->isFileSparse($fileId)) {
            return $this->storage->fopen($path, $mode);
        }

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

            $bucketName = $this->config->getAppValue('s3shadowmigrator', 's3_bucket_name', '');
            $s3Key = $username . '/' . ltrim($path, '/');
            
            // Ensure S3 client and stream wrapper are registered
            $this->getS3Client();
            
            $s3Url = "s3://{$bucketName}/{$s3Key}";
            $this->logger->info("S3ShadowMigrator: proxying streaming read for sparse file '{$path}' from S3", ['app' => 's3shadowmigrator']);
            
            return fopen($s3Url, $mode);
        } catch (\Exception $e) {
            $this->logger->error("S3ShadowMigrator: failed to open S3 stream for sparse file '{$path}': " . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return false;
        }
    }
}
