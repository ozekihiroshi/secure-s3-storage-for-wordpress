<?php

namespace SecureS3StorageForWordpress\Cron;

use RuntimeException;

final class BackupScheduleManager
{
    public const HOOK =
        'secure_s3_storage_database_backup_cron';

    private const RECURRENCE =
        'daily';

    private const DAILY_RUN_HOUR =
        3;

    public function isScheduled(): bool
    {
        return wp_next_scheduled(
            self::HOOK
        ) !== false;
    }

    public function getNextScheduledTimestamp(): ?int
    {
        $timestamp =
            wp_next_scheduled(
                self::HOOK
            );

        return $timestamp === false
            ? null
            : (int) $timestamp;
    }

    public function scheduleDaily(): void
    {
        if ($this->isScheduled()) {
            return;
        }

        $timestamp =
            $this->getNextDailyRunTimestamp();

        $result =
            wp_schedule_event(
                $timestamp,
                self::RECURRENCE,
                self::HOOK,
                [],
                true
            );

        if (is_wp_error($result)) {
            throw new RuntimeException(
                __(
                    'Unable to schedule automatic database backup.',
                    'secure-s3-storage'
                )
            );
        }

        if ($result !== true) {
            throw new RuntimeException(
                __(
                    'Unable to schedule automatic database backup.',
                    'secure-s3-storage'
                )
            );
        }
    }

    public function unschedule(): void
    {
        $result =
            wp_clear_scheduled_hook(
                self::HOOK,
                [],
                true
            );

        if (is_wp_error($result)) {
            throw new RuntimeException(
                __(
                    'Unable to clear automatic database backup schedule.',
                    'secure-s3-storage'
                )
            );
        }

        if ($result === false) {
            throw new RuntimeException(
                __(
                    'Unable to clear automatic database backup schedule.',
                    'secure-s3-storage'
                )
            );
        }
    }

    private function getNextDailyRunTimestamp(): int
    {
        $now =
            current_datetime();

        $nextRun =
            $now->setTime(
                self::DAILY_RUN_HOUR,
                0,
                0
            );

        if ($nextRun <= $now) {
            $nextRun =
                $nextRun->modify(
                    '+1 day'
                );
        }

        return $nextRun->getTimestamp();
    }
}