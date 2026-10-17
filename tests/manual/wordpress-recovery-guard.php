<?php

// Test-only MU plugin, supplied separately from backup material.
add_filter('pre_http_request', static fn () => new WP_Error('fixture_network_blocked', 'External HTTP disabled.'), PHP_INT_MIN);
add_filter('pre_wp_mail', static fn () => false, PHP_INT_MIN);
add_filter('automatic_updater_disabled', '__return_true');
add_filter('redirect_canonical', '__return_false');
add_action('odbfs3_fixture_cron', static function (): void { throw new RuntimeException('Fixture Cron must not run.'); });
