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
                      ->set('status', $query->createNamedParameter('active'))
                      ->set('is_vault', $query->createNamedParameter($isVault, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))
                      ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)));
            } else {
                $query->insert('s3shadow_files')
                      ->values([
                          'fileid' => $query->createNamedParameter($fileId),
                          'migrated_at' => $query->createNamedParameter(date('Y-m-d H:i:s')),
                          'migrated_etag' => $query->createNamedParameter($etag),
                          's3_key' => $query->createNamedParameter($s3Key),
                          'status' => $query->createNamedParameter('active'),
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
     * Marks a file as currently in-progress upload so the SelfHealer won't touch it.
     * Creates the row if it doesn't exist yet (pre-upload reservation).
     */
    public function markFileAsUploading(int $fileId, string $s3Key): void {
        try {
            $query = $this->db->getQueryBuilder();
            $query->select('fileid')->from('s3shadow_files')->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)));
            $exists = $query->executeQuery()->fetch();

            $query = $this->db->getQueryBuilder();
            if ($exists) {
                $query->update('s3shadow_files')
                      ->set('status', $query->createNamedParameter('uploading'))
                      ->set('s3_key', $query->createNamedParameter($s3Key))
                      ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)))
                      ->executeStatement();
            } else {
                $query->insert('s3shadow_files')
                      ->values([
                          'fileid'       => $query->createNamedParameter($fileId),
                          'migrated_at'  => $query->createNamedParameter(date('Y-m-d H:i:s')),
                          'migrated_etag'=> $query->createNamedParameter(''),
                          's3_key'       => $query->createNamedParameter($s3Key),
                          'status'       => $query->createNamedParameter('uploading'),
                      ])
                      ->executeStatement();
            }
        } catch (\Exception $e) {
            $this->logger->warning('FileCacheUpdater: markFileAsUploading failed for ID ' . $fileId . ': ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
        }
    }

    /**
     * Reverts an 'uploading' status back to nothing (removes the row).
     * Called on upload failure so the file can be retried cleanly.
     */
    public function unmarkFileAsUploading(int $fileId): void {
        try {
            $query = $this->db->getQueryBuilder();
            $query->delete('s3shadow_files')
                  ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)))
                  ->andWhere($query->expr()->eq('status', $query->createNamedParameter('uploading')))
                  ->executeStatement();
        } catch (\Exception $e) {
            $this->logger->warning('FileCacheUpdater: unmarkFileAsUploading failed for ID ' . $fileId . ': ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
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
            $query->select('migrated_etag', 'is_vault', 'status')
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

            $status = $row['status'] ?? 'active';

            return [
                'is_sparse' => ($status !== 'uploading'), // uploading rows are not yet sparse
                'is_vault'  => (bool)$row['is_vault'],
                'status'    => $status,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get sparse status: ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return null;
        }
    }

    /**
     * Updates the status and healed_at columns on a tracked file record.
     * Requires the DB migration Version1000000Date20260505000000 to have run.
     *
     * @param int    $fileId   The file cache ID
     * @param string $status   'active' | 'lost' | 'corrupt' | 'orphan'
     * @param string $healedAt ISO datetime of the healing action
     */
    public function updateFileStatus(int $fileId, string $status, string $healedAt): bool {
        try {
            $query = $this->db->getQueryBuilder();
            $query->update('s3shadow_files')
                  ->set('status',    $query->createNamedParameter($status))
                  ->set('healed_at', $query->createNamedParameter($healedAt))
                  ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)));
            $query->executeStatement();
            return true;
        } catch (\Exception $e) {
            // Column may not exist yet if migration hasn't run — log warning, don't crash
            $this->logger->warning('SelfHealer: updateFileStatus failed for ID ' . $fileId . ': ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return false;
        }
    }

    /**
     * Removes the sparse tracking row for a given file ID.
     * Used for Corrupt-C (local intact, S3 missing → re-queue migration) and
     * Orphan-DB (file deleted by user → stale DB row cleanup).
     *
     * @param int $fileId The file cache ID
     */
    public function removeSparseMark(int $fileId): bool {
        try {
            $query = $this->db->getQueryBuilder();
            $query->delete('s3shadow_files')
                  ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)));
            $query->executeStatement();
            return true;
        } catch (\Exception $e) {
            $this->logger->error('SelfHealer: removeSparseMark failed for ID ' . $fileId . ': ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return false;
        }
    }
    /**
     * Repairs the ETag in oc_filecache for a sparse file.
     * This is used when Nextcloud's background scanner incorrectly hashes a sparse stub's null bytes.
     * 
     * @param int $fileId The file cache ID
     * @param string $correctEtag The original correct ETag (from migrated_etag)
     */
    public function repairEtag(int $fileId, string $correctEtag): bool {
        try {
            $query = $this->db->getQueryBuilder();
            $query->update('filecache')
                  ->set('etag', $query->createNamedParameter($correctEtag))
                  ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)));
            $query->executeStatement();
            return true;
        } catch (\Exception $e) {
            $this->logger->error('SelfHealer: repairEtag failed for ID ' . $fileId . ': ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return false;
        }
    }
}
