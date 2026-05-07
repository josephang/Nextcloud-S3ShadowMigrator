<?php

declare(strict_types=1);

namespace OCA\S3ShadowMigrator\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\S3ShadowMigrator\Middleware\DownloadInterceptorMiddleware;

class Application extends App implements IBootstrap {
    public const APP_ID = 's3shadowmigrator';

    public function __construct() {
        parent::__construct(self::APP_ID);
        if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
            require_once __DIR__ . '/../../vendor/autoload.php';
        }
    }

    public function register(IRegistrationContext $context): void {
        // Not registered as AppFramework middleware, but used as a service in EventDispatcher
    }

    public function boot(IBootContext $context): void {
        // Intercept all file reads (WebDAV + UI Streaming) using Nextcloud Event Dispatcher
        $eventDispatcher = $context->getServerContainer()->get(\OCP\EventDispatcher\IEventDispatcher::class);
        $eventDispatcher->addListener(\OCP\Files\Events\Node\BeforeNodeReadEvent::class, function($event) use ($context) {
            /** @var \OCA\S3ShadowMigrator\Middleware\DownloadInterceptorMiddleware $middleware */
            $middleware = $context->getServerContainer()->get(DownloadInterceptorMiddleware::class);
            $middleware->interceptDownload($event->getNode());
        });

        // Register Storage Wrapper to intercept fopen on sparse files
        \OC\Files\Filesystem::addStorageWrapper(self::APP_ID, function($mountPoint, \OCP\Files\Storage\IStorage $storage) {
            // Only wrap Local storage
            if ($storage->instanceOfStorage(\OC\Files\Storage\Local::class) || $storage->instanceOfStorage(\OC\Files\Storage\Home::class)) {
                return new \OCA\S3ShadowMigrator\Storage\S3ShadowStorageWrapper(['storage' => $storage]);
            }
            return $storage;
        });
        
        // Register frontend scripts globally to ensure it loads
        \OCP\Util::addScript(self::APP_ID, 'fileactions');
    }
}
