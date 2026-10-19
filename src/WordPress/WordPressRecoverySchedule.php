<?php

declare(strict_types=1);

namespace SecureS3StorageForWordpress\WordPress;

use Closure;
use wpdb;
use SecureS3StorageForWordpress\Cron\RecoverySetCronHandler;

/** Explicit integration only; never changes the existing database Cron or retention. */
final class WordPressRecoverySchedule
{
    private static array $registered = [];

    /** Register on each WordPress bootstrap. Scheduling/enabling remains explicit. */
    public static function register(wpdb $database, array $configuration, Closure $adapters, Closure $report): string
    {
        $handler = self::create($database, $configuration, $adapters);
        $hook = 'odbfs3_recovery_tick_' . $configuration['source_id'];
        if (isset(self::$registered[$hook])) { throw new \RuntimeException('Recovery schedule already registered.'); }
        add_action($hook, static function() use ($handler, $report): void {
            $report($handler->handle());
        }, 10, 0);
        self::$registered[$hook] = true;
        return $hook;
    }

    public static function create(wpdb $database, array $configuration, Closure $adapters): RecoverySetCronHandler
    {
        return new RecoverySetCronHandler(
            WordPressJobStore::forRecoverySchedule($database, $configuration['source_id']),
            static fn(string $id): WordPressJobStore => WordPressJobStore::forRecovery($database, $id),
            $adapters,
            $configuration,
            null,
            new \SecureS3StorageForWordpress\Cron\RecoveryExecutionRecord(
                WordPressJobStore::forRecoveryExecution($database,$configuration['source_id']),$configuration['source_id']),
        );
    }
}
