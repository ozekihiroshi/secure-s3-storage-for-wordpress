<?php

namespace SecureS3StorageForWordpress\Lifecycle;

use SecureS3StorageForWordpress\Cron\BackupScheduleManager;
use Throwable;

final class PluginLifecycle
{
    private const SETTINGS_OPTION =
        'secure_s3_storage_settings';

    private const HISTORY_OPTION =
        'secure_s3_storage_backup_history';

    private const CRON_LOCK_TRANSIENT =
        'secure_s3_storage_database_backup_cron_lock';

    private const BACKUP_SCHEDULE_DAILY =
        'daily';

    public static function activate(): void
    {
        $options =
            get_option(
                self::SETTINGS_OPTION,
                []
            );

        if (! is_array($options)) {
            return;
        }

        $schedule =
            $options['backup_schedule']
            ?? '';

        if (
            $schedule
            !== self::BACKUP_SCHEDULE_DAILY
        ) {
            return;
        }

        try {
            $manager =
                new BackupScheduleManager();

            $manager->scheduleDaily();
        } catch (Throwable $e) {
            // Activation must not fail because
            // scheduling could not be created.
        }
    }

    public static function deactivate(): void
    {
        try {
            $manager =
                new BackupScheduleManager();

            $manager->unschedule();
        } catch (Throwable $e) {
            // Deactivation should continue even
            // if the Cron entry cannot be cleared.
        }

        delete_transient(
            self::CRON_LOCK_TRANSIENT
        );
    }

    public static function uninstall(): void
    {
        delete_option(
            self::SETTINGS_OPTION
        );

        delete_option(
            self::HISTORY_OPTION
        );

        delete_transient(
            self::CRON_LOCK_TRANSIENT
        );

        wp_clear_scheduled_hook(
            BackupScheduleManager::HOOK
        );
    }
}