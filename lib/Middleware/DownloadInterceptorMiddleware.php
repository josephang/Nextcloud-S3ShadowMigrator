<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Middleware;

use OCP\Encryption\IManager as EncryptionManager;
use OCP\IRequest;
use OCP\Files\Node;
use OCP\Files\File;
use OCP\IConfig;
use OCP\Db\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Aws\S3\S3Client;

class DownloadInterceptorMiddleware {
    private EncryptionManager $encryptionManager;
    private IRequest $request;
    private IConfig $config;
    private IDBConnection $db;
    private LoggerInterface $logger;
    private ?S3Client $s3Client = null;

    public function __construct(
        EncryptionManager $encryptionManager,
        IRequest $request,
        IConfig $config,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->encryptionManager = $encryptionManager;
        $this->request = $request;
        $this->config = $config;
        $this->db = $db;
        $this->logger = $logger;
    }

    private function getS3Client(): S3Client {
        if ($this->s3Client === null) {
            $region = $this->config->getAppValue('s3shadowmigrator', 's3_region', 'us-east-1');
            $endpoint = $this->config->getAppValue('s3shadowmigrator', 's3_endpoint', '');
            $key = $this->config->getAppValue('s3shadowmigrator', 's3_key', '');
            $secret = $this->config->getAppValue('s3shadowmigrator', 's3_secret', '');

            $this->s3Client = new S3Client([
                'version' => 'latest',
                'region'  => $region,
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => true,
                'credentials' => [
                    'key'    => $key,
                    'secret' => $secret,
                ],
            ]);
        }
        return $this->s3Client;
    }

    /**
     * Intercept a download request. Returns true if redirect occurred and execution should stop.
     */
    public function interceptDownload(File $node): bool {
        // 1. User Agent Check for older Desktop Sync Clients
        $userAgent = $this->request->getHeader('User-Agent');
        if (strpos((string)$userAgent, 'mirall') !== false || strpos((string)$userAgent, 'Nextcloud-') !== false) {
            // "mirall" is the internal name for the ownCloud/Nextcloud desktop client
            // Let the desktop client proxy through the server to avoid WebDAV 302 TLS panics
            $this->logger->debug('S3ShadowMigrator bypassing redirect for Desktop Client: ' . $userAgent, ['app' => 's3shadowmigrator']);
            return false;
        }

        // 2. Encryption Check
        if ($this->encryptionManager->isReady()) {
            $fileInfo = $node->getFileInfo();
            if ($fileInfo->isEncrypted()) {
                $this->logger->debug('S3ShadowMigrator bypassing redirect for encrypted file: ' . $node->getId(), ['app' => 's3shadowmigrator']);
                return false;
            }
        }

        // 3. Check if file is stored in our target S3 mount
        $s3BucketIdentifier = $this->config->getAppValue('s3shadowmigrator', 's3_bucket_identifier', '');
        $fileStorageId = $node->getStorage()->getId();
        
        if ($fileStorageId !== $s3BucketIdentifier && !str_starts_with($fileStorageId, 's3::')) {
             // Not an S3 stored file
             return false;
        }

        // 4. Generate Pre-signed URL and Redirect
        try {
            $s3 = $this->getS3Client();
            $bucketName = $this->config->getAppValue('s3shadowmigrator', 's3_bucket_name', '');
            
            // The internal path on the S3 bucket is the node's internal path
            $s3Key = $node->getInternalPath();
            
            $cmd = $s3->getCommand('GetObject', [
                'Bucket' => $bucketName,
                'Key'    => $s3Key
            ]);
            
            // Generate a pre-signed URL valid for 15 minutes
            $request = $s3->createPresignedRequest($cmd, '+15 minutes');
            $presignedUrl = (string)$request->getUri();
            
            $this->logger->info("S3ShadowMigrator issuing 302 Redirect for file: {$s3Key}", ['app' => 's3shadowmigrator']);
            
            // Issue HTTP 302 Redirect
            header('Location: ' . $presignedUrl, true, 302);
            exit(); // Terminate execution immediately to stop PHP from streaming the file
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate S3 pre-signed URL: ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return false;
        }
    }
}
