<?php

namespace SecureS3StorageForWordpress\Backup\Database\Php;

use mysqli;
use RuntimeException;

final class SchemaReader
{
    /**
     * @return list<string>
     */
    public function getTableNames(
        mysqli $connection,
        string $databaseName
    ): array {
        $database = $this->quoteIdentifier($databaseName);

        $result = $connection->query(
            "SHOW FULL TABLES FROM {$database} WHERE Table_type = 'BASE TABLE'"
        );

        if ($result === false) {
            throw new RuntimeException(
                'Unable to retrieve database table list.'
            );
        }

        $tables = [];

        while ($row = $result->fetch_row()) {
            if (! isset($row[0])) {
                continue;
            }

            $tables[] = (string) $row[0];
        }

        $result->free();

        sort($tables);

        return $tables;
    }

    public function getCreateTableSql(
        mysqli $connection,
        string $databaseName,
        string $tableName
    ): string {
        $database = $this->quoteIdentifier($databaseName);
        $table = $this->quoteIdentifier($tableName);

        $result = $connection->query(
            "SHOW CREATE TABLE {$database}.{$table}"
        );

        if ($result === false) {
            throw new RuntimeException(
                'Unable to retrieve table schema.'
            );
        }

        $row = $result->fetch_assoc();

        $result->free();

        if (
            ! is_array($row)
            || ! isset($row['Create Table'])
            || ! is_string($row['Create Table'])
        ) {
            throw new RuntimeException(
                'Invalid CREATE TABLE result received.'
            );
        }

        return $row['Create Table'];
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}