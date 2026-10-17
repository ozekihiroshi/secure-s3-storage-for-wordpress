<?php
// Local rollback snapshot and read-only deployment checks. Never sends data to S3.
if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['snapshot', 'verify'], true)) { exit(2); }
$work = getenv('ODBFS3_UPDATE_WORK');
if (!is_string($work) || !preg_match('#^/tmp/odbfs3-update-[a-f0-9]{12}$#D', $work)
    || !is_dir($work) || is_link($work)) { throw new RuntimeException('Private deployment workspace required.'); }
umask(0077);
define('DISABLE_WP_CRON', true);
require '/var/www/html/wp-load.php';
if (get_option('home') !== 'https://wp.ceri.link') { throw new RuntimeException('Unexpected site.'); }
$plugin = 'ozeki-database-backup-for-s3/ozeki-database-backup-for-s3.php';
if (!in_array($plugin, get_option('active_plugins'), true)) { throw new RuntimeException('Target plugin is not active.'); }
if (get_transient('secure_s3_storage_database_backup_cron_lock')) { throw new RuntimeException('Database backup is active.'); }
$job = get_option('secure_s3_storage_background_job', null);
if (is_string($job)) {
    $decoded = SecureS3StorageForWordpress\Backup\Job\BackupJob::decode($job);
    if (!$decoded->terminal()) { throw new RuntimeException('Media backup is pending or active.'); }
}
$state = [];
foreach (['active_plugins', 'secure_s3_storage_settings', 'secure_s3_storage_backup_history',
    'secure_s3_storage_background_job'] as $name) { $state[$name] = get_option($name, null); }
$state['plugin_cron'] = [];
foreach (_get_cron_array() as $timestamp => $hooks) {
    foreach ($hooks as $hook => $events) {
        if (str_starts_with($hook, 'secure_s3_storage_')) { $state['plugin_cron'][$timestamp][$hook] = $events; }
    }
}
$serialized = serialize($state);
$path = $work . '/options.serialized';
if ($argv[1] === 'snapshot') {
    if (file_exists($path)) { throw new RuntimeException('Snapshot already exists.'); }
    $connection = (new SecureS3StorageForWordpress\WordPress\WordPressDatabaseConnectionFactory())->create();
    $dump = (new SecureS3StorageForWordpress\Backup\BackupService())->backupDatabase($connection);
    try {
        if (!copy($dump->getPath(), $work . '/before.sql')) { throw new RuntimeException('Snapshot copy failed.'); }
        chmod($work . '/before.sql', 0600);
    } finally { unlink($dump->getPath()); }
    file_put_contents($path, $serialized);
} elseif (!hash_equals(hash_file('sha256', $path), hash('sha256', $serialized))) {
    throw new RuntimeException('Settings, history, active plugins, job state or plugin Cron changed.');
}
$header = get_file_data(WP_PLUGIN_DIR . '/' . $plugin, ['version' => 'Version']);
if ($argv[1] === 'verify' && $header['version'] !== '0.3.0') { throw new RuntimeException('Wrong deployed version.'); }
if ($argv[1] === 'verify' && !class_exists(SecureS3StorageForWordpress\Backup\Recovery\RecoveryPublisher::class)) {
    throw new RuntimeException('Recovery code cannot autoload.');
}
echo json_encode(['mode' => $argv[1], 'version' => $header['version'], 'state_sha256' => hash('sha256', $serialized),
    'db_snapshot_bytes' => filesize($work . '/before.sql'), 'db_snapshot_sha256' => hash_file('sha256', $work . '/before.sql')]), PHP_EOL;
