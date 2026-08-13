<?php

namespace SecureS3StorageForWordpress\Cli;

use Aws\S3\S3Client;
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
use WP_CLI;

final class BackupCommand
{
    private const OPTION_NAME =
        'secure_s3_storage_settings';

    /**
     * Create a database backup and upload it to Amazon S3.
     *
     * ## EXAMPLES
     *
     *     wp secure-s3-storage backup
     *
     * @when after_wp_load
     */
    public function __invoke(
        array $args,
        array $assocArgs
    ): void {
        $options =
            $this->getOptions();

        $region =
            $options['region'] ?? '';

        $bucket =
            $options['bucket'] ?? '';

        $prefix =
            $options['prefix'] ?? '';

        if (
            $region === ''
            || $bucket === ''
        ) {
            WP_CLI::error(
                'AWS region and S3 bucket are required.'
            );
        }

        $databaseName =
            defined('DB_NAME')
                ? (string) DB_NAME
                : 'unknown';

        $backend =
            'unknown';

        try {
            WP_CLI::log(
                'Starting database backup...'
            );

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

            WP_CLI::log(
                'Backend: ' . $backend
            );

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

            $result =
                $databaseBackupService->backup(
                    $databaseConnection,
                    $bucket,
                    $prefix
                );

            $retentionMessage =
                $this->runRetention(
                    $client,
                    $bucket,
                    $prefix,
                    $options
                );

            $historyMessage =
                'WP-CLI backup completed successfully.';

            if ($retentionMessage !== '') {
                $historyMessage .= ' '
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
                        $historyMessage
                )
            );

            WP_CLI::success(
                sprintf(
                    'Backup completed: s3://%s/%s (%d bytes)',
                    $result->getBucket(),
                    $result->getKey(),
                    $result->getSizeBytes()
                )
            );

            if ($retentionMessage !== '') {
                WP_CLI::log(
                    $retentionMessage
                );
            }
        } catch (Throwable $e) {
            $this->recordFailure(
                $databaseName,
                $backend
            );

            /*
             * Do not expose raw SDK, SQL,
             * credential, or command details.
             */
            WP_CLI::error(
                'Database backup failed.'
            );
        }
    }

    private function runRetention(
        S3Client $client,
        string $bucket,
        string $prefix,
        array $options
    ): string {
        $keepCount =
            $this->getRetentionKeepCount(
                $options
            );

        if ($keepCount === 0) {
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
                    'Retention: keeping the latest %d backups; no old backups required deletion.',
                    $keepCount
                );
            }

            $result =
                $manager->deleteCandidates(
                    $candidates
                );

            return sprintf(
                'Retention: deleted %d old backup(s), keeping the latest %d.',
                $result->getDeletedCount(),
                $keepCount
            );
        } catch (Throwable $e) {
            return
                'Retention cleanup failed; the new backup was preserved.';
        }
    }

    private function getRetentionKeepCount(
        array $options
    ): int {
        $value =
            $options['retention_keep_count']
            ?? 0;

        if (! is_numeric($value)) {
            return 0;
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
            return 0;
        }

        return $keepCount;
    }

    private function recordFailure(
        string $databaseName,
        string $backend
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
                    'WP-CLI database backup failed.'
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
}
