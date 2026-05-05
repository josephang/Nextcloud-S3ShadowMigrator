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
        string $batch_limit_files,
        string $s3_bucket_identifier,
        string $s3_bucket_name,
        string $s3_region,
        string $s3_endpoint,
        string $s3_key,
        string $s3_secret
    ): DataResponse {
        // BUG FIX: Sanitize batch_limit_files to prevent storing an arbitrary integer
        $batchLimit = max(1, min(5000, (int)$batch_limit_files));

        // BUG FIX: Strip trailing slash from endpoint to prevent double-slash in URLs
        $s3_endpoint = rtrim(trim($s3_endpoint), '/');

        $this->config->setAppValue($this->appName, 'auto_upload_enabled', $auto_upload_enabled === 'yes' ? 'yes' : 'no');
        $this->config->setAppValue($this->appName, 'batch_limit_files', (string)$batchLimit);
        $this->config->setAppValue($this->appName, 's3_bucket_identifier', trim($s3_bucket_identifier));
        $this->config->setAppValue($this->appName, 's3_bucket_name', trim($s3_bucket_name));
        $this->config->setAppValue($this->appName, 's3_region', trim($s3_region));
        $this->config->setAppValue($this->appName, 's3_endpoint', $s3_endpoint);
        $this->config->setAppValue($this->appName, 's3_key', trim($s3_key));
        $this->config->setAppValue($this->appName, 's3_secret', $s3_secret); // Don't trim secrets

        return new DataResponse(['status' => 'success']);
    }
}
