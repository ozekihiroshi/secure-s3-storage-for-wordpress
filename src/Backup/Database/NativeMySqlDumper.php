<?php

namespace SecureS3StorageForWordpress\Backup\Database;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class NativeMySqlDumper implements DatabaseDumper
{
    /**
     * @var list<string>
     */
    private array $binaryCandidates;

    private ExecutableFinder $executableFinder;

    /**
     * @param list<string> $binaryCandidates
     */
    public function __construct(
        array $binaryCandidates = [
            'mysqldump',
            'mariadb-dump',
        ],
        ?ExecutableFinder $executableFinder = null
    ) {
        $this->binaryCandidates =
            $binaryCandidates;

        $this->executableFinder =
            $executableFinder
            ?? new ExecutableFinder();
    }

    public function dump(
        DatabaseConnection $connection
    ): DumpResult {
        if (! $this->canExecuteProcesses()) {
            throw new RuntimeException(
                'Process execution is unavailable.'
            );
        }

        $binary =
            $this->findDumpBinary();

        $optionFile = null;
        $dumpFile = null;
        $success = false;

        try {
            $optionFile =
                $this->createOptionFile(
                    $connection
                );

            $dumpFile =
                $this->createDumpFilePath(
                    $connection->getDatabaseName()
                );

            $this->runDump(
                $binary,
                $optionFile,
                $dumpFile,
                $connection
            );

            if (! is_file($dumpFile)) {
                throw new RuntimeException(
                    'Database dump file was not created.'
                );
            }

            $size =
                filesize($dumpFile);

            if (
                $size === false
                || $size <= 0
            ) {
                throw new RuntimeException(
                    'Database dump file is empty.'
                );
            }

            $success = true;

            return new DumpResult(
                path: $dumpFile,
                sizeBytes: $size,
                databaseName: $connection->getDatabaseName(),
                engine: 'mysql',
                createdAt: new DateTimeImmutable()
            );
        } finally {
            if (
                $optionFile !== null
                && is_file($optionFile)
            ) {
                // Temporary credential file must be removed directly after use.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                @unlink($optionFile);
            }

            if (
                ! $success
                && $dumpFile !== null
                && is_file($dumpFile)
            ) {
                // Temporary database dump is managed directly by the backup engine.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                @unlink($dumpFile);
            }
        }
    }

    private function findDumpBinary(): string
    {
        foreach (
            $this->binaryCandidates
            as $candidate
        ) {
            $path =
                $this->executableFinder->find(
                    $candidate
                );

            if ($path !== null) {
                return $path;
            }
        }

        throw new RuntimeException(
            'MySQL dump utility was not found.'
        );
    }

    private function createOptionFile(
        DatabaseConnection $connection
    ): string {
        $path =
            tempnam(
                sys_get_temp_dir(),
                'secure-s3-mysql-'
            );

        if ($path === false) {
            throw new RuntimeException(
                'Unable to create temporary MySQL option file.'
            );
        }

        $lines = [
            '[client]',
            'host='
                . $connection->getHost(),
            'port='
                . $connection->getPort(),
            'user='
                . $connection->getUsername(),
            'password='
                . $this->escapeOptionValue(
                    $connection->getPassword()
                ),
        ];

        if ($connection->hasSocket()) {
            $lines[] =
                'socket='
                . $connection->getSocket();
        }

        $content =
            implode(
                PHP_EOL,
                $lines
            )
            . PHP_EOL;

        if (
            file_put_contents(
                $path,
                $content
            ) === false
        ) {
            // Temporary credential file must be removed directly on failure.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            @unlink($path);

            throw new RuntimeException(
                'Unable to write temporary MySQL option file.'
            );
        }

        // Credential file permissions must be restricted directly before use.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
        if (! chmod($path, 0600)) {
            // Temporary credential file must be removed directly on failure.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            @unlink($path);

            throw new RuntimeException(
                'Unable to secure temporary MySQL option file.'
            );
        }

        return $path;
    }

    private function createDumpFilePath(
        string $databaseName
    ): string {
        $safeDatabaseName =
            preg_replace(
                '/[^A-Za-z0-9._-]/',
                '_',
                $databaseName
            );

        $directory =
            sys_get_temp_dir();

        try {
            $suffix =
                bin2hex(
                    random_bytes(8)
                );
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to generate temporary dump filename.'
            );
        }

        return sprintf(
            '%s/secure-s3-dump-%s-%s.sql',
            rtrim(
                $directory,
                DIRECTORY_SEPARATOR
            ),
            $safeDatabaseName
                ?: 'database',
            $suffix
        );
    }

    private function runDump(
        string $binary,
        string $optionFile,
        string $dumpFile,
        DatabaseConnection $connection
    ): void {
        if (! $this->canExecuteProcesses()) {
            throw new RuntimeException(
                'Process execution is unavailable.'
            );
        }

        $command = [
            $binary,
            '--defaults-extra-file='
                . $optionFile,
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--default-character-set=utf8mb4',
            $connection->getDatabaseName(),
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $dumpFile, 'w'],
            2 => ['pipe', 'w'],
        ];

        try {
            $process =
                // Native database backup requires direct process execution.
                // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found
                proc_open(
                    $command,
                    $descriptors,
                    $pipes
                );
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to start database dump process.',
                0,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is chained, not output.
                $e
            );
        }

        if (! is_resource($process)) {
            throw new RuntimeException(
                'Unable to start database dump process.'
            );
        }

        if (
            isset($pipes[0])
            && is_resource($pipes[0])
        ) {
            // Process pipe must be closed directly before waiting for the child process.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($pipes[0]);
        }

        if (
            isset($pipes[2])
            && is_resource($pipes[2])
        ) {
            stream_get_contents(
                $pipes[2]
            );

            // Process stderr pipe must be closed directly before proc_close().
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($pipes[2]);
        }

        $exitCode =
            proc_close(
                $process
            );

        if ($exitCode !== 0) {
            throw new RuntimeException(
                'Database dump command failed.'
            );
        }
    }

    private function escapeOptionValue(
        string $value
    ): string {
        return '"'
            . str_replace(
                ['\\', '"'],
                ['\\\\', '\\"'],
                $value
            )
            . '"';
    }

    private function canExecuteProcesses(): bool
    {
        return function_exists('proc_open')
            && is_callable('proc_open');
    }
}
