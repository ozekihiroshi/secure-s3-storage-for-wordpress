<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$autoload =
    __DIR__
    . '/vendor/autoload.php';

if (! file_exists($autoload)) {
    return;
}

require_once $autoload;

SecureS3StorageForWordpress\Lifecycle\PluginLifecycle::uninstall();