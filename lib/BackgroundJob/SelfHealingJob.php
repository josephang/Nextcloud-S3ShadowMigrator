<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCA\S3ShadowMigrator\Service\SelfHealingService;
use Psr\Log\LoggerInterface;

/**
 * Background job that runs a self-healing audit every 5 minutes.
 * Runs independently from ShadowMigrationJob to avoid lock contention.
 */
class SelfHealingJob extends TimedJob {
    private SelfHealingService $healingService;
    private IConfig $config;
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        SelfHealingService $healingService,
        IConfig $config,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->healingService = $healingService;
        $this->config         = $config;
        $this->db             = $db;
        $this->logger         = $logger;

        // Run every 5 minutes
        $this->setInterval(300);

        // Tell Nextcloud to enforce single-instance: sets reserved_at on start,
        // clears it on completion. Prevents double-runs.
        $this->setAllowParallelRuns(false);
    }

    protected function run($argument): void {
        // The healer uses its own flag, independent of whether uploads are enabled.
        // You can pause uploads without stopping the healer (and vice versa).
        if ($this->config->getAppValue('s3shadowmigrator', 'self_heal_enabled', 'yes') !== 'yes') {
            $this->logger->debug('S3ShadowMigrator SelfHealingJob: self-healing disabled.', ['app' => 's3shadowmigrator']);
            return;
        }

        // Self-recovery: if a previous run was killed by PHP (timeout/OOM), reserved_at
        // stays set permanently. Nextcloud waits 12 hours to auto-clear it. We clear after 10 min.
        $this->clearStaleReservation();

        $this->logger->info('S3ShadowMigrator SelfHealingJob: background daemon started.', ['app' => 's3shadowmigrator']);

        $startTime = microtime(true);
        $totalProcessed = 0;

        // Loop for 3 minutes (180s). Leaves headroom before the 5-min interval so the
        // job always finishes cleanly and PHP never kills it mid-run.
        while (microtime(true) - $startTime < 180) {
            try {
                $processed = $this->healingService->runAuditBatch();
                $totalProcessed += $processed;

                if ($processed === 0) {
                    sleep(5); // Phase complete — avoid CPU spin
                }
            } catch (\Exception $e) {
                $this->logger->error('S3ShadowMigrator SelfHealingJob: exception in loop: ' . $e->getMessage(), [
                    'app'       => 's3shadowmigrator',
                    'exception' => $e,
                ]);
                break;
            }
        }

        $this->logger->info("S3ShadowMigrator SelfHealingJob: completed. Items processed: {$totalProcessed}.", ['app' => 's3shadowmigrator']);
    }

    /**
     * If a previous run was killed by PHP, reserved_at stays set and Nextcloud won't
     * clear it for 12 hours. We clear it ourselves after 10 minutes.
     */
    private function clearStaleReservation(): void {
        try {
            $staleThreshold = time() - 600; // 10 minutes ago
            $qb = $this->db->getQueryBuilder();
            $qb->update('jobs')
               ->set('reserved_at', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
               ->where($qb->expr()->eq('class', $qb->createNamedParameter(self::class)))
               ->andWhere($qb->expr()->gt('reserved_at', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->lt('reserved_at', $qb->createNamedParameter($staleThreshold, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
               ->executeStatement();
        } catch (\Exception $e) {
            $this->logger->warning('S3ShadowMigrator SelfHealingJob: could not clear stale reservation: ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
        }
    }
}
