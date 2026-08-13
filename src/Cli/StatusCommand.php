<?php

namespace SecureS3StorageForWordpress\Cli;

use SecureS3StorageForWordpress\Backup\BackupService;
use SecureS3StorageForWordpress\Backup\History\BackupHistoryRepository;
use SecureS3StorageForWordpress\Cron\BackupScheduleManager;
use Throwable;
use WP_CLI;

final class StatusCommand
{
    private const OPTION_NAME =
        'secure_s3_storage_settings';

    /**
     * Show Secure S3 Storage backup status.
     *
     * ## EXAMPLES
     *
     *     wp secure-s3-storage status
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
            'Schedule: ' . $schedule
        );

        WP_CLI::log(
            'Next backup: ' . $nextBackup
        );

        WP_CLI::log(
            'Retention: ' . $retention
        );

        WP_CLI::log(
            'Last successful backup: '
            . $lastSuccessfulBackup
        );

        WP_CLI::log(
            'Backend: ' . $backend
        );
    }

    private function getScheduleLabel(
        array $options
    ): string {
        $schedule =
            $options['backup_schedule']
            ?? 'disabled';

        return $schedule === 'daily'
            ? 'Daily'
            : 'Disabled';
    }

    private function getNextBackupLabel(): string
    {
        $manager =
            new BackupScheduleManager();

        $timestamp =
            $manager->getNextScheduledTimestamp();

        if ($timestamp === null) {
            return 'None';
        }

        $formatted =
            wp_date(
                'Y-m-d H:i T',
                $timestamp
            );

        return $formatted === false
            ? 'Unknown'
            : $formatted;
    }

    private function getRetentionLabel(
        array $options
    ): string {
        $value =
            $options['retention_keep_count']
            ?? 0;

        if (! is_numeric($value)) {
            return 'Disabled';
        }

        $keepCount =
            (int) $value;

        if (
            ! in_array(
                $keepCount,
                [7, 14, 30],
                true
            )
        ) {
            return 'Disabled';
        }

        return sprintf(
            'Keep last %d',
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
                return 'Unknown';
            }
        }

        return 'None';
    }

    private function getBackendLabel(): string
    {
        try {
            $backupService =
                new BackupService();

            return $backupService
                ->getSelectedBackendName();
        } catch (Throwable $e) {
            return 'Unknown';
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