<?php
// Test-only bootstrap for isolated WP-CLI. Does not load the plugin's workers.
if (getenv('ODBFS3_STATUS_FIXTURE') !== '1') { throw new RuntimeException('Fixture required'); }
spl_autoload_register(static function (string $class): void {
    $prefix = 'SecureS3StorageForWordpress\\';
    if (str_starts_with($class, $prefix)) {
        require_once __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
(new SecureS3StorageForWordpress\Cli\CommandRegistrar())->register();
