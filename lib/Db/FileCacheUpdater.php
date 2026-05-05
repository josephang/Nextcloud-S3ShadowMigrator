<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Db;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class FileCacheUpdater {
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(IDBConnection $db, LoggerInterface $logger) {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Resolves the target S3 storage ID by querying oc_storages.
     *
     * @param string $bucketIdentifier The string identifier (e.g. s3::bucket-name)
     * @return int|null The numeric storage ID or null if not found
     */
    public function getS3StorageId(string $bucketIdentifier): ?int {
        $query = $this->db->getQueryBuilder();
        $query->select('numeric_id')
              ->from('storages')
              ->where($query->expr()->eq('id', $query->createNamedParameter($bucketIdentifier)));

        $result = $query->executeQuery();
        $row = $result->fetch();
        if ($row && isset($row['numeric_id'])) {
            return (int)$row['numeric_id'];
        }

        return null;
    }

    /**
     * Executes transactional UPDATE on the PostgreSQL oc_filecache table.
     * Swaps the storage ID integer and updates the path/path_hash to point to the remote S3 object.
     *
     * @param int $fileId The file cache ID
     * @param int $newStorageId The S3 numeric storage ID
     * @param string $newPath The target S3 path/key
     * @return bool True on success
     */
    public function migrateFileRecord(int $fileId, int $newStorageId, string $newPath): bool {
        $this->db->beginTransaction();
        try {
            $query = $this->db->getQueryBuilder();
            $query->update('filecache')
                  ->set('storage', $query->createNamedParameter($newStorageId))
                  ->set('path', $query->createNamedParameter($newPath))
                  ->set('path_hash', $query->createNamedParameter(md5($newPath)))
                  ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)));

            $affected = $query->executeStatement();
            
            $this->db->commit();
            return $affected > 0;
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Failed to update file cache during shadow migration: ' . $e->getMessage(), [
                'app' => 's3shadowmigrator',
                'exception' => $e
            ]);
            return false;
        }
    }
}
