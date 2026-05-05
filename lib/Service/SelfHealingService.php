<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Service;

use OCA\S3ShadowMigrator\Db\FileCacheUpdater;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Aws\S3\Exception\S3Exception;

/**
 * Self-Healing Service: audits all DB-tracked sparse files and the S3 bucket itself,
 * detecting and correcting 5 categories of corruption without destroying any data.
 *
 * Corruption States:
 *   Healthy    — local=0b,  DB=tracked, S3=exists    → no-op
 *   Corrupt-A  — local>0b,  DB=tracked, S3=exists    → re-truncate local to 0 (old ftruncate bug)
 *   Corrupt-B  — local=0b,  DB=tracked, S3=MISSING   → CRITICAL DATA LOSS: mark status='lost', alert
 *   Corrupt-C  — local>0b,  DB=tracked, S3=MISSING   → remove sparse mark, re-queue migration
 *   Orphan-DB  — local=GONE, DB=tracked               → user deleted file, clean up stale DB row
 *   Orphan-S3  — S3 object has NO DB tracking record → log warning (don't auto-delete)
 *   Zero-S3    — S3 object is 0 bytes (bad upload)   → log warning for admin
 */
class SelfHealingService {
    private IConfig $config;
    private IDBConnection $db;
    private LoggerInterface $logger;
    private FileCacheUpdater $fileCacheUpdater;

    public function __construct(
        IConfig $config,
        IDBConnection $db,
        LoggerInterface $logger,
        FileCacheUpdater $fileCacheUpdater
    ) {
        $this->config = $config;
        $this->db = $db;
        $this->logger = $logger;
        $this->fileCacheUpdater = $fileCacheUpdater;
    }

    // -------------------------------------------------------------------------
    // Public Entry Point
    // -------------------------------------------------------------------------

    /**
     * Run a single batch of the self-healing audit.
     * Checks the current phase (DB or S3) from config and runs a chunk.
     * Returns the number of items processed.
     */
    public function runAuditBatch(): int {
        $phase = $this->config->getAppValue('s3shadowmigrator', 'self_heal_phase', 'db_audit');
        
        if ($phase === 'db_audit') {
            return $this->auditDbTrackedFilesChunk();
        } elseif ($phase === 's3_audit') {
            return $this->scanBucketForOrphansChunk();
        } else {
            $this->config->setAppValue('s3shadowmigrator', 'self_heal_phase', 'db_audit');
            return 0;
        }
    }

    // -------------------------------------------------------------------------
    // Phase 1: DB-Tracked File Audit
    // -------------------------------------------------------------------------

    private function auditDbTrackedFilesChunk(): int {
        $lastId = (int)$this->config->getAppValue('s3shadowmigrator', 'self_heal_last_fileid', '0');
        $dataDir = rtrim($this->config->getSystemValue('datadirectory', '/var/www/nextcloud/data'), '/');

        // Join with filecache + storages so we can reconstruct the physical path
        $query = $this->db->getQueryBuilder();
        $query->select(
                'sf.fileid', 'sf.s3_key', 'sf.is_vault', 'sf.migrated_etag',
                'fc.path', 'fc.etag',
                's.id AS storage_id'
              )
              ->from('s3shadow_files', 'sf')
              ->leftJoin('sf', 'filecache',  'fc', $query->expr()->eq('sf.fileid', 'fc.fileid'))
              ->leftJoin('fc', 'storages',   's',  $query->expr()->eq('fc.storage', 's.numeric_id'))
              ->where($query->expr()->gt('sf.fileid', $query->createNamedParameter($lastId)))
              ->orderBy('sf.fileid', 'ASC')
              ->setMaxResults(500);

        $rows = $query->executeQuery()->fetchAll();

        if (empty($rows)) {
            $this->writeLiveLog('=== DB Audit Complete. Switching to S3 Bucket Scan ===');
            $this->config->setAppValue('s3shadowmigrator', 'self_heal_last_fileid', '0');
            $this->config->setAppValue('s3shadowmigrator', 'self_heal_phase', 's3_audit');
            return 0;
        }

        $highestId = $lastId;
        foreach ($rows as $row) {
            $highestId = (int)$row['fileid'];
            try {
                $this->auditSingleFile($row, $dataDir);
            } catch (\Exception $e) {
                $this->writeLiveLog('⚠ Error auditing file ID ' . $row['fileid'] . ': ' . $e->getMessage());
                $this->logger->error('SelfHealer: unexpected error auditing file ID ' . $row['fileid'] . ': ' . $e->getMessage(), [
                    'app' => 's3shadowmigrator', 'exception' => $e,
                ]);
            }
        }
        
        $this->config->setAppValue('s3shadowmigrator', 'self_heal_last_fileid', (string)$highestId);
        return count($rows);
    }

