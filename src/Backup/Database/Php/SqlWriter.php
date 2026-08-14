<?php

namespace SecureS3StorageForWordpress\Backup\Database\Php;

use RuntimeException;
use SecureS3StorageForWordpress\Backup\CompleteStreamWriter;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;

final class SqlWriter
{
    /**
     * @var resource
     */
    private $handle;

    public function __construct(string $path)
    {
        $handle = SecureTemporaryFile::openForWriting($path);

        $this->handle = $handle;
    }

    public function writeHeader(): void
    {
        $this->write(
            "-- Secure S3 Storage database dump\n"
            . "-- Generated: " . gmdate('c') . "\n\n"
            . "SET NAMES utf8mb4;\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n\n"
        );
    }

    public function writeCreateTable(
        string $tableName,
        string $createTableSql
    ): void {
        $table = $this->quoteIdentifier($tableName);

        $this->write(
            "DROP TABLE IF EXISTS {$table};\n"
            . $createTableSql
            . ";\n\n"
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, array{
     *     name: string,
     *     dataType: string,
     *     columnType: string,
     *     characterSet: ?string,
     *     nullable: bool
     * }> $columns
     */
    public function writeInsertRows(
        string $tableName,
        array $rows,
        array $columns,
        SqlValueSerializer $serializer,
        \mysqli $connection
    ): void {
        if ($rows === []) {
            return;
        }

        if ($columns === []) {
            throw new RuntimeException(
                'Column metadata is required for INSERT generation.'
            );
        }

        $columnNames = array_keys($columns);

        $quotedColumns = array_map(
            fn (string $column): string =>
                $this->quoteIdentifier($column),
            $columnNames
        );

        $valueGroups = [];

        foreach ($rows as $row) {
            $values = [];

            foreach ($columnNames as $columnName) {
                if (! array_key_exists($columnName, $row)) {
                    throw new RuntimeException(
                        'Database row does not contain expected column.'
                    );
                }

                $values[] = $serializer->serialize(
                    $connection,
                    $row[$columnName],
                    $columns[$columnName]
                );
            }

            $valueGroups[] = '(' . implode(', ', $values) . ')';
        }

        $table = $this->quoteIdentifier($tableName);

        $sql =
            'INSERT INTO '
            . $table
            . ' ('
            . implode(', ', $quotedColumns)
            . ') VALUES'
            . "\n"
            . implode(",\n", $valueGroups)
            . ";\n\n";

        $this->write($sql);
    }

    public function writeFooter(): void
    {
        $this->write(
            "SET FOREIGN_KEY_CHECKS=1;\n"
        );
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            // Direct stream handling is required by the SQL dump writer.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function write(string $content): void
    {
        CompleteStreamWriter::writeAll(
            $content,
            function (string $remaining): int|false {
                // SQL data is streamed directly to the secured dump file.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
                return fwrite(
                    $this->handle,
                    $remaining
                );
            },
            'Unable to write SQL dump file.'
        );
    }

    private function quoteIdentifier(
        string $identifier
    ): string {
        return '`'
            . str_replace('`', '``', $identifier)
            . '`';
    }
}