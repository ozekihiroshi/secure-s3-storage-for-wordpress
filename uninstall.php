<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$secure_s3_storage_autoload =
    __DIR__
    . '/vendor/autoload.php';

if (! file_exists($secure_s3_storage_autoload)) {
    return;
}

require_once $secure_s3_storage_autoload;

SecureS3StorageForWordpress\Lifecycle\PluginLifecycle::uninstall();
