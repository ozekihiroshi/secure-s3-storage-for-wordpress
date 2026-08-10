<?php
/**
 * Plugin Name: Secure S3 Storage
 * Description: Security-focused Amazon S3 storage plugin for WordPress.
 * Version: 0.1.0
 * Author: Hiroshi Ozeki
 * License: GPL-2.0-or-later
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $autoload ) ) {
    require_once $autoload;
}
