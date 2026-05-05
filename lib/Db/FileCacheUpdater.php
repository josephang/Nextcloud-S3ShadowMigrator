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
     * Executes transactional UPSERT on the PostgreSQL oc_s3shadow_files table
     * to mark a file as migrated (sparse) with its current ETag and S3 Key.
     *
     * @param int $fileId The file cache ID
     * @param string $etag The file's ETag
     * @param string $s3Key The file's destination key in S3
     * @return bool True on success
     */
    public function markFileAsSparse(int $fileId, string $etag, string $s3Key, bool $isVault = false): bool {
        $this->db->beginTransaction();
        try {
            // Check if exists first to do an UPSERT
            $query = $this->db->getQueryBuilder();
            $query->select('fileid')->from('s3shadow_files')->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)));
            $exists = $query->executeQuery()->fetch();

            $query = $this->db->getQueryBuilder();
            if ($exists) {
                $query->update('s3shadow_files')
                      ->set('migrated_at', $query->createNamedParameter(date('Y-m-d H:i:s')))
                      ->set('migrated_etag', $query->createNamedParameter($etag))
                      ->set('s3_key', $query->createNamedParameter($s3Key))
                      ->set('is_vault', $query->createNamedParameter($isVault, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))
                      ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)));
            } else {
                $query->insert('s3shadow_files')
                      ->values([
                          'fileid' => $query->createNamedParameter($fileId),
                          'migrated_at' => $query->createNamedParameter(date('Y-m-d H:i:s')),
                          'migrated_etag' => $query->createNamedParameter($etag),
                          's3_key' => $query->createNamedParameter($s3Key),
                          'is_vault' => $query->createNamedParameter($isVault, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)
                      ]);
            }

            $affected = $query->executeStatement();
            
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Failed to mark file as sparse during shadow migration: ' . $e->getMessage(), [
                'app' => 's3shadowmigrator',
                'exception' => $e
            ]);
            return false;
        }
    }

    /**
     * Checks if a file is marked as sparse in the custom tracking table,
     * AND verifies that the ETag hasn't changed (which would mean it was overwritten locally).
     *
     * @param int $fileId The file cache ID
     * @param string $currentEtag The current ETag from oc_filecache
     * @return bool True if the file is sparse AND ETags match
     */
    public function isFileSparse(int $fileId, string $currentEtag = ''): bool {
        try {
            $query = $this->db->getQueryBuilder();
            $query->select('migrated_etag')
                  ->from('s3shadow_files')
                  ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)));

            $result = $query->executeQuery();
            $row = $result->fetch();
            
            if ($row === false) {
                return false;
            }

            // If an ETag is provided, ensure it matches
            if ($currentEtag !== '' && isset($row['migrated_etag']) && $row['migrated_etag'] !== $currentEtag) {
                // The file was overwritten locally. It is no longer a valid sparse file.
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to check if file is sparse: ' . $e->getMessage(), [
                'app' => 's3shadowmigrator',
                'exception' => $e
            ]);
            return false;
        }
    }

    /**
     * Checks if a file is marked as sparse and if it is encrypted in the vault.
     *
     * @param int $fileId The file cache ID
     * @param string $currentEtag The current ETag from oc_filecache
     * @return array|null Returns ['is_sparse' => bool, 'is_vault' => bool] or null if not sparse
     */
    public function getFileSparseStatus(int $fileId, string $currentEtag = ''): ?array {
        try {
            $query = $this->db->getQueryBuilder();
            $query->select('migrated_etag', 'is_vault')
                  ->from('s3shadow_files')
                  ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)));

            $result = $query->executeQuery();
            $row = $result->fetch();
            
            if ($row === false) {
                return null;
            }

            if ($currentEtag !== '' && isset($row['migrated_etag']) && $row['migrated_etag'] !== $currentEtag) {
                return null;
            }

            return [
                'is_sparse' => true,
                'is_vault' => (bool)$row['is_vault']
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get sparse status: ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return null;
        }
    }
}
