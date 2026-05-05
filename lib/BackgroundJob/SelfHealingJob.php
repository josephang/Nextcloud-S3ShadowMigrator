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

        // Run once per hour — aggressive enough to catch issues without hammering S3
        $this->setInterval(3600);
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

        $this->logger->info('S3ShadowMigrator SelfHealingJob: starting hourly audit.', ['app' => 's3shadowmigrator']);

        try {
            $stats = $this->healingService->runFullAudit();

            if ($stats['critical'] > 0) {
                $this->logger->critical(sprintf(
                    'S3ShadowMigrator SelfHealingJob: %d critical data loss event(s) detected. Check Nextcloud logs and admin Redis dashboard immediately.',
                    $stats['critical']
                ), ['app' => 's3shadowmigrator']);
            }

            $this->logger->info(sprintf(
                'S3ShadowMigrator SelfHealingJob: audit complete. fixed=%d re-queued=%d critical=%d orphan-s3=%d',
                $stats['fixed_a'],
                $stats['fixed_c'],
                $stats['critical'],
                $stats['orphan_s3']
            ), ['app' => 's3shadowmigrator']);

        } catch (\Exception $e) {
            $this->logger->error('S3ShadowMigrator SelfHealingJob: unhandled exception: ' . $e->getMessage(), [
                'app'       => 's3shadowmigrator',
                'exception' => $e,
            ]);
        }
    }
}
