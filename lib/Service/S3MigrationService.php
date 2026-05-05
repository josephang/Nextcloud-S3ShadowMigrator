<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Service;

use OCA\S3ShadowMigrator\Db\FileCacheUpdater;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\File;
use OCP\Lock\ILockingProvider;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

class S3MigrationService {
    private FileCacheUpdater $fileCacheUpdater;
    private IRootFolder $rootFolder;
    private IConfig $config;
    private IDBConnection $db;
    private LoggerInterface $logger;
    private ?S3Client $s3Client = null;

    public function __construct(
        FileCacheUpdater $fileCacheUpdater,
        IRootFolder $rootFolder,
        IConfig $config,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->fileCacheUpdater = $fileCacheUpdater;
        $this->rootFolder = $rootFolder;
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

            $s3Config = [
                'version' => 'latest',
                'region'  => $region,
                'use_path_style_endpoint' => true,
                'credentials' => [
                    'key'    => $key,
                    'secret' => $secret,
                ],
            ];

            if (!empty($endpoint)) {
                $s3Config['endpoint'] = $endpoint;
            }

            $this->s3Client = new S3Client($s3Config);
        }
        return $this->s3Client;
    }

    public function migrateBatch(int $limit = 500): void {
        $s3BucketIdentifier = $this->config->getAppValue('s3shadowmigrator', 's3_bucket_identifier', '');
        if (empty($s3BucketIdentifier)) {
            $this->logger->warning('Target S3 bucket identifier is not configured.', ['app' => 's3shadowmigrator']);
            return;
        }

        $s3StorageId = $this->fileCacheUpdater->getS3StorageId($s3BucketIdentifier);
        if ($s3StorageId === null) {
            $this->logger->error("Could not find storage ID for identifier: {$s3BucketIdentifier}", ['app' => 's3shadowmigrator']);
            return;
        }

        $filesToMigrate = $this->getFilesToMigrate($limit, $s3StorageId);

        foreach ($filesToMigrate as $fileRecord) {
            $this->migrateFile((int)$fileRecord['fileid'], $fileRecord['path'], clone $fileRecord, $s3StorageId);
        }
    }

    /**
     * Finds local files to migrate, excluding appdata thumbnails.
     */
    private function getFilesToMigrate(int $limit, int $s3StorageId): array {
        $query = $this->db->getQueryBuilder();
        $query->select('*')
              ->from('filecache')
              ->where($query->expr()->neq('storage', $query->createNamedParameter($s3StorageId))) // Anything not already on S3
              ->andWhere($query->expr()->notLike('path', $query->createNamedParameter('appdata_%/preview/%')))
              ->andWhere($query->expr()->notLike('path', $query->createNamedParameter('appdata_%/css/%')))
              ->andWhere($query->expr()->eq('mimetype', $query->createNamedParameter(2))) // Exclude folders (mimetype = 2 is file in some contexts, but let's query actual file type or just size > 0)
              // Actually mimetype is an int pointing to oc_mimetypes. Let's just exclude directory type if known, or size = 0.
              ->andWhere($query->expr()->gt('size', $query->createNamedParameter(0)))
              ->setMaxResults($limit);

        return $query->executeQuery()->fetchAll();
    }

    public function migrateFile(int $fileId, string $internalPath, array $fileRecord, int $s3StorageId): bool {
        // Attempt to find the Node in the VFS
        $nodes = $this->rootFolder->getById($fileId);
        if (empty($nodes)) {
            $this->logger->debug("File ID {$fileId} not found in VFS.", ['app' => 's3shadowmigrator']);
            return false;
        }

        /** @var File $node */
        $node = $nodes[0];
        
        if (!($node instanceof File)) {
            return false;
        }

        try {
            $node->lock(ILockingProvider::LOCK_EXCLUSIVE);
        } catch (\OCP\Lock\LockedException $e) {
            $this->logger->info("File ID {$fileId} is currently locked. Skipping migration.", ['app' => 's3shadowmigrator']);
            return false;
        }

        try {
            $localPhysicalPath = $this->config->getSystemValue('datadirectory', '/var/www/nextcloud/data') . '/' . $internalPath;
            if (!file_exists($localPhysicalPath) || !is_writable($localPhysicalPath)) {
                $this->logger->info("File ID {$fileId} is missing or not writable. Skipping migration.", ['app' => 's3shadowmigrator']);
                $node->unlock(ILockingProvider::LOCK_EXCLUSIVE);
                return false;
            }

            // Verify local checksum
            $localHash = md5_file($localPhysicalPath);

            $bucketName = $this->config->getAppValue('s3shadowmigrator', 's3_bucket_name', '');
            
            // S3 Key usually matches the internal path, minus the user ID if the bucket is shared, 
            // but Nextcloud external storage 's3' appends a prefix. We'll use the exact $internalPath.
            $s3Key = $internalPath; 

            // Upload to S3
            $s3 = $this->getS3Client();
            $result = $s3->putObject([
                'Bucket'     => $bucketName,
                'Key'        => $s3Key,
                'SourceFile' => $localPhysicalPath,
                'ContentMD5' => base64_encode(pack('H*', $localHash)),
            ]);

            // If upload succeeds, update DB in a transaction
            $dbSuccess = $this->fileCacheUpdater->migrateFileRecord($fileId, $s3StorageId, $s3Key);

            if ($dbSuccess) {
                // Safely delete the local file
                unlink($localPhysicalPath);
                $this->logger->info("Successfully migrated File ID {$fileId} to S3.", ['app' => 's3shadowmigrator']);
            } else {
                // If DB update failed, the transaction rolled back. 
                // We should probably delete the S3 object to prevent orphans, but local file is safe.
                $s3->deleteObject([
                    'Bucket' => $bucketName,
                    'Key'    => $s3Key
                ]);
            }

            $node->unlock(ILockingProvider::LOCK_EXCLUSIVE);
            return $dbSuccess;

        } catch (S3Exception $e) {
            $this->logger->error("S3 Upload failed for File ID {$fileId}: " . $e->getMessage(), ['app' => 's3shadowmigrator']);
            $node->unlock(ILockingProvider::LOCK_EXCLUSIVE);
            return false;
        } catch (\Exception $e) {
            $this->logger->error("Unexpected error migrating File ID {$fileId}: " . $e->getMessage(), ['app' => 's3shadowmigrator']);
            $node->unlock(ILockingProvider::LOCK_EXCLUSIVE);
            return false;
        }
    }
}
