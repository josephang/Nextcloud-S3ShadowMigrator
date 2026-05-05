<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IConfig;

class SettingsController extends Controller {
    private IConfig $config;

    public function __construct(string $AppName, IRequest $request, IConfig $config) {
        parent::__construct($AppName, $request);
        $this->config = $config;
    }

    /**
     * BUG FIX: The old annotation was @NoCSRFRequired which completely disables CSRF protection.
     * Admin settings endpoints should KEEP CSRF protection (it's the default).
     * We remove @NoCSRFRequired and keep only @AdminRequired.
     * The frontend fetch() sends the requesttoken header, so this will work correctly.
     *
     * @AdminRequired
     */
    public function saveSettings(
        string $auto_upload_enabled,
        string $s3_mount_id,
        string $throttle_mode,
        string $custom_throttle_mb,
        string $exclusion_mode,
        string $excluded_users
    ): DataResponse {
        $this->config->setAppValue($this->appName, 'auto_upload_enabled', $auto_upload_enabled === 'yes' ? 'yes' : 'no');
        $this->config->setAppValue($this->appName, 's3_mount_id', trim($s3_mount_id));
        $this->config->setAppValue($this->appName, 'throttle_mode', trim($throttle_mode));
        $this->config->setAppValue($this->appName, 'custom_throttle_mb', trim($custom_throttle_mb));
        $this->config->setAppValue($this->appName, 'exclusion_mode', trim($exclusion_mode));
        $this->config->setAppValue($this->appName, 'excluded_users', trim($excluded_users));

        return new DataResponse(['status' => 'success']);
    }

    /**
     * @AdminRequired
     */
    public function status(): DataResponse {
        $cache = \OC::$server->getMemCacheFactory()->createDistributed('s3shadowmigrator');
        $log = (string)$cache->get('live_log');
        return new DataResponse(['log' => $log]);
    }
}
