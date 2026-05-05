<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IConfig;
use OCA\S3ShadowMigrator\Service\S3MigrationService;
use Psr\Log\LoggerInterface;

class SettingsController extends Controller {
    private IConfig $config;
    private S3MigrationService $migrationService;
    private LoggerInterface $logger;

    public function __construct(
        string $AppName,
        IRequest $request,
        IConfig $config,
        S3MigrationService $migrationService,
        LoggerInterface $logger
    ) {
        parent::__construct($AppName, $request);
        $this->config           = $config;
        $this->migrationService = $migrationService;
        $this->logger           = $logger;
    }

    /**
     * Saves all admin settings.
     * Route: POST /apps/s3shadowmigrator/settings  (name: settings#save → method: save)
     *
     * @AdminRequired
     */
    public function save(
        string $auto_upload_enabled,
        string $s3_mount_id,
        string $throttle_mode,
        string $custom_throttle_mb,
        string $exclusion_mode,
        string $excluded_users
    ): DataResponse {
        $this->config->setAppValue($this->appName, 'auto_upload_enabled', $auto_upload_enabled === 'yes' ? 'yes' : 'no');
        $this->config->setAppValue($this->appName, 's3_mount_id',         trim($s3_mount_id));
        $this->config->setAppValue($this->appName, 'throttle_mode',       trim($throttle_mode));
        $this->config->setAppValue($this->appName, 'custom_throttle_mb',  trim($custom_throttle_mb));
        $this->config->setAppValue($this->appName, 'exclusion_mode',      trim($exclusion_mode));
        $this->config->setAppValue($this->appName, 'excluded_users',      trim($excluded_users));

        return new DataResponse(['status' => 'success']);
    }

    /**
     * Returns the current Redis live log for the transparency dashboard.
     * Route: GET /apps/s3shadowmigrator/status
     *
     * @AdminRequired
     */
    public function status(): DataResponse {
        $cache = \OC::$server->getMemCacheFactory()->createDistributed('s3shadowmigrator');
        $log   = (string)$cache->get('live_log');
        return new DataResponse(['log' => $log]);
    }

    /**
     * Immediately runs one migration batch synchronously and returns the log.
     * Called when the admin toggles the daemon ON in the UI for instant feedback.
     * Route: POST /apps/s3shadowmigrator/trigger
     *
     * @AdminRequired
     */
    public function trigger(): DataResponse {
        if ($this->config->getAppValue($this->appName, 'auto_upload_enabled', 'no') !== 'yes') {
            return new DataResponse(['status' => 'disabled', 'log' => 'Daemon is disabled. Enable it first.']);
        }

        try {
            // Run a small synchronous batch (50 files) for immediate UI feedback
            $migrated = $this->migrationService->migrateBatch(50);

            $cache = \OC::$server->getMemCacheFactory()->createDistributed('s3shadowmigrator');
            $log   = (string)$cache->get('live_log');

            return new DataResponse([
                'status'   => 'ok',
                'migrated' => $migrated,
                'log'      => $log,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('S3ShadowMigrator trigger error: ' . $e->getMessage(), ['app' => 's3shadowmigrator']);
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
