<?php

namespace SecureS3StorageForWordpress\Cron;

use RuntimeException;

final class BackupScheduleManager
{
    public const HOOK =
        'secure_s3_storage_database_backup_cron';

    private const RECURRENCE = 'daily';

    public function isScheduled(): bool
    {
        return wp_next_scheduled(self::HOOK) !== false;
    }

    public function getNextScheduledTimestamp(): ?int
    {
        $timestamp = wp_next_scheduled(self::HOOK);

        return $timestamp === false
            ? null
            : (int) $timestamp;
    }

    public function scheduleDaily(): void
    {
        if ($this->isScheduled()) {
            return;
        }

        $scheduled = wp_schedule_event(
            time(),
            self::RECURRENCE,
            self::HOOK
        );

        if ($scheduled === false) {
            throw new RuntimeException(
                'Unable to schedule automatic database backup.'
            );
        }
    }

    public function unschedule(): void
    {
        $result = wp_clear_scheduled_hook(
            self::HOOK
        );

        if ($result === false) {
            throw new RuntimeException(
                'Unable to clear automatic database backup schedule.'
            );
        }
    }
}