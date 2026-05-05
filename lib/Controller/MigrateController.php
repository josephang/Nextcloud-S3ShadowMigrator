<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCA\S3ShadowMigrator\Service\S3MigrationService;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;

class MigrateController extends Controller {
    private S3MigrationService $migrationService;
    private IRootFolder $rootFolder;
    private IDBConnection $db;

    public function __construct(
        string $AppName,
        IRequest $request,
        S3MigrationService $migrationService,
        IRootFolder $rootFolder,
        IDBConnection $db
    ) {
        parent::__construct($AppName, $request);
        $this->migrationService = $migrationService;
        $this->rootFolder = $rootFolder;
        $this->db = $db;
    }

    /**
     * @NoAdminRequired
     */
    public function migrateFile(int $fileId): DataResponse {
        // Need to fetch file record from DB to pass to migration service
        $query = $this->db->getQueryBuilder();
        $query->select('*')
              ->from('filecache')
              ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)));

        $fileRecord = $query->executeQuery()->fetch();

        if (!$fileRecord) {
            return new DataResponse(['status' => 'error', 'message' => 'File not found in cache.'], 404);
        }

        // Get target S3 storage ID
        $s3BucketIdentifier = \OC::$server->getConfig()->getAppValue('s3shadowmigrator', 's3_bucket_identifier', '');
        if (empty($s3BucketIdentifier)) {
            return new DataResponse(['status' => 'error', 'message' => 'S3 Bucket identifier not configured.'], 500);
        }

        $s3StorageId = \OC::$server->get( \OCA\S3ShadowMigrator\Db\FileCacheUpdater::class )->getS3StorageId($s3BucketIdentifier);

        if ($s3StorageId === null) {
            return new DataResponse(['status' => 'error', 'message' => 'S3 Storage ID not found.'], 500);
        }

        $success = $this->migrationService->migrateFile($fileId, $fileRecord['path'], clone $fileRecord, $s3StorageId);

        if ($success) {
            return new DataResponse(['status' => 'success']);
        } else {
            return new DataResponse(['status' => 'error', 'message' => 'Migration failed. File might be locked or already migrated.'], 400);
        }
    }
}
