<?php

namespace SecureS3StorageForWordpress\Backup\Database\Php;

use mysqli;
use RuntimeException;

final class ColumnMetadataReader
{
    /**
     * @return array<string, array{
     *     name: string,
     *     dataType: string,
     *     columnType: string,
     *     characterSet: ?string,
     *     nullable: bool
     * }>
     */
    public function getColumns(
        mysqli $connection,
        string $databaseName,
        string $tableName
    ): array {
        $sql = '
            SELECT
                COLUMN_NAME,
                DATA_TYPE,
                COLUMN_TYPE,
                CHARACTER_SET_NAME,
                IS_NULLABLE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ';

        $statement = $connection->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException(
                'Unable to prepare column metadata query.'
            );
        }

        $statement->bind_param(
            'ss',
            $databaseName,
            $tableName
        );

        if (! $statement->execute()) {
            $statement->close();

            throw new RuntimeException(
                'Unable to retrieve column metadata.'
            );
        }

        $result = $statement->get_result();

        if ($result === false) {
            $statement->close();

            throw new RuntimeException(
                'Unable to read column metadata result.'
            );
        }

        $columns = [];

        while ($row = $result->fetch_assoc()) {
            $name = (string) $row['COLUMN_NAME'];

            $columns[$name] = [
                'name' => $name,
                'dataType' => strtolower(
                    (string) $row['DATA_TYPE']
                ),
                'columnType' => strtolower(
                    (string) $row['COLUMN_TYPE']
                ),
                'characterSet' => $row['CHARACTER_SET_NAME'] !== null
                    ? strtolower(
                        (string) $row['CHARACTER_SET_NAME']
                    )
                    : null,
                'nullable' => (
                    (string) $row['IS_NULLABLE']
                ) === 'YES',
            ];
        }

        $result->free();
        $statement->close();

        return $columns;
    }
}