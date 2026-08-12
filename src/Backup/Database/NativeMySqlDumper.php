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

    public function __construct(
        array $binaryCandidates = ['mysqldump', 'mariadb-dump']
    ) {
        $this->binaryCandidates = $binaryCandidates;
    }

    public function dump(DatabaseConnection $connection): DumpResult
    {
        $binary = $this->findDumpBinary();

        $optionFile = null;
        $dumpFile = null;
        $success = false;

        try {
            $optionFile = $this->createOptionFile($connection);
            $dumpFile = $this->createDumpFilePath(
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

            $size = filesize($dumpFile);

            if ($size === false || $size <= 0) {
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
            if ($optionFile !== null && is_file($optionFile)) {
                @unlink($optionFile);
            }

            if (
                ! $success
                && $dumpFile !== null
                && is_file($dumpFile)
            ) {
                @unlink($dumpFile);
            }
        }
    }

    private function findDumpBinary(): string
    {
        foreach ($this->binaryCandidates as $candidate) {
            $path = $this->findExecutable($candidate);

            if ($path !== null) {
                return $path;
            }
        }

        throw new RuntimeException(
            'MySQL dump utility was not found.'
        );
    }

    private function findExecutable(string $binary): ?string
    {
        $process = proc_open(
            ['which', $binary],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (! is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return null;
        }

        $path = trim((string) $stdout);

        return $path !== '' ? $path : null;
    }

    private function createOptionFile(
        DatabaseConnection $connection
    ): string {
        $path = tempnam(
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
            'host=' . $connection->getHost(),
            'port=' . $connection->getPort(),
            'user=' . $connection->getUsername(),
            'password=' . $this->escapeOptionValue(
                $connection->getPassword()
            ),
        ];

        if ($connection->hasSocket()) {
            $lines[] = 'socket=' . $connection->getSocket();
        }

        $content = implode(PHP_EOL, $lines) . PHP_EOL;

        if (file_put_contents($path, $content) === false) {
            @unlink($path);

            throw new RuntimeException(
                'Unable to write temporary MySQL option file.'
            );
        }

        if (! chmod($path, 0600)) {
            @unlink($path);

            throw new RuntimeException(
                'Unable to secure temporary MySQL option file.'
            );
        }

        return $path;
    }

    private function createDumpFilePath(string $databaseName): string
    {
        $safeDatabaseName = preg_replace(
            '/[^A-Za-z0-9._-]/',
            '_',
            $databaseName
        );

        $directory = sys_get_temp_dir();

        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to generate temporary dump filename.'
            );
        }

        return sprintf(
            '%s/secure-s3-dump-%s-%s.sql',
            rtrim($directory, DIRECTORY_SEPARATOR),
            $safeDatabaseName ?: 'database',
            $suffix
        );
    }

    private function runDump(
        string $binary,
        string $optionFile,
        string $dumpFile,
        DatabaseConnection $connection
    ): void {
        $command = [
            $binary,
            '--defaults-extra-file=' . $optionFile,
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--default-character-set=utf8mb4',
            $connection->getDatabaseName(),
        ];

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $dumpFile, 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes
        );

        if (! is_resource($process)) {
            throw new RuntimeException(
                'Unable to start database dump process.'
            );
        }

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                'Database dump command failed.'
            );
        }
    }

    private function escapeOptionValue(string $value): string
    {
        return '"'
            . str_replace(
                ['\\', '"'],
                ['\\\\', '\\"'],
                $value
            )
            . '"';
    }
}
