<?php
/**
 * Plugin Name: Ozeki Database Backup for S3
 * Description: Create secure, gzip-compressed WordPress database backups and store them in Amazon S3.
 * Version: 0.1.1
 * Author: Hiroshi Ozeki
 * License: GPL-2.0-or-later
 * Requires at least: 5.9
 * Requires PHP: 8.1
 * Text Domain: ozeki-database-backup-for-s3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$secure_s3_storage_autoload = __DIR__ . '/vendor/autoload.php';

if ( ! file_exists( $secure_s3_storage_autoload ) ) {
    return;
}

require_once $secure_s3_storage_autoload;

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