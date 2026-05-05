<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCA\S3ShadowMigrator\Service\S3MigrationService;
use Psr\Log\LoggerInterface;

class ShadowMigrationJob extends TimedJob {
    private S3MigrationService $migrationService;
    private IConfig $config;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        S3MigrationService $migrationService,
        IConfig $config,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->migrationService = $migrationService;
        $this->config = $config;
        $this->logger = $logger;

        // Set interval to run every 5 minutes (default for Nextcloud background jobs is often checked every 5 mins via system cron)
        $this->setInterval(300);
    }

    /**
     * @param array $argument
     */
    protected function run($argument): void {
        $isAutoUploadEnabled = $this->config->getAppValue('s3shadowmigrator', 'auto_upload_enabled', 'no');
        
        if ($isAutoUploadEnabled !== 'yes') {
            $this->logger->debug('S3ShadowMigrator auto-upload is disabled via settings. Skipping job.', ['app' => 's3shadowmigrator']);
            return;
        }

        $this->logger->info("S3ShadowMigrator continuous background daemon started.", ['app' => 's3shadowmigrator']);
        
        $startTime = microtime(true);
        $totalMigrated = 0;
        
        // Loop continuously for exactly 4 minutes (240 seconds)
        // This ensures maximum throughput without piling up Nextcloud's scheduler (which runs every 5 mins).
        while (microtime(true) - $startTime < 240) {
            try {
                // Drain in chunks of 1000 files to keep memory usage low
                $migrated = $this->migrationService->migrateBatch(1000);
                $totalMigrated += $migrated;
                
                if ($migrated === 0) {
                    // Drive is empty. Sleep for a few seconds to avoid spinning CPU until the 4 minutes are up
                    sleep(10);
                }
            } catch (\Exception $e) {
                $this->logger->error('Error during S3 shadow migration loop: ' . $e->getMessage(), [
                    'app' => 's3shadowmigrator',
                    'exception' => $e
                ]);
                break; // Exit loop on critical failure
            }
        }
        
        $this->logger->info("S3ShadowMigrator continuous daemon gracefully exited. Total migrated this session: {$totalMigrated} files.", ['app' => 's3shadowmigrator']);
    }
}
