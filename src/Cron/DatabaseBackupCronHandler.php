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
        $options = $this->getOptions();

        $region = $options['region'] ?? '';
        $bucket = $options['bucket'] ?? '';
        $prefix = $options['prefix'] ?? '';

        $databaseName = defined('DB_NAME')
            ? (string) DB_NAME
            : 'unknown';

        $backend = 'unknown';

        if ($region === '' || $bucket === '') {
            $this->recordFailure(
                databaseName: $databaseName,
                backend: $backend,
                message: 'Automatic backup skipped because S3 configuration is incomplete.'
            );

            return;
        }

        try {
            $connectionFactory =
                new WordPressDatabaseConnectionFactory();

            $databaseConnection =
                $connectionFactory->create();

            $databaseName =
                $databaseConnection->getDatabaseName();

            $backupService =
                new BackupService();

            $backend =
                $backupService->getSelectedBackendName();

            $clientFactory =
                new S3ClientFactory();

            $client =
                $clientFactory->create($region);

            $storage =
                new S3Storage($client);

            $compressor =
                new GzipCompressor();

            $databaseBackupService =
                new DatabaseBackupService(
                    $backupService,
                    $compressor,
                    $storage
                );

            $result =
                $databaseBackupService->backup(
                    $databaseConnection,
                    $bucket,
                    $prefix
                );

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
                        'Automatic backup completed successfully.'
                )
            );
        } catch (Throwable $e) {
            /*
             * Never expose exception details here.
             *
             * They may contain database connection information,
             * filesystem paths, SQL fragments, AWS details,
             * or other sensitive operational information.
             */
            $this->recordFailure(
                databaseName: $databaseName,
                backend: $backend,
                message: 'Automatic database backup failed.'
            );
        }
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
                databaseName: $databaseName,
                backend: $backend,
                message: $message
            )
        );
    }

    private function getOptions(): array
    {
        $options = get_option(
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