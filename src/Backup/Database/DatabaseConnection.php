<?php
// Allow independent CLI tools and WordPress; reject direct web execution.
if (!defined('ABSPATH')) {
    if (PHP_SAPI !== 'cli') {
        exit;
    }
}
// Compatibility alias. Do not duplicate the shared implementation.
require_once __DIR__.'/../shared-core-compat.php';
