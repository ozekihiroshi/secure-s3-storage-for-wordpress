<?php

// Run only with WordPress loaded in the isolated local ZIP-test environment.
// A session-local TEMPORARY table receives all writes; real options are untouched.
namespace SecureS3StorageForWordpress\WordPress {
    // Record invalidations without modifying the site's object cache during tests.
    function wp_cache_delete($key, $group): bool
    {
        $GLOBALS['odbfs3_jobtest_cache_deletes'][] = [$key, $group];
        return true;
    }
}

namespace {
    use SecureS3StorageForWordpress\Backup\Job\BackupJob;
    use SecureS3StorageForWordpress\WordPress\WordPressJobStore;

    if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
        fwrite(STDERR, "Load WordPress before this integration test.\n");
        exit(1);
    }

    require_once __DIR__ . '/../../src/Backup/Job/JobStore.php';
    require_once __DIR__ . '/../../src/Backup/Job/BackupJob.php';
    require_once __DIR__ . '/../../src/WordPress/WordPressJobStore.php';

    $database = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $table = 'odbfs3_jobtest_' . bin2hex(random_bytes(8));
    $checks = 0;
    $check = static function (bool $condition, string $message) use (&$checks): void {
        if (! $condition) { throw new \RuntimeException($message); }
        ++$checks;
    };

    try {
        $check(
            $database->query("CREATE TEMPORARY TABLE `$table` LIKE `{$wpdb->options}`") !== false,
            'Create isolated fixture.',
        );
        $database->options = $table;
        $first = new WordPressJobStore($database);
        $second = new WordPressJobStore($database);
        $check($first->read() === null, 'Empty slot.');
        $record = (new BackupJob(str_repeat('a', 32), 'media'))->encode();
        $check($first->compareAndSwap(null, $record), 'First insert.');
        $check(! $second->compareAndSwap(null, $record), 'Duplicate insert rejected.');
        $check($second->read() === $record, 'Fresh DB read.');
        $autoload = $database->get_var($database->prepare(
            "SELECT autoload FROM `$table` WHERE option_name = %s",
            WordPressJobStore::OPTION_NAME,
        ));
        $check($autoload === 'no', 'Never autoload job metadata.');
        $claim = BackupJob::decode($record)->claim(time(), 60)->encode();
        $check($first->compareAndSwap($record, $claim), 'Claim persists.');
        $check(! $second->compareAndSwap($record, $claim), 'Stale claim rejected.');
        $check($second->read() === $claim, 'No stale cache.');
        $failed = BackupJob::decode($claim)->fail('step_failed')->encode();
        $check(! $second->compareAndSwap(strtoupper($claim), $failed), 'Case-sensitive comparison.');
        $check(! $second->compareAndSwap($claim . ' ', $failed), 'Trailing-space comparison.');
        $check($second->compareAndSwap($claim, $failed), 'Exact match writes.');
        $check(! $first->compareAndSwap($claim, $record), 'Old worker cannot overwrite completion.');
        $check((new WordPressJobStore($database))->read() === $failed, 'Reconstructed store persists.');
        $check(count($GLOBALS['odbfs3_jobtest_cache_deletes']) === 6, 'Only successful writes invalidate caches.');
        $check($wpdb->options !== $database->options, 'Site options table unchanged.');
        echo "WordPress job store verification: OK ($checks checks)\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, 'WordPress job store verification failed: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    } finally {
        // TEMPORARY keyword prevents accidental removal of a real site table.
        $database->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    }
}