    /**
     * Audits a single DB-tracked file, classifies its corruption state, and acts.
     *
     * @return string One of: 'healthy'|'fixed_a'|'fixed_c'|'critical'|'orphan_db'
     */
    private function auditSingleFile(array $row, string $dataDir): string {
        $fileId    = (int)$row['fileid'];
        $s3Key     = (string)$row['s3_key'];
        $storageId = (string)($row['storage_id'] ?? '');
        $now       = date('Y-m-d H:i:s');

        // Reconstruct local physical path from storage ID and filecache path
        $username  = str_starts_with($storageId, 'home::') ? substr($storageId, 6) : null;
        $localPath = ($username !== null && !empty($row['path']))
            ? $dataDir . '/' . $username . '/' . $row['path']
            : null;

        $localExists = $localPath !== null && file_exists($localPath);
        $localSize   = $localExists ? (int)filesize($localPath) : -1;

        // Lightweight S3 existence check — HeadObject only, no download
        $s3Exists = $this->verifyS3Object($s3Key);

        // -------------------------------------------------------------------
        // State Classification
        // -------------------------------------------------------------------

        $etagMatches = isset($row['etag']) && ((string)$row['migrated_etag'] === (string)$row['etag']);

        $isMirrorMode = false;
        if (!empty($row['path'])) {
            $mirrorPathsStr = $this->config->getAppValue('s3shadowmigrator', 'mirror_paths', 'Notes/');
            $mirrorPaths = array_filter(array_map('trim', explode(',', $mirrorPathsStr)));
            foreach ($mirrorPaths as $mp) {
                if (!empty($mp) && str_contains($row['path'], $mp)) {
                    $isMirrorMode = true;
                    break;
                }
            }
        }

        // Healthy: local is properly zeroed, S3 has the object, and ETags match
        // Or if in Mirror Mode, local is full size, S3 has the object, and ETags match
        if ($localExists && $s3Exists && $etagMatches) {
            if ($isMirrorMode && $localSize === 0) {
                // Mirror-Hydrate: Path is mirrored, but file was already truncated! Download it.
                $this->writeLiveLog(sprintf('💧 Mirror-Hydrate [ID %d]: %s was truncated previously. Restoring from S3...', $fileId, $row['path']));
                try {
                    if ($this->hydrateFromS3($s3Key, $localPath)) {
                        $this->writeLiveLog(sprintf('✓ [Hydrated] Successfully restored %s to local disk.', $row['path']));
                        $this->logger->info(sprintf('S3ShadowMigrator SelfHealer: hydrated mirrored file ID %d (%s) from S3.', $fileId, $row['path']), ['app' => 's3shadowmigrator']);
                    } else {
                        $this->writeLiveLog(sprintf('⚠ [Hydration Failed] Could not restore %s.', $row['path']));
                    }
                } catch (\Exception $e) {
                    $this->writeLiveLog(sprintf('⚠ [Hydration Error] %s: %s', $row['path'], $e->getMessage()));
                }
                return 'healthy'; // Treat as healthy so it doesn't count as an error
            } elseif ($localSize === 0) {
                return 'healthy';
            } elseif ($isMirrorMode && $localSize > 0) {
                return 'healthy';
            }
        }

        // Modified by user: file has new content and new ETag. Leave it alone for Migrator to handle.
        if ($localExists && $localSize > 0 && !$etagMatches) {
            $this->writeLiveLog(sprintf('📝 Info [ID %d]: file modified locally (ETag changed). Skipping so migrator can upload new version.', $fileId));
            return 'healthy';
        }

        // Corrupt-A: local still has content (null bytes from old ftruncate bug), S3 is fine, AND ETags match (file was not modified)
        if ($localExists && $localSize > 0 && $s3Exists && $etagMatches) {
            // Ultimate safety net: if the file was modified on disk in the last 5 minutes, leave it alone.
            // This prevents race conditions if a user is actively saving a file and the DB hasn't committed the new ETag yet.
            clearstatcache(true, $localPath);
            $mtime = @filemtime($localPath);
            if ($mtime !== false && $mtime > time() - 300) {
                $this->writeLiveLog(sprintf('⏳ Info [ID %d]: file modified very recently. Delaying Corrupt-A fix to prevent race conditions.', $fileId));
                return 'healthy';
            }

            $this->writeLiveLog(sprintf('🔧 Corrupt-A [ID %d]: local file is %d bytes (should be 0). Re-truncating.', $fileId, $localSize));
            $f = @fopen($localPath, 'w');
            if ($f) {
                ftruncate($f, 0);
                fclose($f);
            }
            $this->fileCacheUpdater->updateFileStatus($fileId, 'active', $now);
            return 'fixed_a';
        }

        // Corrupt-B: local is 0 bytes but S3 object is MISSING — data loss
        if ($localExists && $localSize === 0 && !$s3Exists) {
            $this->writeLiveLog(sprintf('🚨 CRITICAL [ID %d]: s3_key="%s" local=0b S3=MISSING — POSSIBLE DATA LOSS', $fileId, $s3Key));
            $this->logger->critical(sprintf(
                'S3ShadowMigrator SelfHealer CRITICAL: file ID %d s3_key="%s" — local file is 0 bytes AND S3 object is missing. Data may be lost.',
                $fileId, $s3Key
            ), ['app' => 's3shadowmigrator']);
            $this->fileCacheUpdater->updateFileStatus($fileId, 'lost', $now);
            return 'critical';
        }

        // Corrupt-C: local file has content but S3 upload was never completed — re-queue
        if ($localExists && $localSize > 0 && !$s3Exists) {
            $this->writeLiveLog(sprintf('♻ Corrupt-C [ID %d]: local intact (%d bytes) but S3 object missing. Removing sparse mark to re-queue.', $fileId, $localSize));
            $this->fileCacheUpdater->removeSparseMark($fileId);
            return 'fixed_c';
        }

        // Orphan-DB: local file no longer exists at all (user deleted the file — normal case)
        if (!$localExists && $s3Exists) {
            $this->writeLiveLog(sprintf('🗑 Orphan-DB [ID %d]: local file gone (user deleted?), S3 object exists. Cleaning stale DB row.', $fileId));
            $this->fileCacheUpdater->removeSparseMark($fileId);
            return 'orphan_db';
        }

        // Double-gone: both local and S3 are missing
        if (!$localExists && !$s3Exists) {
            $this->writeLiveLog(sprintf('🚨 CRITICAL [ID %d]: BOTH local file and S3 object are missing. Marking as lost.', $fileId));
            $this->logger->critical(sprintf(
                'S3ShadowMigrator SelfHealer CRITICAL: file ID %d s3_key="%s" — both local file and S3 object are gone.',
                $fileId, $s3Key
            ), ['app' => 's3shadowmigrator']);
            $this->fileCacheUpdater->updateFileStatus($fileId, 'lost', $now);
            return 'critical';
        }

        // Shouldn't be reached, but treat as healthy if classification falls through
        return 'healthy';
    }

