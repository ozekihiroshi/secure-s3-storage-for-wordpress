<?php
/**
 * Plugin Name: Secure S3 Storage
 * Description: Security-focused Amazon S3 storage plugin for WordPress.
 * Version: 0.1.0
 * Author: Hiroshi Ozeki
 * License: GPL-2.0-or-later
 * Requires PHP: 8.1
 * text Domain: secure-s3-storage
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';

if ( ! file_exists( $autoload ) ) {
    return;
}

require_once $autoload;

register_activation_hook(
    __FILE__,
    [
        SecureS3StorageForWordpress\Lifecycle\PluginLifecycle::class,
        'activate',
    ]
);

register_deactivation_hook(
    __FILE__,
    [
        SecureS3StorageForWordpress\Lifecycle\PluginLifecycle::class,
        'deactivate',
    ]
);

$plugin =
    new SecureS3StorageForWordpress\Plugin();

$plugin->run();