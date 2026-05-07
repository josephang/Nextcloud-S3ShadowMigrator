<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCA\S3ShadowMigrator\Service\S3MigrationService;
use Psr\Log\LoggerInterface;

class ShadowMigrationJob extends TimedJob {
    private S3MigrationService $migrationService;
    private IConfig $config;
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        S3MigrationService $migrationService,
        IConfig $config,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->migrationService = $migrationService;
        $this->config = $config;
        $this->db     = $db;
        $this->logger = $logger;

        $this->setInterval(300);

        // CRITICAL: Prevent multiple instances from running simultaneously.
        // With cron every minute, without this flag multiple processes would pick
        // up the same files and truncate each other's uploads (IncompleteBody errors).
        $this->setAllowParallelRuns(false);
    }

    protected function run($argument): void {
        if ($this->config->getAppValue('s3shadowmigrator', 'auto_upload_enabled', 'no') !== 'yes') {
            $this->logger->debug('S3ShadowMigrator auto-upload disabled. Skipping.', ['app' => 's3shadowmigrator']);
            return;
        }

        // Self-recovery: clear stale reserved_at after 10 minutes (Nextcloud waits 12 hours)
        $this->clearStaleReservation();

        $this->logger->info('S3ShadowMigrator: migration daemon started.', ['app' => 's3shadowmigrator']);

        $startTime = microtime(true);
        $totalMigrated = 0;

        // Run for 3 minutes (180s), leaving headroom before the 5-min interval.
        while (microtime(true) - $startTime < 180) {
            try {
                $migrated = $this->migrationService->migrateBatch(1000);
                $totalMigrated += $migrated;

                if ($migrated === 0) {
                    sleep(10);
                }
            } catch (\Exception $e) {
                $this->logger->error('S3ShadowMigrator: error in migration loop: ' . $e->getMessage(), [
                    'app'       => 's3shadowmigrator',
                    'exception' => $e,
                ]);
                break;
            }
        }

        $this->logger->info("S3ShadowMigrator: daemon exited. Total migrated: {$totalMigrated}.", ['app' => 's3shadowmigrator']);
    }

    private function clearStaleReservation(): void {
        try {
            $staleThreshold = time() - 600;
            $qb = $this->db->getQueryBuilder();
            $qb->update('jobs')
               ->set('reserved_at', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
               ->where($qb->expr()->eq('class', $qb->createNamedParameter(self::class)))
               ->andWhere($qb->expr()->gt('reserved_at', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->lt('reserved_at', $qb->createNamedParameter($staleThreshold, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
               ->executeStatement();
        } catch (\Exception $e) {
            $this->logger->warning('S3ShadowMigrator: could not clear stale reservation: ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
        }
    }
}
