<?php

namespace SecureS3StorageForWordpress\Backup\Database;

final class DatabaseConnection
{
    public function __construct(
        private string $host,
        private int $port,
        private string $databaseName,
        private string $username,
        private string $password,
        private ?string $socket = null,
    ) {
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getDatabaseName(): string
    {
        return $this->databaseName;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getSocket(): ?string
    {
        return $this->socket;
    }

    public function hasSocket(): bool
    {
        return $this->socket !== null && $this->socket !== '';
    }
}