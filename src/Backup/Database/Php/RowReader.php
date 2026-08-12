<?php

namespace SecureS3StorageForWordpress\Backup\Database\Php;

use mysqli;
use RuntimeException;

final class RowReader
{
    /**
     * @return list<array<string, mixed>>
     */
    public function readChunk(
        mysqli $connection,
        string $databaseName,
        string $tableName,
        int $limit,
        int $offset
    ): array {
        if ($limit <= 0) {
            throw new RuntimeException(
                'Row chunk limit must be greater than zero.'
            );
        }

        if ($offset < 0) {
            throw new RuntimeException(
                'Row chunk offset must not be negative.'
            );
        }

        $database = $this->quoteIdentifier($databaseName);
        $table = $this->quoteIdentifier($tableName);

        $sql = sprintf(
            'SELECT * FROM %s.%s LIMIT %d OFFSET %d',
            $database,
            $table,
            $limit,
            $offset
        );

        $result = $connection->query($sql);

        if ($result === false) {
            throw new RuntimeException(
                'Unable to read database rows.'
            );
        }

        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $result->free();

        return $rows;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}