<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;

class DecryptController extends Controller {
    private IConfig $config;

    public function __construct(string $appName, IRequest $request, IConfig $config) {
        parent::__construct($appName, $request);
        $this->config = $config;
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     */
    public function index(): TemplateResponse {
        return new TemplateResponse('s3shadowmigrator', 'decrypt', [
            // Pre-fill the vault key so the user doesn't have to paste it manually.
            'vaultKey' => $this->config->getAppValue('s3shadowmigrator', 'vault_key', ''),
        ], 'blank');
    }
}
