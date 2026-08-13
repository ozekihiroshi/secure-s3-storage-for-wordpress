<?php

namespace SecureS3StorageForWordpress\Cron;

use DateTimeImmutable;
use SecureS3StorageForWordpress\Aws\S3ClientFactory;
use SecureS3StorageForWordpress\Aws\S3Storage;
use SecureS3StorageForWordpress\Backup\BackupService;
use SecureS3StorageForWordpress\Backup\Compression\GzipCompressor;
use SecureS3StorageForWordpress\Backup\DatabaseBackupService;
use SecureS3StorageForWordpress\Backup\History\BackupHistoryEntry;
use SecureS3StorageForWordpress\Backup\History\BackupHistoryRepository;
use SecureS3StorageForWordpress\Backup\Retention\RetentionPolicy;
use SecureS3StorageForWordpress\Backup\Retention\S3BackupRetentionManager;
use SecureS3StorageForWordpress\WordPress\WordPressDatabaseConnectionFactory;
use Throwable;

final class DatabaseBackupCronHandler
{
    private const OPTION_NAME =
        'secure_s3_storage_settings';

    private const LOCK_KEY =
        'secure_s3_storage_database_backup_cron_lock';

    private const LOCK_TTL =
        30 * MINUTE_IN_SECONDS;

    private const RETENTION_DISABLED =
        0;

    public function register(): void
    {
        add_action(
            BackupScheduleManager::HOOK,
            [$this, 'handle']
        );
    }

    public function handle(): void
    {
        if ($this->isLocked()) {
            return;
        }

        $this->acquireLock();

        try {
            $this->runBackup();
        } finally {
            $this->releaseLock();
        }
    }

    private function runBackup(): void
    {
        $options =
            $this->getOptions();

        $region =
            $options['region'] ?? '';

        $bucket =
            $options['bucket'] ?? '';

        $prefix =
            $options['prefix'] ?? '';

        $retentionKeepCount =
            $this->getRetentionKeepCount(
                $options
            );

        $databaseName =
            defined('DB_NAME')
                ? (string) DB_NAME
                : 'unknown';

        $backend = 'unknown';

        if (
            $region === ''
            || $bucket === ''
        ) {
            $this->recordFailure(
                databaseName: $databaseName,
                backend: $backend,
                message: __(
                    'Automatic backup skipped because S3 configuration is incomplete.',
                    'secure-s3-storage'
                )
            );

            return;
        }

        try {
            $connectionFactory =
                new WordPressDatabaseConnectionFactory();

            $databaseConnection =
                $connectionFactory->create();

            $databaseName =
                $databaseConnection
                    ->getDatabaseName();

            $backupService =
                new BackupService();

            $backend =
                $backupService
                    ->getSelectedBackendName();

            $clientFactory =
                new S3ClientFactory();

            $client =
                $clientFactory->create(
                    $region
                );

            $storage =
                new S3Storage(
                    $client
                );

            $compressor =
                new GzipCompressor();

            $databaseBackupService =
                new DatabaseBackupService(
                    $backupService,
                    $compressor,
                    $storage
                );

            /*
             * The database backup must complete successfully
             * before retention is allowed to run.
             */
            $result =
                $databaseBackupService->backup(
                    $databaseConnection,
                    $bucket,
                    $prefix
                );

            $retentionMessage =
                $this->runRetentionAfterBackup(
                    client: $client,
                    bucket: $bucket,
                    prefix: $prefix,
                    keepCount:
                        $retentionKeepCount
                );

            $message =
                __(
                    'Automatic backup completed successfully.',
                    'secure-s3-storage'
                );

            if ($retentionMessage !== '') {
                $message .= ' '
                    . $retentionMessage;
            }

            $history =
                new BackupHistoryRepository();

            $history->add(
                new BackupHistoryEntry(
                    success: true,
                    createdAt: new DateTimeImmutable(
                        'now',
                        wp_timezone()
                    ),
                    databaseName:
                        $result->getDatabaseName(),
                    backend:
                        $result->getBackend(),
                    bucket:
                        $result->getBucket(),
                    key:
                        $result->getKey(),
                    sizeBytes:
                        $result->getSizeBytes(),
                    message:
                        $message
                )
            );
        } catch (Throwable $e) {
            /*
             * Never expose database credentials,
             * AWS credentials, raw SQL, filesystem paths,
             * commands, or SDK exception details.
             */
            $this->recordFailure(
                databaseName: $databaseName,
                backend: $backend,
                message: __(
                    'Automatic database backup failed.',
                    'secure-s3-storage'
                )
            );
        }
    }

    private function runRetentionAfterBackup(
        object $client,
        string $bucket,
        string $prefix,
        int $keepCount
    ): string {
        if (
            $keepCount
            === self::RETENTION_DISABLED
        ) {
            return '';
        }

        try {
            $manager =
                new S3BackupRetentionManager(
                    $client
                );

            $policy =
                new RetentionPolicy(
                    $keepCount
                );

            $candidates =
                $manager
                    ->findDeletionCandidates(
                        $bucket,
                        $prefix,
                        $policy
                    );

            if ($candidates === []) {
                return sprintf(
                    /* translators: %d: number of backups to keep. */
                    __(
                        'Retention: keeping the latest %d backups; no old backups required deletion.',
                        'secure-s3-storage'
                    ),
                    $keepCount
                );
            }

            $deleteResult =
                $manager->deleteCandidates(
                    $candidates
                );

            return sprintf(
                /* translators: 1: number of deleted backups, 2: number of backups to keep. */
                __(
                    'Retention: deleted %1$d old backup(s), keeping the latest %2$d.',
                    'secure-s3-storage'
                ),
                $deleteResult
                    ->getDeletedCount(),
                $keepCount
            );
        } catch (Throwable $e) {
            /*
             * Retention failure must not change a successful
             * database backup into a failed backup.
             */
            return __(
                'Retention cleanup failed; the new backup was preserved.',
                'secure-s3-storage'
            );
        }
    }

    private function getRetentionKeepCount(
        array $options
    ): int {
        $value =
            $options['retention_keep_count']
            ?? self::RETENTION_DISABLED;

        if (! is_numeric($value)) {
            return self::RETENTION_DISABLED;
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
            return self::RETENTION_DISABLED;
        }

        return $keepCount;
    }

    private function recordFailure(
        string $databaseName,
        string $backend,
        string $message
    ): void {
        $history =
            new BackupHistoryRepository();

        $history->add(
            new BackupHistoryEntry(
                success: false,
                createdAt: new DateTimeImmutable(
                    'now',
                    wp_timezone()
                ),
                databaseName:
                    $databaseName,
                backend:
                    $backend,
                message:
                    $message
            )
        );
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

    private function isLocked(): bool
    {
        return get_transient(
            self::LOCK_KEY
        ) !== false;
    }

    private function acquireLock(): void
    {
        set_transient(
            self::LOCK_KEY,
            '1',
            self::LOCK_TTL
        );
    }

    private function releaseLock(): void
    {
        delete_transient(
            self::LOCK_KEY
        );
    }
}