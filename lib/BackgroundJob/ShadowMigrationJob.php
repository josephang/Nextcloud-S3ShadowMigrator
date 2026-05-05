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

        // Run every 15 minutes for aggressive drain. Nextcloud won't overlap cron runs.
        $this->setInterval(900);
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

        $batchLimitFiles = (int)$this->config->getAppValue('s3shadowmigrator', 'batch_limit_files', '500');

        $this->logger->info("S3ShadowMigrator background job starting with batch limit of {$batchLimitFiles} files.", ['app' => 's3shadowmigrator']);

        try {
            $migrated = $this->migrationService->migrateBatch($batchLimitFiles);
            if ($migrated > 0) {
                $this->logger->info("S3ShadowMigrator cron: migrated {$migrated} files this run.", ['app' => 's3shadowmigrator']);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error during S3 shadow migration: ' . $e->getMessage(), [
                'app' => 's3shadowmigrator',
                'exception' => $e
            ]);
        }
    }
}
