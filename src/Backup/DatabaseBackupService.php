<?php

namespace SecureS3StorageForWordpress\Backup;

use RuntimeException;
use SecureS3StorageForWordpress\Aws\S3Storage;
use SecureS3StorageForWordpress\Backup\Compression\Compressor;
use SecureS3StorageForWordpress\Backup\Database\DatabaseConnection;
use Throwable;

final class DatabaseBackupService
{
    public function __construct(
        private BackupService $backupService,
        private Compressor $compressor,
        private S3Storage $storage,
    ) {
    }

    public function backup(
        DatabaseConnection $connection,
        string $bucket,
        string $prefix
    ): DatabaseBackupResult {
        $dumpPath = null;
        $compressedPath = null;

        try {
            $dumpResult = $this->backupService->backupDatabase(
                $connection
            );

            $dumpPath = $dumpResult->getPath();

            $compressedResult = $this->compressor->compress(
                $dumpPath
            );

            $compressedPath = $compressedResult->getPath();

            $key = $this->buildBackupKey(
                $prefix,
                $connection->getDatabaseName()
            );

            $uploadResult = $this->storage->upload(
                $compressedPath,
                $bucket,
                $key
            );

            return new DatabaseBackupResult(
                bucket: $uploadResult->getBucket(),
                key: $uploadResult->getKey(),
                sizeBytes: $uploadResult->getSizeBytes(),
                databaseName: $dumpResult->getDatabaseName(),
                backend: $this->backupService->getSelectedBackendName(),
                createdAt: $dumpResult->getCreatedAt(),
                etag: $uploadResult->getEtag(),
            );

        } catch (Throwable $e) {
            throw new RuntimeException(
                'Database backup failed.',
                0,
                $e
            );

        } finally {
            if (
                $compressedPath !== null
                && is_file($compressedPath)
            ) {
                @unlink($compressedPath);
            }

            if (
                $dumpPath !== null
                && is_file($dumpPath)
            ) {
                @unlink($dumpPath);
            }
        }
    }

    private function buildBackupKey(
        string $prefix,
        string $databaseName
    ): string {
        $prefix = trim($prefix, '/');

        $safeDatabaseName = preg_replace(
            '/[^A-Za-z0-9._-]/',
            '_',
            $databaseName
        );

        if ($safeDatabaseName === null || $safeDatabaseName === '') {
            $safeDatabaseName = 'database';
        }

        $datePath = gmdate('Y/m/d');
        $timestamp = gmdate('Ymd-His');

        $filename = sprintf(
            'db-%s-%s.sql.gz',
            $safeDatabaseName,
            $timestamp
        );

        $parts = array_filter([
            $prefix,
            'backups',
            'database',
            $datePath,
            $filename,
        ]);

        return implode('/', $parts);
    }
}