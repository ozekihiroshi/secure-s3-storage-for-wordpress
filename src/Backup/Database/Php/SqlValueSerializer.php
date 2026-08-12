<?php

namespace SecureS3StorageForWordpress\Backup\Database\Php;

use mysqli;
use RuntimeException;

final class SqlValueSerializer
{
    private const NUMERIC_TYPES = [
        'tinyint',
        'smallint',
        'mediumint',
        'int',
        'integer',
        'bigint',
        'decimal',
        'numeric',
        'float',
        'double',
        'real',
    ];

    private const BINARY_TYPES = [
        'binary',
        'varbinary',
        'tinyblob',
        'blob',
        'mediumblob',
        'longblob',
    ];

    /**
     * @param array{
     *     name: string,
     *     dataType: string,
     *     columnType: string,
     *     characterSet: ?string,
     *     nullable: bool
     * } $column
     */
    public function serialize(
        mysqli $connection,
        mixed $value,
        array $column
    ): string {
        if ($value === null) {
            return 'NULL';
        }

        $dataType = strtolower(
            (string) ($column['dataType'] ?? '')
        );

        if ($dataType === '') {
            throw new RuntimeException(
                'Column data type is missing.'
            );
        }

        if ($this->isBinaryType($dataType)) {
            return $this->serializeBinary($value);
        }

        if ($this->isNumericType($dataType)) {
            return $this->serializeNumeric($value);
        }

        return $this->serializeString(
            $connection,
            $value
        );
    }

    private function isNumericType(string $dataType): bool
    {
        return in_array(
            $dataType,
            self::NUMERIC_TYPES,
            true
        );
    }

    private function isBinaryType(string $dataType): bool
    {
        return in_array(
            $dataType,
            self::BINARY_TYPES,
            true
        );
    }

    private function serializeNumeric(mixed $value): string
    {
        $stringValue = (string) $value;

        if (! is_numeric($stringValue)) {
            throw new RuntimeException(
                'Invalid numeric database value.'
            );
        }

        return $stringValue;
    }

    private function serializeBinary(mixed $value): string
    {
        return '0x' . bin2hex((string) $value);
    }

    private function serializeString(
        mysqli $connection,
        mixed $value
    ): string {
        $escaped = $connection->real_escape_string(
            (string) $value
        );

        return "'" . $escaped . "'";
    }
}