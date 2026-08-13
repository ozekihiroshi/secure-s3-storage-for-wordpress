<?php

namespace SecureS3StorageForWordpress\WordPress;

use RuntimeException;
use SecureS3StorageForWordpress\Backup\Database\DatabaseConnection;

final class WordPressDatabaseConnectionFactory
{
    public function create(): DatabaseConnection
    {
        if (
            ! defined('DB_HOST')
            || ! defined('DB_NAME')
            || ! defined('DB_USER')
            || ! defined('DB_PASSWORD')
        ) {
            throw new RuntimeException(
                'WordPress database configuration is unavailable.'
            );
        }

        [$host, $port, $socket] = $this->parseHost(
            (string) DB_HOST
        );

        return new DatabaseConnection(
            host: $host,
            port: $port,
            databaseName: (string) DB_NAME,
            username: (string) DB_USER,
            password: (string) DB_PASSWORD,
            socket: $socket
        );
    }

    /**
     * @return array{0:string, 1:int, 2:?string}
     */
    private function parseHost(string $dbHost): array
    {
        $host = $dbHost;
        $port = 3306;
        $socket = null;

        // Example: localhost:/var/run/mysqld/mysqld.sock
        if (preg_match('/^([^:]+):(\/.+)$/', $dbHost, $matches)) {
            return [
                $matches[1],
                $port,
                $matches[2],
            ];
        }

        // Example: db.example.com:3307
        if (preg_match('/^(.+):(\d+)$/', $dbHost, $matches)) {
            return [
                $matches[1],
                (int) $matches[2],
                null,
            ];
        }

        return [
            $host,
            $port,
            $socket,
        ];
    }
}