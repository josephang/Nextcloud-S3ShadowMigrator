<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Settings;

use OCP\Settings\ISettings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;

class Admin implements ISettings {
    private IConfig $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    public function getForm() {
        $parameters = [
            'auto_upload_enabled' => $this->config->getAppValue('s3shadowmigrator', 'auto_upload_enabled', 'no'),
            'batch_limit_files' => $this->config->getAppValue('s3shadowmigrator', 'batch_limit_files', '500'),
            's3_bucket_identifier' => $this->config->getAppValue('s3shadowmigrator', 's3_bucket_identifier', ''),
            's3_bucket_name' => $this->config->getAppValue('s3shadowmigrator', 's3_bucket_name', ''),
            's3_region' => $this->config->getAppValue('s3shadowmigrator', 's3_region', 'us-east-1'),
            's3_endpoint' => $this->config->getAppValue('s3shadowmigrator', 's3_endpoint', ''),
            's3_key' => $this->config->getAppValue('s3shadowmigrator', 's3_key', ''),
            's3_secret' => $this->config->getAppValue('s3shadowmigrator', 's3_secret', ''),
        ];

        return new TemplateResponse('s3shadowmigrator', 'settings-admin', $parameters);
    }

    public function getSection(): string {
        return 'additional';
    }

    public function getPriority(): int {
        return 10;
    }
}
