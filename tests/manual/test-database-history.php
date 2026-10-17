<?php

require __DIR__ . '/../../vendor/autoload.php';

// Minimal read-only WordPress presentation stubs; no site is loaded.
function __(string $message, string $domain): string { return $message; }
function esc_html(string $message): string { return htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); }
function esc_html__(string $message, string $domain): string { return esc_html($message); }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['fixture_history']; }
function wp_timezone(): DateTimeZone { return new DateTimeZone('UTC'); }
function size_format(int $size): string { return (string) $size; }

$base = ['success' => true, 'createdAt' => '2026-10-17T00:00:00Z', 'databaseName' => '<script>bad</script>', 'backend' => 'php', 'sizeBytes' => 42];
$GLOBALS['fixture_history'] = [
    $base + ['sha256' => str_repeat('a', 64), 'verification' => 'remote_integrity'],
    $base,
    $base + ['sha256' => '<script>bad</script>', 'verification' => 'remote_integrity'],
];
ob_start();
(new ReflectionMethod(\SecureS3StorageForWordpress\Admin\SettingsPage::class, 'render_backup_history'))
    ->invoke(new \SecureS3StorageForWordpress\Admin\SettingsPage());
$html = ob_get_clean();
if (substr_count($html, 'S3 integrity verified; restore not tested.') !== 1
    || substr_count($html, 'Not verified.') !== 2
    || ! str_contains($html, str_repeat('a', 64)) || str_contains($html, '<script>')) {
    throw new RuntimeException('History verification labels or escaping failed.');
}
echo "PASS database history: new evidence, legacy status, invalid evidence, escaped content\n";