    /**
     * Performs a lightweight S3 HeadObject check.
     * Returns true if the object exists, false if 404.
     * Throws on unexpected S3 errors (network, auth, etc).
     */
    private function verifyS3Object(string $s3Key): bool {
        if ($s3Key === '') {
            return false;
        }

        try {
            $s3Config = S3ConfigHelper::getS3Config($this->config, $this->db);
            $s3 = S3ConfigHelper::createS3Client($s3Config);
            $s3->headObject([
                'Bucket' => $s3Config['bucket'],
                'Key'    => $s3Key,
            ]);
            return true;
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return false;
            }
            // Re-throw unexpected errors (auth failure, network, etc) so they're counted as errors
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Phase 2: S3 Bucket Scan
    // -------------------------------------------------------------------------

    /**
     * Lists objects in the configured S3 bucket (paginated in chunks of 1000) and checks:
     *   1. Whether the object has a matching row in oc_s3shadow_files (orphaned if not)
     *   2. Whether the object is 0 bytes (indicates a failed upload)
     */
    private function scanBucketForOrphansChunk(): int {
        $continuationToken = $this->config->getAppValue('s3shadowmigrator', 'self_heal_s3_token', '');
        
        try {
            $s3Config = S3ConfigHelper::getS3Config($this->config, $this->db);
            $s3       = S3ConfigHelper::createS3Client($s3Config);
            $bucket   = $s3Config['bucket'];

            $params = ['Bucket' => $bucket, 'MaxKeys' => 1000];
            if ($continuationToken !== '') {
                $params['ContinuationToken'] = $continuationToken;
            }

            $result  = $s3->listObjectsV2($params);
            $objects = $result['Contents'] ?? [];

            if (empty($objects)) {
                $this->writeLiveLog('=== S3 Bucket Scan Complete. Switching to DB Audit ===');
                $this->config->setAppValue('s3shadowmigrator', 'self_heal_s3_token', '');
                $this->config->setAppValue('s3shadowmigrator', 'self_heal_phase', 'db_audit');
                return 0;
            }

            // To avoid N+1 queries, pull just the keys we need from the DB
            $keysToCheck = [];
            foreach ($objects as $obj) {
                $keysToCheck[] = (string)$obj['Key'];
            }
            
            $dbQuery = $this->db->getQueryBuilder();
            // Use IN array to fetch matched keys efficiently
            $dbQuery->select('s3_key')
                    ->from('s3shadow_files')
                    ->where($dbQuery->expr()->in('s3_key', $dbQuery->createNamedParameter(
                        $keysToCheck,
                        \Doctrine\DBAL\Connection::PARAM_STR_ARRAY
                    )));
            
            $dbRows = $dbQuery->executeQuery()->fetchAll();
            $trackedKeys = array_flip(array_column($dbRows, 's3_key'));

            foreach ($objects as $obj) {
                $key  = (string)$obj['Key'];
                $size = (int)($obj['Size'] ?? -1);

                // Check 1: orphaned (no DB tracking record)
                if (!isset($trackedKeys[$key])) {
                    $this->writeLiveLog(sprintf('⚠ Orphan-S3: "%s" in bucket has no DB tracking record.', $key));
                    $this->logger->warning(sprintf(
                        'S3ShadowMigrator SelfHealer: S3 object "%s" exists in bucket but has no row in oc_s3shadow_files.',
                        $key
                    ), ['app' => 's3shadowmigrator']);
                }

                // Check 2: zero-byte object (upload failed mid-stream)
                if ($size === 0) {
                    $this->writeLiveLog(sprintf('⚠ Zero-S3: "%s" is 0 bytes in the bucket — upload may have failed.', $key));
                    $this->logger->warning(sprintf(
                        'S3ShadowMigrator SelfHealer: S3 object "%s" is 0 bytes — this likely indicates a failed upload.',
                        $key
                    ), ['app' => 's3shadowmigrator']);
                }
            }

            if (!empty($result['IsTruncated']) && !empty($result['NextContinuationToken'])) {
                $this->config->setAppValue('s3shadowmigrator', 'self_heal_s3_token', $result['NextContinuationToken']);
            } else {
                $this->writeLiveLog('=== S3 Bucket Scan Complete. Switching to DB Audit ===');
                $this->config->setAppValue('s3shadowmigrator', 'self_heal_s3_token', '');
                $this->config->setAppValue('s3shadowmigrator', 'self_heal_phase', 'db_audit');
            }

            return count($objects);

        } catch (\Exception $e) {
            $this->writeLiveLog('⚠ Error scanning S3 bucket: ' . $e->getMessage());
            $this->logger->error('S3ShadowMigrator SelfHealer: S3 bucket scan error: ' . $e->getMessage(), ['app' => 's3shadowmigrator', 'exception' => $e]);
            // Clear token to prevent infinite stuck loops if a specific chunk throws
            $this->config->setAppValue('s3shadowmigrator', 'self_heal_s3_token', '');
            $this->config->setAppValue('s3shadowmigrator', 'self_heal_phase', 'db_audit');
            return 0;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Downloads an object directly from S3 to the specified local path.
     * Used to hydrate files that were previously truncated but are now mirrored.
     */
    private function hydrateFromS3(string $s3Key, string $localPath): bool {
        if ($s3Key === '' || $localPath === '') {
            return false;
        }

        try {
            $s3Config = S3ConfigHelper::getS3Config($this->config, $this->db);
            $s3 = S3ConfigHelper::createS3Client($s3Config);
            
            $s3->getObject([
                'Bucket' => $s3Config['bucket'],
                'Key'    => $s3Key,
                'SaveAs' => $localPath
            ]);
            
            // Clear stat cache so Nextcloud knows the file is full size again
            clearstatcache(true, $localPath);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('S3ShadowMigrator SelfHealer: hydration failed for ' . $s3Key . ': ' . $e->getMessage(), ['app' => 's3shadowmigrator', 'exception' => $e]);
            return false;
        }
    }

    private function writeLiveLog(string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        $cache     = \OC::$server->getMemCacheFactory()->createDistributed('s3shadowmigrator');

        $currentLog = (string)$cache->get('live_log');
        if (strlen($currentLog) > 4000) {
            $currentLog = substr($currentLog, -2000);
            $newlinePos = strpos($currentLog, "\n");
            if ($newlinePos !== false) {
                $currentLog = substr($currentLog, $newlinePos + 1);
            }
        }

        $cache->set('live_log', $currentLog . "[{$timestamp}] {$message}\n", 3600);
    }
}
