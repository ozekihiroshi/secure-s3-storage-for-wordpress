<?php

namespace SecureS3StorageForWordpress\Backup\Database\Php;

use DateTimeImmutable;
use mysqli;
use RuntimeException;
use SecureS3StorageForWordpress\Backup\Database\DatabaseConnection;
use SecureS3StorageForWordpress\Backup\Database\DatabaseDumper;
use SecureS3StorageForWordpress\Backup\Database\DumpResult;
use Throwable;

final class PhpMySqlDumper implements DatabaseDumper
{
    private const DEFAULT_CHUNK_SIZE = 1000;

    public function __construct(
        private ?SchemaReader $schemaReader = null,
        private ?ColumnMetadataReader $columnMetadataReader = null,
        private ?RowReader $rowReader = null,
        private ?SqlValueSerializer $serializer = null,
        private int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {
        if ($this->chunkSize <= 0) {
            throw new RuntimeException(
                'Database dump chunk size must be greater than zero.'
            );
        }

        $this->schemaReader ??= new SchemaReader();
        $this->columnMetadataReader ??= new ColumnMetadataReader();
        $this->rowReader ??= new RowReader();
        $this->serializer ??= new SqlValueSerializer();
    }

    public function dump(DatabaseConnection $connection): DumpResult
    {
        $mysqli = null;
        $writer = null;
        $dumpFile = null;
        $success = false;
        $transactionStarted = false;

        try {
            $mysqli = $this->createConnection($connection);

            $dumpFile = $this->createDumpFilePath(
                $connection->getDatabaseName()
            );

            $writer = new SqlWriter($dumpFile);
            $writer->writeHeader();

            $tables = $this->schemaReader->getTableNames(
                $mysqli,
                $connection->getDatabaseName()
            );

            /*
             * Use a consistent snapshot where the storage engine supports it.
             * WordPress tables are normally InnoDB.
             */
            $this->startConsistentSnapshot($mysqli);
            $transactionStarted = true;

            foreach ($tables as $tableName) {
                $this->dumpTable(
                    $mysqli,
                    $connection->getDatabaseName(),
                    $tableName,
                    $writer
                );
            }

            $writer->writeFooter();
            $writer->close();
            $writer = null;

            if (! $mysqli->commit()) {
                throw new RuntimeException(
                    'Unable to commit database snapshot transaction.'
                );
            }

            $transactionStarted = false;

            if (! is_file($dumpFile)) {
                throw new RuntimeException(
                    'PHP database dump file was not created.'
                );
            }

            $size = filesize($dumpFile);

            if ($size === false || $size <= 0) {
                throw new RuntimeException(
                    'PHP database dump file is empty.'
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
        } catch (Throwable $e) {
            throw new RuntimeException(
                'PHP database dump failed.',
                0,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is chained, not output.
                $e
            );
        } finally {
            if ($writer !== null) {
                $writer->close();
            }

            if ($mysqli instanceof mysqli) {
                if ($transactionStarted) {
                    try {
                        $mysqli->rollback();
                    } catch (Throwable $e) {
                        // Do not mask the original dump failure.
                    }
                }

                $mysqli->close();
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

    private function dumpTable(
        mysqli $connection,
        string $databaseName,
        string $tableName,
        SqlWriter $writer
    ): void {
        $createTableSql = $this->schemaReader->getCreateTableSql(
            $connection,
            $databaseName,
            $tableName
        );

        $columns = $this->columnMetadataReader->getColumns(
            $connection,
            $databaseName,
            $tableName
        );

        if ($columns === []) {
            throw new RuntimeException(
                'No column metadata found for database table.'
            );
        }

        $writer->writeCreateTable(
            $tableName,
            $createTableSql
        );

        $offset = 0;

        while (true) {
            $rows = $this->rowReader->readChunk(
                $connection,
                $databaseName,
                $tableName,
                $this->chunkSize,
                $offset
            );

            if ($rows === []) {
                break;
            }

            $writer->writeInsertRows(
                $tableName,
                $rows,
                $columns,
                $this->serializer,
                $connection
            );

            $offset += count($rows);

            if (count($rows) < $this->chunkSize) {
                break;
            }
        }
    }

    private function createConnection(
        DatabaseConnection $connection
    ): mysqli {
        mysqli_report(MYSQLI_REPORT_OFF);

        $mysqli = @new mysqli(
            $connection->getHost(),
            $connection->getUsername(),
            $connection->getPassword(),
            $connection->getDatabaseName(),
            $connection->getPort(),
            $connection->hasSocket()
                ? $connection->getSocket()
                : null
        );

        if ($mysqli->connect_errno) {
            throw new RuntimeException(
                'Unable to connect to the MySQL database.'
            );
        }

        if (! $mysqli->set_charset('utf8mb4')) {
            $mysqli->close();

            throw new RuntimeException(
                'Unable to configure the database connection character set.'
            );
        }

        return $mysqli;
    }

    private function startConsistentSnapshot(
        mysqli $connection
    ): void {
        if (
            ! $connection->query(
                'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'
            )
        ) {
            throw new RuntimeException(
                'Unable to configure database transaction isolation.'
            );
        }

        if (
            ! $connection->query(
                'START TRANSACTION WITH CONSISTENT SNAPSHOT'
            )
        ) {
            throw new RuntimeException(
                'Unable to start consistent database snapshot.'
            );
        }
    }

    private function createDumpFilePath(
        string $databaseName
    ): string {
        $safeDatabaseName = preg_replace(
            '/[^A-Za-z0-9._-]/',
            '_',
            $databaseName
        );

        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to generate PHP dump filename.'
            );
        }

        return sprintf(
            '%s/secure-s3-php-dump-%s-%s.sql',
            rtrim(
                sys_get_temp_dir(),
                DIRECTORY_SEPARATOR
            ),
            $safeDatabaseName ?: 'database',
            $suffix
        );
    }
}