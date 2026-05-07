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
    private ?\Aws\S3\S3Client $s3Client = null;
    private array $s3Config = [];

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
     *
     * Uses a PostgreSQL/MySQL advisory lock (lock ID 0x53334865616C) so that if
     * two healing jobs are scheduled concurrently (e.g. after a cron backlog),
     * the second will skip immediately rather than processing the same files twice
     * or racing on `oc_filecache` UPDATE statements.
     */
    public function runAuditBatch(): int {
        // Attempt to acquire a non-blocking advisory lock.
        // If another instance already holds it, exit immediately.
        if (!$this->acquireAdvisoryLock()) {
            $this->logger->info('S3ShadowMigrator SelfHealer: another instance is already running. Skipping this tick.', ['app' => 's3shadowmigrator']);
            return 0;
        }

        try {
            $phase = $this->config->getAppValue('s3shadowmigrator', 'self_heal_phase', 'db_audit');

            if ($phase === 'db_audit') {
                return $this->auditDbTrackedFilesChunk();
            } elseif ($phase === 's3_audit') {
                return $this->scanBucketForOrphansChunk();
            } else {
                $this->config->setAppValue('s3shadowmigrator', 'self_heal_phase', 'db_audit');
                return 0;
            }
        } finally {
            $this->releaseAdvisoryLock();
        }
    }

    // Lock ID: arbitrary stable integer derived from 'S3Heal' in ASCII hex
    private const ADVISORY_LOCK_ID = 0x5333_4865;

    private function acquireAdvisoryLock(): bool {
        try {
            $platform = $this->db->getDatabasePlatform();
            $platformClass = get_class($platform);

            if (str_contains($platformClass, 'PostgreSQL') || str_contains($platformClass, 'Postgres')) {
                // pg_try_advisory_lock returns true if lock was granted, false if already held
                $result = $this->db->executeQuery('SELECT pg_try_advisory_lock(' . self::ADVISORY_LOCK_ID . ')');
                return (bool)$result->fetchOne();
            } elseif (str_contains($platformClass, 'MySQL') || str_contains($platformClass, 'MariaDB')) {
                // GET_LOCK returns 1 on success, 0 if timeout (using 0-second timeout = non-blocking)
                $result = $this->db->executeQuery("SELECT GET_LOCK('s3shadowmigrator_heal', 0)");
                return (bool)$result->fetchOne();
            } else {
                // SQLite or unknown: no advisory locks — just proceed
                return true;
            }
        } catch (\Exception $e) {
            $this->logger->warning('S3ShadowMigrator SelfHealer: could not acquire advisory lock: ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return true; // Fail open: better to run than to deadlock
        }
    }

    private function releaseAdvisoryLock(): void {
        try {
            $platform = $this->db->getDatabasePlatform();
            $platformClass = get_class($platform);

            if (str_contains($platformClass, 'PostgreSQL') || str_contains($platformClass, 'Postgres')) {
                $this->db->executeQuery('SELECT pg_advisory_unlock(' . self::ADVISORY_LOCK_ID . ')');
            } elseif (str_contains($platformClass, 'MySQL') || str_contains($platformClass, 'MariaDB')) {
                $this->db->executeQuery("SELECT RELEASE_LOCK('s3shadowmigrator_heal')");
            }
        } catch (\Exception $e) {
            // Ignore — connection close will release the lock automatically anyway
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
                'sf.fileid', 'sf.s3_key', 'sf.is_vault', 'sf.migrated_etag', 'sf.status',
                'fc.path', 'fc.etag', 'fc.size',
                's.id AS storage_id'
              )
              ->from('s3shadow_files', 'sf')
              ->leftJoin('sf', 'filecache',  'fc', $query->expr()->eq('sf.fileid', 'fc.fileid'))
              ->leftJoin('fc', 'storages',   's',  $query->expr()->eq('fc.storage', 's.numeric_id'))
              ->where($query->expr()->gt('sf.fileid', $query->createNamedParameter($lastId)))
              // CRITICAL: Never audit files the Migrator is actively uploading
              ->andWhere($query->expr()->neq('sf.status', $query->createNamedParameter('uploading')))
              ->orderBy('sf.fileid', 'ASC')
              ->setMaxResults(2000);

        $rows = $query->executeQuery()->fetchAll();

        if (empty($rows)) {
            $this->writeLiveLog('=== DB Audit Complete. Switching to S3 Bucket Scan ===');
            $this->config->setAppValue('s3shadowmigrator', 'self_heal_last_fileid', '0');
            $this->config->setAppValue('s3shadowmigrator', 'self_heal_phase', 's3_audit');
            return 0;
        }

        $highestId = $lastId;
        $results = ['healthy' => 0, 'fixed_a' => 0, 'fixed_c' => 0, 'critical' => 0, 'errors' => 0];

        foreach ($rows as $row) {
            $highestId = (int)$row['fileid'];
            try {
                $outcome = $this->auditSingleFile($row, $dataDir);
                $results[$outcome] = ($results[$outcome] ?? 0) + 1;
            } catch (\Exception $e) {
                $results['errors']++;
                $this->writeLiveLog('⚠ Error auditing file ID ' . $row['fileid'] . ': ' . $e->getMessage());
                $this->logger->error('SelfHealer: unexpected error auditing file ID ' . $row['fileid'] . ': ' . $e->getMessage(), [
                    'app' => 's3shadowmigrator', 'exception' => $e,
                ]);
            }
        }

        $this->config->setAppValue('s3shadowmigrator', 'self_heal_last_fileid', (string)$highestId);

        // Heartbeat: always write a summary so the live log confirms the healer is active
        $this->writeLiveLog(sprintf(
            '🩺 Batch done [up to ID %d]: %d audited, %d fixed, %d critical, %d errors',
            $highestId, count($rows),
            ($results['fixed_a'] ?? 0) + ($results['fixed_c'] ?? 0),
            $results['critical'] ?? 0,
            $results['errors'] ?? 0
        ));

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

        // Reconstruct local physical path from storage ID and filecache path.
        // Primary: use storage_id join + filecache path (most accurate).
        // Fallback: derive from s3_key (username/files/...) when storage_id join fails.
        $username  = str_starts_with($storageId, 'home::') ? substr($storageId, 6) : null;
        $localPath = ($username !== null && !empty($row['path']))
            ? $dataDir . '/' . $username . '/' . $row['path']
            : null;

        // Fallback: s3_key is always "username/files/relative/path", which maps directly
        // to the data directory. Use this when the storage join returned no username.
        if ($localPath === null && !empty($s3Key)) {
            $localPath = $dataDir . '/' . ltrim($s3Key, '/');
        }

        $localExists = $localPath !== null && file_exists($localPath);
        $localSize   = $localExists ? (int)filesize($localPath) : -1;

        // Lazy-load S3 existence check to avoid B2 API rate-limits and bottlenecks.
        // We only call this if we absolutely cannot determine health locally.
        $s3Size = -1;
        $s3Exists = null; // null = unchecked
        $getS3 = function() use (&$s3Size, &$s3Exists, $s3Key) {
            if ($s3Exists === null) {
                try {
                    $s3Size = $this->getS3ObjectSize($s3Key);
                    $s3Exists = ($s3Size >= 0);
                } catch (\Exception $e) {
                    $s3Exists = false;
                }
            }
        };

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

        // Calculate allocation for sparsity checks
        $allocatedBytes = $localSize;
        if ($localExists && $localSize > 0) {
            clearstatcache(true, $localPath);
            $stat = @stat($localPath);
            $allocatedBytes = isset($stat['blocks']) ? (int)$stat['blocks'] * 512 : $localSize;
        }
        $isSparseLocally = ($localSize === 0) || ($localSize > 0 && $allocatedBytes < 65536 && $localSize > $allocatedBytes);

        // Filesystems allocate minimum 4KB blocks. A 500-byte sparse file will have blocks=8 (4096 bytes),
        // making it indistinguishable from a real file via stat. We check for leading null bytes to be sure.
        if (!$isSparseLocally && $localExists && $localSize > 0) {
            $f = @fopen($localPath, 'r');
            if ($f) {
                $head = fread($f, 8);
                fclose($f);
                // If it's literally just null bytes, it was ftruncated.
                if ($head === str_repeat("\0", strlen($head))) {
                    $isSparseLocally = true;
                }
            }
        }

        // False positive scanner check: If the file is physically sparse, it could not have been modified by the user.
        // If the ETags mismatch, the scanner hashed the null bytes. We must repair the DB ETag to restore S3 streaming.
        if ($localExists && $isSparseLocally && !$etagMatches) {
            $this->writeLiveLog(sprintf('🔧 Repair [ID %d]: Sparse file ETag corrupted by scanner. Restoring ETag.', $fileId));
            $this->fileCacheUpdater->repairEtag($fileId, $row['migrated_etag']);
            return 'fixed_a'; // Repaired DB state
        }

        // Modified by user: file has real new content and new ETag. Leave it alone for Migrator to handle.
        if ($localExists && !$isSparseLocally && $localSize > 0 && !$etagMatches) {
            return 'healthy';
        }

        // Healthy Sparse File check:
        if ($localExists && $localSize > 0 && $etagMatches && !$isMirrorMode) {
            if ($isSparseLocally) {
                return 'healthy'; 
            }
        }

        // Mirror-Hydrate check: if it's mirror mode and it's sparse, we MUST download it.
        if ($localExists && $isMirrorMode && $isSparseLocally) {
            $getS3();
            if ($s3Exists) {
                $this->writeLiveLog(sprintf('💧 Mirror-Hydrate [ID %d]: %s was sparse. Restoring from S3...', $fileId, $row['path']));
                try {
                    if ($this->hydrateFromS3($s3Key, $localPath)) {
                        $this->writeLiveLog(sprintf('✓ [Hydrated] Successfully restored %s.', $row['path']));
                        $this->logger->info(sprintf('S3ShadowMigrator SelfHealer: hydrated mirrored file ID %d (%s) from S3.', $fileId, $row['path']), ['app' => 's3shadowmigrator']);
                    }
                } catch (\Exception $e) {
                    $this->writeLiveLog(sprintf('⚠ [Hydration Error] %s: %s', $row['path'], $e->getMessage()));
                }
            }
            return 'healthy';
        }

        // Healthy Mirror check: if it's mirror mode and it has real content, it's healthy.
        if ($localExists && $isMirrorMode && !$isSparseLocally && $etagMatches) {
            return 'healthy';
        }

        // Repair 0-byte file check (for NON-mirror files only)
        if ($localExists && $localSize === 0 && !$isMirrorMode) {
            $targetSize = (isset($row['size']) && (int)$row['size'] > 0) ? (int)$row['size'] : -1;
            
            if ($targetSize <= 0) {
                $getS3();
                $targetSize = $s3Size;
            }

            if ($targetSize > 0) {
                $f = @fopen($localPath, 'w');
                if ($f) {
                    ftruncate($f, $targetSize);
                    fclose($f);
                }
                if (isset($row['size']) && (int)$row['size'] === 0) {
                    $this->writeLiveLog(sprintf('🔧 Repair [ID %d]: disk=0b DB=0b S3=%d bytes. Wrote sparse file + repaired DB.', $fileId, $targetSize));
                    $this->updateFileCacheSize($fileId, $targetSize);
                }
                return 'fixed_a'; // Repaired locally
            } elseif ($targetSize === -1) {
                $getS3(); 
                if (!$s3Exists) {
                    $this->writeLiveLog(sprintf('🚨 CRITICAL [ID %d]: s3_key="%s" local=0b S3=MISSING — POSSIBLE DATA LOSS', $fileId, $s3Key));
                    $this->logger->critical(sprintf('S3ShadowMigrator SelfHealer CRITICAL: file ID %d s3_key="%s" — local file is 0 bytes AND S3 object is missing.', $fileId, $s3Key), ['app' => 's3shadowmigrator']);
                    $this->fileCacheUpdater->updateFileStatus($fileId, 'lost', $now);
                    return 'critical';
                }
            }
            return 'healthy';
        }

        // Corrupt-A: local file has real content but SHOULD be sparse (not mirror mode)
        if ($localExists && $localSize > 0 && !$isSparseLocally && $etagMatches && !$isMirrorMode) {
            $getS3();
            if ($s3Exists) {
                $mtime = @filemtime($localPath);
                if ($mtime !== false && $mtime > time() - 300) {
                    $this->logger->debug(sprintf('S3ShadowMigrator SelfHealer: ID %d has real content but mtime < 5 min, deferring Corrupt-A.', $fileId), ['app' => 's3shadowmigrator']);
                    return 'healthy';
                }

                // Guard: if the S3 object is 0 bytes (a previously failed upload),
                // DO NOT re-sparse the local file — treat it as Corrupt-C instead.
                if ($s3Size <= 0) {
                    $this->writeLiveLog(sprintf('♻ Corrupt-C (zero-S3) [ID %d]: local intact (%d bytes) but S3 object is 0 bytes. Removing sparse mark to re-queue.', $fileId, $localSize));
                    $this->fileCacheUpdater->removeSparseMark($fileId);
                    return 'fixed_c';
                }
                
                $this->writeLiveLog(sprintf('🔧 Corrupt-A [ID %d]: local file has %d bytes of real content (allocated %d bytes). Re-sparsing.', $fileId, $localSize, $allocatedBytes));
                $f = @fopen($localPath, 'w');
                if ($f) {
                    ftruncate($f, $s3Size);
                    fclose($f);
                }
                $this->fileCacheUpdater->updateFileStatus($fileId, 'active', $now);
                return 'fixed_a';
            } else {
                $this->writeLiveLog(sprintf('♻ Corrupt-C [ID %d]: local intact (%d bytes) but S3 object missing. Removing sparse mark to re-queue.', $fileId, $localSize));
                $this->fileCacheUpdater->removeSparseMark($fileId);
                return 'fixed_c';
            }
        }

        // For any case where local file is gone, we MUST check S3 first.
        // $s3Exists is lazily loaded — calling $getS3() here ensures it is always a bool, never null.
        if (!$localExists) {
            $getS3();
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

    private function getS3Client(): \Aws\S3\S3Client {
        if ($this->s3Client === null) {
            $this->s3Config = S3ConfigHelper::getS3Config($this->config, $this->db);
            $this->s3Client = S3ConfigHelper::createS3Client($this->s3Config);
        }
        return $this->s3Client;
    }

    /**
     * Performs a lightweight S3 HeadObject check and returns ContentLength.
     * Returns >= 0 if the object exists, -1 if 404.
     * Throws on unexpected S3 errors (network, auth, etc).
     */
    private function getS3ObjectSize(string $s3Key): int {
        if ($s3Key === '') {
            return -1;
        }

        try {
            $s3 = $this->getS3Client();
            $metadata = $s3->headObject([
                'Bucket' => $this->s3Config['bucket'],
                'Key'    => $s3Key,
            ]);
            return (int)$metadata['ContentLength'];
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return -1;
            }
            // Re-throw unexpected errors (auth failure, network, etc) so they're counted as errors
            throw $e;
        }
    }

    private function updateFileCacheSize(int $fileId, int $size): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('filecache')
           ->set('size', $qb->createNamedParameter($size))
           ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId)))
           ->executeStatement();
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
            $s3     = $this->getS3Client(); // initializes $this->s3Config as a side-effect
            $bucket = $this->s3Config['bucket'];

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
