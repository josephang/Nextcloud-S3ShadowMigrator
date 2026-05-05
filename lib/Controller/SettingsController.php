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
     * @NoCSRFRequired
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
        $this->config->setAppValue($this->appName, 'auto_upload_enabled', $auto_upload_enabled);
        $this->config->setAppValue($this->appName, 'batch_limit_files', $batch_limit_files);
        $this->config->setAppValue($this->appName, 's3_bucket_identifier', $s3_bucket_identifier);
        $this->config->setAppValue($this->appName, 's3_bucket_name', $s3_bucket_name);
        $this->config->setAppValue($this->appName, 's3_region', $s3_region);
        $this->config->setAppValue($this->appName, 's3_endpoint', $s3_endpoint);
        $this->config->setAppValue($this->appName, 's3_key', $s3_key);
        $this->config->setAppValue($this->appName, 's3_secret', $s3_secret);

        return new DataResponse(['status' => 'success']);
    }
}
