<?php

namespace SecureS3StorageForWordpress\Backup\Database;

use DateTimeImmutable;

final class DumpResult
{
    public function __construct(
        private string $path,
        private int $sizeBytes,
        private string $databaseName,
        private string $engine,
        private DateTimeImmutable $createdAt,
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getDatabaseName(): string
    {
        return $this->databaseName;
    }

    public function getEngine(): string
    {
        return $this->engine;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}