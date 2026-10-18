<?php
// Allow independent CLI tools and WordPress; reject direct web execution.
if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}
// Version-scoped package: no WordPress hooks, runtime downloads or global aliases.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Ozeki\\BackupCore\\V0_1_0\\';
    if (!str_starts_with($class, $prefix)) { return; }
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z0-9_\\\\]+$/D', $relative)) { return; }
    $path = __DIR__.'/src/'.str_replace('\\', '/', $relative).'.php';
    if (is_file($path)) { require_once $path; }
});
