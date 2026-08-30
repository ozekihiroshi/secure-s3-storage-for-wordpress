<?php

// Docker-only integration-test bootstrap, never included in release ZIPs.
// Prevent bootstrap/shutdown from dispatching unrelated scheduled site jobs.
define('DISABLE_WP_CRON', true);
require '/var/www/html/wp-load.php';
