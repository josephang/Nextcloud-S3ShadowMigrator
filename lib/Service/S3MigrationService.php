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

    private function writeLiveLog(string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        // Use createDistributed() so the CLI cron job and PHP-FPM web process share the same key
        $cache = \OC::$server->getMemCacheFactory()->createDistributed('s3shadowmigrator');
        
        $currentLog = (string)$cache->get('live_log');
        if (strlen($currentLog) > 4000) {
            $currentLog = substr($currentLog, -2000);
            // Ensure we don't start midway through a line
            $newlinePos = strpos($currentLog, "\n");
            if ($newlinePos !== false) {
                $currentLog = substr($currentLog, $newlinePos + 1);
            }
        }
        
        $newLog = $currentLog . "[{$timestamp}] {$message}\n";
        $cache->set('live_log', $newLog, 3600);
    }

    private function getS3Client(): S3Client {
        if ($this->s3Client === null) {
            $s3Config = S3ConfigHelper::getS3Config($this->config, $this->db);
            $this->s3Client = S3ConfigHelper::createS3Client($s3Config);
        }
        return $this->s3Client;
    }

    private function getBucketName(): string {
        $s3Config = S3ConfigHelper::getS3Config($this->config, $this->db);
        return $s3Config['bucket'];
    }

    /**
     * Main batch entry point called by the cron job and CLI command.
     * Drains local home storage files into the configured S3/B2 bucket.
     */
    public function migrateBatch(int $limit = 500, int $maxSizeBytes = 0, ?callable $progressCallback = null): int {
        $s3MountId = $this->config->getAppValue('s3shadowmigrator', 's3_mount_id', '0');
        if ($s3MountId === '0') {
            $this->logger->warning('S3ShadowMigrator: S3 Mount ID not configured. Skipping migration.', ['app' => 's3shadowmigrator']);
            $this->writeLiveLog('ERROR: S3 Mount ID not configured. Skipping migration.');
            return 0;
        }

        $this->writeLiveLog("Starting database sweep for {$limit} files...");
        $filesToMigrate = $this->getLocalFilesToMigrate($limit, $maxSizeBytes);
        $count = count($filesToMigrate);

        if ($count === 0) {
            $this->logger->info('S3ShadowMigrator: no local files found to migrate. Drain is complete or nothing is configured.', ['app' => 's3shadowmigrator']);
            $this->writeLiveLog("SUCCESS: No local files found to migrate. Drive is empty.");
            return 0;
        }

        $this->logger->info("S3ShadowMigrator: starting batch of {$count} files.", ['app' => 's3shadowmigrator']);
        $this->writeLiveLog("Found {$count} files. Starting upload...");
        $succeeded = 0;

        foreach ($filesToMigrate as $fileRecord) {
            // Extract username from storage ID string e.g. "home::josephang" → "josephang"
            $username = $this->extractUsernameFromStorageId($fileRecord['storage_id_string']);
            if ($username === null) {
                $this->logger->warning("S3ShadowMigrator: could not extract username from storage_id '{$fileRecord['storage_id_string']}'. Skipping.", ['app' => 's3shadowmigrator']);
                if ($progressCallback !== null) {
                    $progressCallback();
                }
                continue;
            }

            if ($this->migrateFileRecord($fileRecord, $username)) {
                $succeeded++;
            }
            if ($progressCallback !== null) {
                $progressCallback();
            }
        }

        $this->logger->info("S3ShadowMigrator: batch complete. {$succeeded}/{$count} files migrated.", ['app' => 's3shadowmigrator']);
        $this->writeLiveLog("Batch complete. Successfully migrated {$succeeded}/{$count} files.");
        return $succeeded;
    }

    /**
     * Finds files in local home:: storages only.
     * Joins oc_storages to get the storage string ID for username extraction.
     * Excludes directories, thumbnails, appdata, and partial uploads.
     */
    public function getLocalFilesToMigrate(int $limit, int $maxSizeBytes = 0): array {
        $query = $this->db->getQueryBuilder();
        $query->select('fc.fileid', 'fc.path', 'fc.size', 'fc.storage', 'fc.etag', 's.id AS storage_id_string')
              ->from('filecache', 'fc')
              ->innerJoin('fc', 'storages', 's', $query->expr()->eq('fc.storage', 's.numeric_id'))
              ->innerJoin('fc', 'mimetypes', 'mt', $query->expr()->eq('fc.mimetype', 'mt.id'))
              ->leftJoin('fc', 's3shadow_files', 'sf', $query->expr()->eq('fc.fileid', 'sf.fileid'))
              // Only local home storages
              ->where($query->expr()->like('s.id', $query->createNamedParameter('home::%')));
                     // EXCLUSION / INCLUSION LOGIC
        $exclusionMode = $this->config->getAppValue('s3shadowmigrator', 'exclusion_mode', 'blacklist');
        $excludedUsersStr = $this->config->getAppValue('s3shadowmigrator', 'excluded_users', '');
        
        $targetUsernames = [];
        if (!empty($excludedUsersStr)) {
            $items = array_map('trim', explode(',', $excludedUsersStr));
            $explicitUsers = [];
            $groups = [];
            
            foreach ($items as $item) {
                if (str_starts_with($item, 'user::')) {
                    $explicitUsers[] = substr($item, 6);
                } elseif (str_starts_with($item, 'group::')) {
                    $groups[] = substr($item, 7);
                }
            }
            
            $targetUsernames = $explicitUsers;
            
            if (!empty($groups)) {
                $groupQuery = $this->db->getQueryBuilder();
                $groupQuery->select('uid')
                           ->from('group_user')
                           ->where($groupQuery->expr()->in('gid', $groupQuery->createNamedParameter($groups, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
                // fetchAll() with associative array, then extract column
                $groupUsersRows = $groupQuery->executeQuery()->fetchAll();
                $groupUsers = array_map(function($row) { return $row['uid']; }, $groupUsersRows);
                
                if (!empty($groupUsers)) {
                    $targetUsernames = array_merge($targetUsernames, $groupUsers);
                }
            }
            
            $targetUsernames = array_unique($targetUsernames);
        }

        if (!empty($targetUsernames)) {
            $storageIds = array_map(function($u) { return 'home::' . $u; }, $targetUsernames);
            if ($exclusionMode === 'whitelist') {
                $query->andWhere($query->expr()->in('s.id', $query->createNamedParameter($storageIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
            } else {
                $query->andWhere($query->expr()->notIn('s.id', $query->createNamedParameter($storageIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
            }
        } elseif ($exclusionMode === 'whitelist') {
            // Whitelist is empty: migrate no one. Use a dummy impossible condition.
            $query->andWhere($query->expr()->eq('1', $query->createNamedParameter('0')));
        }
        
        $query->andWhere($query->expr()->neq('mt.mimetype', $query->createNamedParameter('httpd/unix-directory')))
              // Only real user files, not thumbnails/cache
              ->andWhere($query->expr()->like('fc.path', $query->createNamedParameter('files/%')))
              // Must have content
              ->andWhere($query->expr()->gt('fc.size', $query->createNamedParameter(0)))
              // Exclude partial/temp uploads
              ->andWhere($query->expr()->notLike('fc.path', $query->createNamedParameter('%.ocTransferId%')))
              // COOLDOWN: Only migrate files older than 30 minutes to avoid race conditions with chunked uploads
              ->andWhere($query->expr()->lt('fc.mtime', $query->createNamedParameter(time() - 1800)))
              // EXCLUSION: Exclude files that are already sparse, UNLESS they were overwritten locally (ETag changed)
              ->andWhere($query->expr()->orX(
                  $query->expr()->isNull('sf.fileid'),
                  $query->expr()->neq('fc.etag', 'sf.migrated_etag')
              ))
              ->andWhere($query->expr()->notLike('fc.path', $query->createNamedParameter('%/.ocdata')))
              // Order by smallest files first for faster initial drain
              ->orderBy('fc.size', 'ASC');

        // Optional: only migrate files below a size threshold
        if ($maxSizeBytes > 0) {
            $query->andWhere($query->expr()->lte('fc.size', $query->createNamedParameter($maxSizeBytes)));
        }

        $query->setMaxResults($limit);

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
    public function migrateFileRecord(array $fileRecord, string $username): bool {
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

        $stat = stat($localPhysicalPath);
        if ($stat !== false && $stat['blocks'] == 0 && $stat['size'] > 0) {
            $this->logger->error("S3ShadowMigrator: CRITICAL ERROR - file ID {$fileId} is a sparse stub (Blocks: 0) but was queued for upload. Skipping to prevent S3 corruption.", ['app' => 's3shadowmigrator']);
            $this->writeLiveLog("✗ Skipped ID {$fileId}: sparse stub on disk");
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

        $isVault = str_contains($cachePath, '/EncryptedVault/');
        $sourceFile = $localPhysicalPath;
        $tempVaultFile = null;

        if ($isVault) {
            $vaultKeyHex = $this->config->getAppValue('s3shadowmigrator', 'vault_key', '');
            if (empty($vaultKeyHex)) {
                $this->logger->info("S3ShadowMigrator: vault_key not found. Auto-generating a new AES-256 master key.", ['app' => 's3shadowmigrator']);
                $vaultKeyHex = bin2hex(random_bytes(32));
                $this->config->setAppValue('s3shadowmigrator', 'vault_key', $vaultKeyHex);
            }

            $tempVaultFile = $localPhysicalPath . '.vault.tmp';
            $ivHex = bin2hex(random_bytes(16));
            
            // Fast OpenSSL streaming encryption: write 32-byte IV first, then append ciphertext
            $cmd = sprintf(
                'echo -n %s > %s && openssl enc -aes-256-cbc -e -in %s -K %s -iv %s >> %s',
                escapeshellarg($ivHex),
                escapeshellarg($tempVaultFile),
                escapeshellarg($localPhysicalPath),
                escapeshellarg($vaultKeyHex),
                escapeshellarg($ivHex),
                escapeshellarg($tempVaultFile)
            );
            
            exec($cmd, $output, $returnVar);
            if ($returnVar !== 0) {
                $this->logger->error("S3ShadowMigrator: OpenSSL encryption failed for {$cachePath}", ['app' => 's3shadowmigrator']);
                if (file_exists($tempVaultFile)) unlink($tempVaultFile);
                return false;
            }
            $sourceFile = $tempVaultFile;
        }

        // Throttle Logic Pre-computation
        $startTime = microtime(true);

        try {
            $sourceFileSize = filesize($sourceFile);
            $bucketName = $this->getBucketName();

            $s3 = $this->getS3Client();

            // Stamp 'uploading' in the DB BEFORE reading the file.
            // The SelfHealer will skip any file with this status, preventing it
            // from truncating the file to 0 bytes while we are mid-upload.
            $this->fileCacheUpdater->markFileAsUploading($fileId, $s3Key);

            // Use multipart upload for large files
            if ($sourceFileSize >= self::MULTIPART_THRESHOLD_BYTES) {
                $this->logger->info("S3ShadowMigrator: using multipart upload for file ID {$fileId} ({$sourceFileSize} bytes).", ['app' => 's3shadowmigrator']);
                $uploader = new \Aws\S3\MultipartUploader($s3, $sourceFile, [
                    'bucket' => $bucketName,
                    'key'    => $s3Key,
                ]);
                $uploader->upload();
            } else {
                $localHash = md5_file($sourceFile);
                $s3->putObject([
                    'Bucket'     => $bucketName,
                    'Key'        => $s3Key,
                    'SourceFile' => $sourceFile,
                    'ContentMD5' => base64_encode(pack('H*', $localHash)),
                ]);
            }

            // Mark file as sparse in custom tracking table
            $dbSuccess = $this->fileCacheUpdater->markFileAsSparse($fileId, $fileRecord['etag'], $s3Key, $isVault);

            if (!$dbSuccess) {
                // DB failed — roll back S3 upload to prevent orphan objects
                $this->logger->error("S3ShadowMigrator: DB update failed for file ID {$fileId}. Rolling back S3 object.", ['app' => 's3shadowmigrator']);
                $this->writeLiveLog("✗ DB Error: rolling back S3 object for file ID {$fileId}");
                try {
                    $s3->deleteObject(['Bucket' => $bucketName, 'Key' => $s3Key]);
                } catch (\Exception $cleanupError) {
                    $this->logger->warning("S3ShadowMigrator: failed to clean up orphaned S3 object '{$s3Key}': " . $cleanupError->getMessage(), ['app' => 's3shadowmigrator']);
                }
                return false;
            }

            // --- Throttling Logic (runs AFTER upload, computed against actual elapsed time) ---
            $throttleMode = $this->config->getAppValue('s3shadowmigrator', 'throttle_mode', 'unlimited');
            $mbps = 0.0;
            if ($throttleMode === 'balanced') {
                $mbps = 50.0;
            } elseif ($throttleMode === 'gentle') {
                $mbps = 10.0;
            } elseif ($throttleMode === 'custom') {
                $customMb = (float)$this->config->getAppValue('s3shadowmigrator', 'custom_throttle_mb', '50');
                $mbps = max(0.1, $customMb); // Prevent division by zero
            }

            if ($mbps > 0) {
                $expectedSeconds = ($sourceFileSize / 1024 / 1024) / $mbps;
                $actualSeconds = microtime(true) - $startTime;
                if ($actualSeconds < $expectedSeconds) {
                    $sleepMicro = (int)(($expectedSeconds - $actualSeconds) * 1000000);
                    usleep($sleepMicro);
                }
            }
            // ---------------------------------------------------------------------------------

            // Check if Mirror Mode path matches
            $mirrorPathsStr = $this->config->getAppValue('s3shadowmigrator', 'mirror_paths', 'Notes/');
            $mirrorPaths = array_filter(array_map('trim', explode(',', $mirrorPathsStr)));
            $isMirrorMode = false;
            foreach ($mirrorPaths as $mp) {
                if (!empty($mp) && str_contains($cachePath, $mp)) {
                    $isMirrorMode = true;
                    break;
                }
            }

            if ($isMirrorMode) {
                $humanSize = $sourceFileSize >= 1048576
                    ? round($sourceFileSize / 1048576, 2) . ' MB'
                    : round($sourceFileSize / 1024, 1) . ' KB';
                $this->writeLiveLog("✓ [Mirror Mode] Uploaded {$cachePath} without truncating.");
                $this->logger->info("S3ShadowMigrator: mirror-migrated file ID {$fileId} ({$humanSize}) → s3://{$bucketName}/{$s3Key}", ['app' => 's3shadowmigrator']);
            } else {
                // Create a Linux sparse file: content cleared, but filesize() reports the
                // real size. Takes near-zero disk space. Stops Nextcloud's scanner from
                // resetting oc_filecache.size to 0 (which caused the "0 bytes in UI" bug).
                $f = fopen($localPhysicalPath, 'w');
                if ($f) {
                    ftruncate($f, $sourceFileSize); // sparse hole, not actual disk usage
                    fclose($f);
                } else {
                    $this->logger->warning("S3ShadowMigrator: uploaded and DB-marked file ID {$fileId} but sparse truncation failed: {$localPhysicalPath}", ['app' => 's3shadowmigrator']);
                }

                // CRITICAL: After sparse truncation the file's physical ETag changes (content is now
                // null bytes). The migrator's query re-queues files where fc.etag != sf.migrated_etag.
                // We MUST update oc_filecache.etag to match the pre-migration value so the migrator
                // doesn't immediately re-upload the 0-byte sparse stub over the real S3 object.
                try {
                    $qb = $this->db->getQueryBuilder();
                    $qb->update('filecache')
                       ->set('etag', $qb->createNamedParameter($fileRecord['etag']))
                       ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId)))
                       ->executeStatement();
                } catch (\Exception $e) {
                    $this->logger->warning("S3ShadowMigrator: could not update filecache etag for ID {$fileId}: " . $e->getMessage(), ['app' => 's3shadowmigrator']);
                }

                $humanSize = $sourceFileSize >= 1048576
                    ? round($sourceFileSize / 1048576, 2) . ' MB'
                    : round($sourceFileSize / 1024, 1) . ' KB';
                $this->writeLiveLog("✓ [{$humanSize}] → {$s3Key}");
                $this->logger->info("S3ShadowMigrator: sparse-migrated file ID {$fileId} ({$humanSize}) → s3://{$bucketName}/{$s3Key}", ['app' => 's3shadowmigrator']);
            }

            return true;

        } catch (MultipartUploadException $e) {
            $this->logger->error("S3ShadowMigrator: multipart upload failed for file ID {$fileId}: " . $e->getMessage(), ['app' => 's3shadowmigrator']);
            $this->writeLiveLog("✗ Multipart Error for ID {$fileId}: " . $e->getMessage());
            $this->fileCacheUpdater->unmarkFileAsUploading($fileId);
            return false;
        } catch (S3Exception $e) {
            $this->logger->error("S3ShadowMigrator: S3 upload failed for file ID {$fileId}: " . $e->getMessage(), ['app' => 's3shadowmigrator']);
            $this->writeLiveLog("✗ S3 Error for ID {$fileId}: " . $e->getAwsErrorCode());
            $this->fileCacheUpdater->unmarkFileAsUploading($fileId);
            return false;
        } catch (\Exception $e) {
            $this->logger->error("S3ShadowMigrator: unexpected error for file ID {$fileId}: " . $e->getMessage(), [
                'app'       => 's3shadowmigrator',
                'exception' => $e,
            ]);
            $this->writeLiveLog("✗ Error for ID {$fileId}: " . $e->getMessage());
            // Ensure 'uploading' reservation is cleared so the file can be retried on the next pass
            $this->fileCacheUpdater->unmarkFileAsUploading($fileId);
            return false;
        } finally {
            if ($tempVaultFile && file_exists($tempVaultFile)) {
                unlink($tempVaultFile);
            }
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
        $query->select('fc.fileid', 'fc.path', 'fc.size', 'fc.storage', 'fc.etag', 's.id AS storage_id_string')
              ->from('filecache', 'fc')
              ->innerJoin('fc', 'storages', 's', $query->expr()->eq('fc.storage', 's.numeric_id'))
              ->where($query->expr()->eq('fc.fileid', $query->createNamedParameter($fileId)));

        $fileRecord = $query->executeQuery()->fetch();

        if (!$fileRecord) {
            $this->logger->error("S3ShadowMigrator: file ID {$fileId} not found in oc_filecache.", ['app' => 's3shadowmigrator']);
            return false;
        }

        if (!str_starts_with($fileRecord['storage_id_string'], 'home::')) {
            $this->logger->info("S3ShadowMigrator: file ID {$fileId} is not a local file. Skipping.", ['app' => 's3shadowmigrator']);
            return false;
        }

        $username = $this->extractUsernameFromStorageId($fileRecord['storage_id_string']);
        if ($username === null) {
            $this->logger->error("S3ShadowMigrator: storage '{$fileRecord['storage_id_string']}' is not a local home storage. Cannot migrate.", ['app' => 's3shadowmigrator']);
            return false;
        }

        return $this->migrateFileRecord($fileRecord, $username);
    }
}
