<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCA\S3ShadowMigrator\Service\SelfHealingService;
use Psr\Log\LoggerInterface;

/**
 * Hourly background job that runs a full self-healing audit.
 * Runs entirely independent from ShadowMigrationJob to avoid lock contention.
 */
class SelfHealingJob extends TimedJob {
    private SelfHealingService $healingService;
    private IConfig $config;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        SelfHealingService $healingService,
        IConfig $config,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->healingService = $healingService;
        $this->config         = $config;
        $this->logger         = $logger;

        // Run every 5 minutes (acts as a continuous daemon wrapper)
        $this->setInterval(300);
    }

    /**
     * @param array $argument
     */
    protected function run($argument): void {
        // Only run if the daemon is enabled — no point healing if migration is off
        if ($this->config->getAppValue('s3shadowmigrator', 'auto_upload_enabled', 'no') !== 'yes') {
            $this->logger->debug('S3ShadowMigrator SelfHealingJob: daemon disabled, skipping audit.', ['app' => 's3shadowmigrator']);
            return;
        }

        $this->logger->info('S3ShadowMigrator SelfHealingJob: continuous background daemon started.', ['app' => 's3shadowmigrator']);

        $startTime = microtime(true);
        $totalProcessed = 0;

        // Loop continuously for exactly 4 minutes (240 seconds)
        while (microtime(true) - $startTime < 240) {
            try {
                // Run one chunk of the audit (DB or S3)
                $processed = $this->healingService->runAuditBatch();
                $totalProcessed += $processed;

                if ($processed === 0) {
                    // Current phase is fully idle or bucket is empty.
                    // Sleep to prevent tight loop CPU spinning before next tick.
                    sleep(10);
                }
            } catch (\Exception $e) {
                $this->logger->error('S3ShadowMigrator SelfHealingJob: unhandled exception in loop: ' . $e->getMessage(), [
                    'app'       => 's3shadowmigrator',
                    'exception' => $e,
                ]);
                break;
            }
        }

        $this->logger->info("S3ShadowMigrator SelfHealingJob: daemon gracefully exited after 4 mins. Items processed: {$totalProcessed}.", ['app' => 's3shadowmigrator']);
    }
}
