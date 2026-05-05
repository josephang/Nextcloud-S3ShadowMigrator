<?xml version="1.0"?>
<?php

declare(strict_types=1);

$appManager = \OC::$server->getAppManager();
$appManager->registerApp('s3shadowmigrator');

// Note: Bootstrapping and middleware registration will be moved to lib/AppInfo/Application.php
// which implements IBootstrap.
