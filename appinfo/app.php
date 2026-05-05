<?php

declare(strict_types=1);

$appManager = \OC::$server->getAppManager();
$appManager->registerApp('s3shadowmigrator');

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Note: Bootstrapping and middleware registration will be moved to lib/AppInfo/Application.php
// which implements IBootstrap.
