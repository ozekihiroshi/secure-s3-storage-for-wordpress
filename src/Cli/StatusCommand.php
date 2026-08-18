<?php

namespace SecureS3StorageForWordpress\Cli;

use SecureS3StorageForWordpress\Backup\BackupService;
use SecureS3StorageForWordpress\Backup\History\BackupHistoryRepository;
use SecureS3StorageForWordpress\Backup\Retention\RetentionSetting;
use SecureS3StorageForWordpress\Cron\BackupScheduleManager;
use Throwable;
use WP_CLI;

final class StatusCommand
{
    private const OPTION_NAME =
        'secure_s3_storage_settings';

    /**
     * Show Ozeki Database Backup for S3 backup status.
     *
     * ## EXAMPLES
     *
     *     wp ozeki-database-backup-for-s3 status
     *
     * @when after_wp_load
     */
    public function __invoke(
        array $args,
        array $assocArgs
    ): void {
        $options =
            $this->getOptions();

        $schedule =
            $this->getScheduleLabel(
                $options
            );

        $nextBackup =
            $this->getNextBackupLabel();

        $retention =
            $this->getRetentionLabel(
                $options
            );

        $lastSuccessfulBackup =
            $this->getLastSuccessfulBackupLabel();

        $backend =
            $this->getBackendLabel();

        WP_CLI::log(
            sprintf(
                /* translators: %s: configured backup schedule. */
                __(
                    'Schedule: %s',
                    'ozeki-database-backup-for-s3'
                ),
                $schedule
            )
        );

        WP_CLI::log(
            sprintf(
                /* translators: %s: date and time of the next scheduled backup. */
                __(
                    'Next backup: %s',
                    'ozeki-database-backup-for-s3'
                ),
                $nextBackup
            )
        );

        WP_CLI::log(
            sprintf(
                /* translators: %s: configured backup retention policy. */
                __(
                    'Retention: %s',
                    'ozeki-database-backup-for-s3'
                ),
                $retention
            )
        );

        WP_CLI::log(
            sprintf(
                /* translators: %s: date and time of the last successful backup. */
                __(
                    'Last successful backup: %s',
                    'ozeki-database-backup-for-s3'
                ),
                $lastSuccessfulBackup
            )
        );

        WP_CLI::log(
            sprintf(
                /* translators: %s: database backup backend name. */
                __(
                    'Backend: %s',
                    'ozeki-database-backup-for-s3'
                ),
                $backend
            )
        );
    }

    private function getScheduleLabel(
        array $options
    ): string {
        $schedule =
            $options['backup_schedule']
            ?? 'disabled';

        return $schedule === 'daily'
            ? __(
                'Daily',
                'ozeki-database-backup-for-s3'
            )
            : __(
                'Disabled',
                'ozeki-database-backup-for-s3'
            );
    }

    private function getNextBackupLabel(): string
    {
        $manager =
            new BackupScheduleManager();

        $timestamp =
            $manager->getNextScheduledTimestamp();

        if ($timestamp === null) {
            return __(
                'None',
                'ozeki-database-backup-for-s3'
            );
        }

        $formatted =
            wp_date(
                'Y-m-d H:i T',
                $timestamp
            );

        return $formatted === false
            ? __(
                'Unknown',
                'ozeki-database-backup-for-s3'
            )
            : $formatted;
    }

    private function getRetentionLabel(
        array $options
    ): string {
        $keepCount = RetentionSetting::normalize(
            $options['retention_keep_count']
            ?? RetentionSetting::DISABLED
        );

        if ($keepCount === RetentionSetting::DISABLED) {
            return __(
                'Disabled',
                'ozeki-database-backup-for-s3'
            );
        }

        return sprintf(
            /* translators: %d: number of backups to keep. */
            __(
                'Keep last %d',
                'ozeki-database-backup-for-s3'
            ),
            $keepCount
        );
    }

    private function getLastSuccessfulBackupLabel(): string
    {
        $repository =
            new BackupHistoryRepository();

        $history =
            $repository->all();

        foreach ($history as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (empty($entry['success'])) {
                continue;
            }

            $createdAt =
                isset($entry['createdAt'])
                    ? (string) $entry['createdAt']
                    : '';

            if ($createdAt === '') {
                continue;
            }

            try {
                $date =
                    new \DateTimeImmutable(
                        $createdAt
                    );

                return $date
                    ->setTimezone(
                        wp_timezone()
                    )
                    ->format(
                        'Y-m-d H:i T'
                    );
            } catch (Throwable $e) {
                return __(
                    'Unknown',
                    'ozeki-database-backup-for-s3'
                );
            }
        }

        return __(
            'None',
            'ozeki-database-backup-for-s3'
        );
    }

    private function getBackendLabel(): string
    {
        try {
            $backupService =
                new BackupService();

            return $backupService
                ->getSelectedBackendName();
        } catch (Throwable $e) {
            return __(
                'Unknown',
                'ozeki-database-backup-for-s3'
            );
        }
    }

    private function getOptions(): array
    {
        $options =
            get_option(
                self::OPTION_NAME,
                []
            );

        return is_array($options)
            ? $options
            : [];
    }
}