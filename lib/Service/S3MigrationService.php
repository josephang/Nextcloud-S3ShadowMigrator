<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Service;

use OCA\S3ShadowMigrator\Db\FileCacheUpdater;
use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCP\Lock\ILockingProvider;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Aws\S3\S3Client;
use Aws\S3\MultipartUploader;
use Aws\S3\Exception\S3Exception;
use Aws\Exception\MultipartUploadException;

class S3MigrationService {
    private FileCacheUpdater $fileCacheUpdater;
    private IRootFolder $rootFolder;
    private IConfig $config;
    private IDBConnection $db;
    private LoggerInterface $logger;
    private ?S3Client $s3Client = null;

    // Files >= 100 MB use multipart upload
    private const MULTIPART_THRESHOLD_BYTES = 104857600;

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
            $region   = $this->config->getAppValue('s3shadowmigrator', 's3_region', 'us-east-1');
            $endpoint = $this->config->getAppValue('s3shadowmigrator', 's3_endpoint', '');
            $key      = $this->config->getAppValue('s3shadowmigrator', 's3_key', '');
            $secret   = $this->config->getAppValue('s3shadowmigrator', 's3_secret', '');

            if (empty($key) || empty($secret)) {
                throw new \RuntimeException('S3 credentials are not configured in S3 Shadow Migrator settings.');
            }

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
        }
        return $this->s3Client;
    }

    /**
     * Main batch entry point called by the cron job and CLI command.
     * Drains local home storage files into the configured S3/B2 bucket.
     */
    public function migrateBatch(int $limit = 500): int {
        $s3BucketIdentifier = $this->config->getAppValue('s3shadowmigrator', 's3_bucket_identifier', '');
        if (empty($s3BucketIdentifier)) {
            $this->logger->warning('S3ShadowMigrator: target bucket identifier is not configured.', ['app' => 's3shadowmigrator']);
            return 0;
        }

        $s3StorageId = $this->fileCacheUpdater->getS3StorageId($s3BucketIdentifier);
        if ($s3StorageId === null) {
            $this->logger->error("S3ShadowMigrator: could not find storage numeric_id for '{$s3BucketIdentifier}'. Is the external storage mounted?", ['app' => 's3shadowmigrator']);
            return 0;
        }

        $filesToMigrate = $this->getLocalFilesToMigrate($limit, $s3StorageId);
        $count = count($filesToMigrate);

        if ($count === 0) {
            $this->logger->info('S3ShadowMigrator: no local files found to migrate. Drain is complete or nothing is configured.', ['app' => 's3shadowmigrator']);
            return 0;
        }

        $this->logger->info("S3ShadowMigrator: starting batch of {$count} files.", ['app' => 's3shadowmigrator']);
        $succeeded = 0;

        foreach ($filesToMigrate as $fileRecord) {
            // Extract username from storage ID string e.g. "home::josephang" → "josephang"
            $username = $this->extractUsernameFromStorageId($fileRecord['storage_id_string']);
            if ($username === null) {
                $this->logger->warning("S3ShadowMigrator: could not extract username from storage_id '{$fileRecord['storage_id_string']}'. Skipping.", ['app' => 's3shadowmigrator']);
                continue;
            }

            if ($this->migrateFileRecord($fileRecord, $username, $s3StorageId)) {
                $succeeded++;
            }
        }

        $this->logger->info("S3ShadowMigrator: batch complete. {$succeeded}/{$count} files migrated.", ['app' => 's3shadowmigrator']);
        return $succeeded;
    }

    /**
     * Finds files in local home:: storages only.
     * Joins oc_storages to get the storage string ID for username extraction.
     * Excludes directories, thumbnails, appdata, and partial uploads.
     */
    private function getLocalFilesToMigrate(int $limit, int $s3StorageId): array {
        $query = $this->db->getQueryBuilder();
        $query->select('fc.fileid', 'fc.path', 'fc.size', 'fc.storage', 's.id AS storage_id_string')
              ->from('filecache', 'fc')
              ->innerJoin('fc', 'storages', 's', $query->expr()->eq('fc.storage', 's.numeric_id'))
              ->innerJoin('fc', 'mimetypes', 'mt', $query->expr()->eq('fc.mimetype', 'mt.id'))
              // Only local home storages
              ->where($query->expr()->like('s.id', $query->createNamedParameter('home::%')))
              // Only actual files (not directories)
              ->andWhere($query->expr()->neq('mt.mimetype', $query->createNamedParameter('httpd/unix-directory')))
              // Only real user files, not thumbnails/cache
              ->andWhere($query->expr()->like('fc.path', $query->createNamedParameter('files/%')))
              // Must have content
              ->andWhere($query->expr()->gt('fc.size', $query->createNamedParameter(0)))
              // Exclude partial/temp uploads
              ->andWhere($query->expr()->notLike('fc.path', $query->createNamedParameter('%.ocTransferId%')))
              ->andWhere($query->expr()->notLike('fc.path', $query->createNamedParameter('%/.ocdata')))
              // Order by smallest files first for faster initial drain
              ->orderBy('fc.size', 'ASC')
              ->setMaxResults($limit);

        return $query->executeQuery()->fetchAll();
    }

    /**
     * Extracts username from a home storage ID string.
     * "home::josephang" → "josephang"
     * "home::duck@origin600i.com" → "duck@origin600i.com"
     */
    private function extractUsernameFromStorageId(string $storageId): ?string {
        if (!str_starts_with($storageId, 'home::')) {
            return null;
        }
        $username = substr($storageId, strlen('home::'));
        return !empty($username) ? $username : null;
    }

    /**
     * Migrate a single file record from local home storage to S3.
     * The S3 key structure is: <username>/<filecache_path>
     * e.g. "josephang/files/Documents/report.pdf"
     * This makes files accessible in Nextcloud at /originhangar/josephang/files/Documents/report.pdf
     */
    public function migrateFileRecord(array $fileRecord, string $username, int $s3StorageId): bool {
        $fileId      = (int)$fileRecord['fileid'];
        $cachePath   = $fileRecord['path']; // e.g. "files/Documents/report.pdf"

        // Construct S3 key: username/files/Documents/report.pdf
        $s3Key = $username . '/' . $cachePath;

        // Construct physical local path
        $dataDir = rtrim($this->config->getSystemValue('datadirectory', '/var/www/nextcloud/data'), '/');
        $localPhysicalPath = $dataDir . '/' . $username . '/' . $cachePath;

        if (!file_exists($localPhysicalPath)) {
            $this->logger->debug("S3ShadowMigrator: file ID {$fileId} not found at expected path '{$localPhysicalPath}'. Skipping.", ['app' => 's3shadowmigrator']);
            return false;
        }

        if (!is_readable($localPhysicalPath)) {
            $this->logger->warning("S3ShadowMigrator: file ID {$fileId} is not readable at '{$localPhysicalPath}'. Skipping.", ['app' => 's3shadowmigrator']);
            return false;
        }

        // Try to acquire an exclusive lock to prevent concurrent access
        $nodes = $this->rootFolder->getById($fileId);
        $node = !empty($nodes) ? $nodes[0] : null;
        $locked = false;

        if ($node instanceof File) {
            try {
                $node->lock(ILockingProvider::LOCK_EXCLUSIVE);
                $locked = true;
            } catch (\OCP\Lock\LockedException $e) {
                $this->logger->info("S3ShadowMigrator: file ID {$fileId} is locked (in use). Skipping.", ['app' => 's3shadowmigrator']);
                return false;
            }
        }

        try {
            $fileSize   = filesize($localPhysicalPath);
            $bucketName = $this->config->getAppValue('s3shadowmigrator', 's3_bucket_name', '');

            if (empty($bucketName)) {
                $this->logger->error('S3ShadowMigrator: bucket name is not configured.', ['app' => 's3shadowmigrator']);
                return false;
            }

            $s3 = $this->getS3Client();

            // Use multipart upload for large files
            if ($fileSize >= self::MULTIPART_THRESHOLD_BYTES) {
                $this->logger->info("S3ShadowMigrator: using multipart upload for file ID {$fileId} ({$fileSize} bytes).", ['app' => 's3shadowmigrator']);
                $uploader = new MultipartUploader($s3, $localPhysicalPath, [
                    'bucket' => $bucketName,
                    'key'    => $s3Key,
                ]);
                $uploader->upload();
            } else {
                $localHash = md5_file($localPhysicalPath);
                $s3->putObject([
                    'Bucket'     => $bucketName,
                    'Key'        => $s3Key,
                    'SourceFile' => $localPhysicalPath,
                    'ContentMD5' => base64_encode(pack('H*', $localHash)),
                ]);
            }

            // Update database pointer to S3 storage
            $dbSuccess = $this->fileCacheUpdater->migrateFileRecord($fileId, $s3StorageId, $s3Key);

            if ($dbSuccess) {
                if (!unlink($localPhysicalPath)) {
                    $this->logger->warning("S3ShadowMigrator: file ID {$fileId} uploaded and DB updated but local deletion failed: {$localPhysicalPath}", ['app' => 's3shadowmigrator']);
                } else {
                    $this->logger->info("S3ShadowMigrator: migrated file ID {$fileId} → s3://{$bucketName}/{$s3Key}", ['app' => 's3shadowmigrator']);
                }
                return true;
            } else {
                // DB failed — roll back S3 upload to prevent orphan objects
                $this->logger->error("S3ShadowMigrator: DB update failed for file ID {$fileId}. Rolling back S3 object.", ['app' => 's3shadowmigrator']);
                try {
                    $s3->deleteObject(['Bucket' => $bucketName, 'Key' => $s3Key]);
                } catch (\Exception $cleanupError) {
                    $this->logger->warning("S3ShadowMigrator: failed to clean up orphaned S3 object '{$s3Key}': " . $cleanupError->getMessage(), ['app' => 's3shadowmigrator']);
                }
                return false;
            }

        } catch (MultipartUploadException $e) {
            $this->logger->error("S3ShadowMigrator: multipart upload failed for file ID {$fileId}: " . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return false;
        } catch (S3Exception $e) {
            $this->logger->error("S3ShadowMigrator: S3 upload failed for file ID {$fileId}: " . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return false;
        } catch (\Exception $e) {
            $this->logger->error("S3ShadowMigrator: unexpected error for file ID {$fileId}: " . $e->getMessage(), [
                'app'       => 's3shadowmigrator',
                'exception' => $e,
            ]);
            return false;
        } finally {
            if ($locked && $node instanceof File) {
                try {
                    $node->unlock(ILockingProvider::LOCK_EXCLUSIVE);
                } catch (\Exception $e) {
                    $this->logger->warning("S3ShadowMigrator: failed to unlock file ID {$fileId}.", ['app' => 's3shadowmigrator']);
                }
            }
        }
    }

    /**
     * Migrate a single specific file by ID. Used by CLI command and web controller.
     */
    public function migrateFileById(int $fileId, int $s3StorageId): bool {
        // Fetch the specific file record
        $query = $this->db->getQueryBuilder();
        $query->select('fc.fileid', 'fc.path', 'fc.size', 'fc.storage', 's.id AS storage_id_string')
              ->from('filecache', 'fc')
              ->innerJoin('fc', 'storages', 's', $query->expr()->eq('fc.storage', 's.numeric_id'))
              ->where($query->expr()->eq('fc.fileid', $query->createNamedParameter($fileId)));

        $fileRecord = $query->executeQuery()->fetch();

        if (!$fileRecord) {
            $this->logger->error("S3ShadowMigrator: file ID {$fileId} not found in oc_filecache.", ['app' => 's3shadowmigrator']);
            return false;
        }

        if ((int)$fileRecord['storage'] === $s3StorageId) {
            $this->logger->info("S3ShadowMigrator: file ID {$fileId} is already on S3.", ['app' => 's3shadowmigrator']);
            return true;
        }

        $username = $this->extractUsernameFromStorageId($fileRecord['storage_id_string']);
        if ($username === null) {
            $this->logger->error("S3ShadowMigrator: storage '{$fileRecord['storage_id_string']}' is not a local home storage. Cannot migrate.", ['app' => 's3shadowmigrator']);
            return false;
        }

        return $this->migrateFileRecord($fileRecord, $username, $s3StorageId);
    }
}
