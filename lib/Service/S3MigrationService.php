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

class S3MigrationService {
    private FileCacheUpdater $fileCacheUpdater;
    private IRootFolder $rootFolder;
    private IConfig $config;
    private IDBConnection $db;
    private LoggerInterface $logger;
    private ?S3Client $s3Client = null;

    // BUG FIX: Files larger than this threshold use multipart upload (100 MB).
    // putObject() silently fails or times out on large files.
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

            // BUG FIX: Guard against empty credentials which causes the AWS SDK to hang
            // trying to fetch instance metadata from EC2 IMDS endpoint (169.254.169.254)
            // for up to 30 seconds before throwing a misleading error.
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
        $this->logger->info('S3ShadowMigrator batch: found ' . count($filesToMigrate) . ' files to migrate.', ['app' => 's3shadowmigrator']);

        foreach ($filesToMigrate as $fileRecord) {
            $this->migrateFile((int)$fileRecord['fileid'], $fileRecord['path'], $fileRecord, $s3StorageId);
        }
    }

    /**
     * Finds local files to migrate, excluding appdata, thumbnails, and directories.
     */
    private function getFilesToMigrate(int $limit, int $s3StorageId): array {
        // BUG FIX: The old query used `mimetype = 2` to exclude folders, but oc_filecache stores
        // a foreign key into oc_mimetypes, not a raw mimetype string. The integer 2 is NOT
        // reliably the directory mimetype. The correct way to exclude directories is to
        // filter on `mimepart != (SELECT id FROM oc_mimetypes WHERE mimetype = 'httpd/unix-directory')`.
        // Simpler and more reliable: filter on size > 0 AND the path not ending in '/', which
        // is how Nextcloud marks directories internally.
        $query = $this->db->getQueryBuilder();
        $query->select('fc.*')
              ->from('filecache', 'fc')
              ->where($query->expr()->neq('fc.storage', $query->createNamedParameter($s3StorageId)))
              ->andWhere($query->expr()->gt('fc.size', $query->createNamedParameter(0)))
              ->andWhere($query->expr()->notLike('fc.path', $query->createNamedParameter('appdata_%')))
              ->andWhere($query->expr()->notLike('fc.path', $query->createNamedParameter('%.jpg.ocTransferId%'))) // skip partial uploads
              ->andWhere($query->expr()->notLike('fc.path', $query->createNamedParameter('%/.ocdata')))
              // BUG FIX: Exclude directories by filtering out paths that resolve to mimetype 'httpd/unix-directory'
              ->innerJoin('fc', 'mimetypes', 'mt', $query->expr()->eq('fc.mimetype', 'mt.id'))
              ->andWhere($query->expr()->neq('mt.mimetype', $query->createNamedParameter('httpd/unix-directory')))
              ->setMaxResults($limit);

        return $query->executeQuery()->fetchAll();
    }

    public function migrateFile(int $fileId, string $internalPath, array $fileRecord, int $s3StorageId): bool {
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

        $localPhysicalPath = null;
        $s3Key = null;
        $uploadedToS3 = false;

        try {
            // BUG FIX: The internal path from oc_filecache is RELATIVE to the storage root (e.g. "files/photo.jpg").
            // The physical path requires the user's data directory prefix. We must resolve it via the Node API
            // to handle all edge cases (including encrypted storage, mounted external storage, etc.)
            $localPhysicalPath = $node->getStorage()->getLocalFile($node->getInternalPath());

            if ($localPhysicalPath === null || !file_exists($localPhysicalPath)) {
                $this->logger->info("File ID {$fileId}: local physical path not resolvable or missing. Skipping.", ['app' => 's3shadowmigrator']);
                return false;
            }

            if (!is_writable($localPhysicalPath)) {
                $this->logger->info("File ID {$fileId}: file is not writable, cannot safely delete after upload. Skipping.", ['app' => 's3shadowmigrator']);
                return false;
            }

            $fileSize = filesize($localPhysicalPath);
            // BUG FIX: md5_file on a very large file (multi-GB) can cause a PHP memory exhaustion.
            // We only compute the hash for files below the multipart threshold.
            $localHash = ($fileSize < self::MULTIPART_THRESHOLD_BYTES) ? md5_file($localPhysicalPath) : null;

            $bucketName = $this->config->getAppValue('s3shadowmigrator', 's3_bucket_name', '');
            if (empty($bucketName)) {
                $this->logger->error("S3 bucket name is not configured.", ['app' => 's3shadowmigrator']);
                return false;
            }

            // Use the node's internal path as the S3 key (relative to its storage root)
            $s3Key = $node->getInternalPath();
            $s3 = $this->getS3Client();

            // BUG FIX: Use multipart upload for large files. putObject() will silently hang or
            // timeout for files larger than ~100MB, leaving no error in the logs.
            if ($fileSize >= self::MULTIPART_THRESHOLD_BYTES) {
                $this->logger->info("File ID {$fileId} is large ({$fileSize} bytes), using multipart upload.", ['app' => 's3shadowmigrator']);
                $uploader = new MultipartUploader($s3, $localPhysicalPath, [
                    'bucket' => $bucketName,
                    'key'    => $s3Key,
                ]);
                $uploader->upload();
            } else {
                $putParams = [
                    'Bucket'     => $bucketName,
                    'Key'        => $s3Key,
                    'SourceFile' => $localPhysicalPath,
                ];
                if ($localHash !== null) {
                    $putParams['ContentMD5'] = base64_encode(pack('H*', $localHash));
                }
                $s3->putObject($putParams);
            }

            $uploadedToS3 = true;

            // Surgically update DB pointer in a transaction
            $dbSuccess = $this->fileCacheUpdater->migrateFileRecord($fileId, $s3StorageId, $s3Key);

            if ($dbSuccess) {
                // BUG FIX: unlink() can silently fail on NFS/network mounts. We check the return value
                // and log the failure. The DB is already updated, so we don't roll back - the file
                // will just exist in both places (harmless duplicate, not a data loss scenario).
                if (!unlink($localPhysicalPath)) {
                    $this->logger->warning("File ID {$fileId}: uploaded to S3 and DB updated, but local file deletion failed. Manual cleanup required: {$localPhysicalPath}", ['app' => 's3shadowmigrator']);
                } else {
                    $this->logger->info("Successfully migrated File ID {$fileId} to S3.", ['app' => 's3shadowmigrator']);
                }
                return true;
            } else {
                // DB update failed (transaction rolled back). Delete orphaned S3 object.
                $this->logger->error("File ID {$fileId}: DB update failed. Rolling back S3 upload.", ['app' => 's3shadowmigrator']);
                $s3->deleteObject(['Bucket' => $bucketName, 'Key' => $s3Key]);
                return false;
            }

        } catch (S3Exception $e) {
            $this->logger->error("S3 Upload failed for File ID {$fileId}: " . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return false;
        } catch (\Exception $e) {
            $this->logger->error("Unexpected error migrating File ID {$fileId}: " . $e->getMessage(), [
                'app'       => 's3shadowmigrator',
                'exception' => $e,
            ]);
            return false;
        } finally {
            // BUG FIX: The original code only unlocked in happy-path branches.
            // If an exception was thrown, the file would remain EXCLUSIVELY locked forever,
            // causing all subsequent access to that file to fail with LockedException.
            // The lock MUST be released in a finally block.
            try {
                $node->unlock(ILockingProvider::LOCK_EXCLUSIVE);
            } catch (\Exception $unlockError) {
                $this->logger->warning("Failed to unlock File ID {$fileId}: " . $unlockError->getMessage(), ['app' => 's3shadowmigrator']);
            }
        }
    }
}
