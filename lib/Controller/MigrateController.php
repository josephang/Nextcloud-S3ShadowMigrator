<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCA\S3ShadowMigrator\Service\S3MigrationService;
use OCA\S3ShadowMigrator\Db\FileCacheUpdater;
use OCP\IConfig;
use OCP\IDBConnection;

class MigrateController extends Controller {
    private S3MigrationService $migrationService;
    private FileCacheUpdater $fileCacheUpdater;
    private IConfig $config;
    private IDBConnection $db;

    public function __construct(
        string $AppName,
        IRequest $request,
        S3MigrationService $migrationService,
        FileCacheUpdater $fileCacheUpdater,
        IConfig $config,
        IDBConnection $db
    ) {
        parent::__construct($AppName, $request);
        $this->migrationService = $migrationService;
        $this->fileCacheUpdater = $fileCacheUpdater;
        $this->config = $config;
        $this->db = $db;
    }

    /**
     * @NoAdminRequired
     */
    public function migrateFile(int $fileId): DataResponse {
        if ($fileId <= 0) {
            return new DataResponse(['status' => 'error', 'message' => 'Invalid file ID.'], 400);
        }

        $query = $this->db->getQueryBuilder();
        $query->select('*')
              ->from('filecache')
              ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)));

        $fileRecord = $query->executeQuery()->fetch();

        if (!$fileRecord) {
            return new DataResponse(['status' => 'error', 'message' => 'File not found in cache.'], 404);
        }

        $s3BucketIdentifier = $this->config->getAppValue('s3shadowmigrator', 's3_bucket_identifier', '');
        if (empty($s3BucketIdentifier)) {
            return new DataResponse(['status' => 'error', 'message' => 'S3 Bucket identifier not configured.'], 500);
        }

        $s3StorageId = $this->fileCacheUpdater->getS3StorageId($s3BucketIdentifier);
        if ($s3StorageId === null) {
            return new DataResponse(['status' => 'error', 'message' => 'S3 Storage not found in database. Is the external storage mounted?'], 500);
        }

        // BUG FIX: Check if already on S3 to avoid a pointless double-migration attempt
        if ((int)$fileRecord['storage'] === $s3StorageId) {
            return new DataResponse(['status' => 'success', 'message' => 'File is already on S3.']);
        }

        $success = $this->migrationService->migrateFile($fileId, $fileRecord['path'], $fileRecord, $s3StorageId);

        if ($success) {
            return new DataResponse(['status' => 'success']);
        }
        return new DataResponse(['status' => 'error', 'message' => 'Migration failed. File may be locked or inaccessible. Check nextcloud.log.'], 400);
    }
}
