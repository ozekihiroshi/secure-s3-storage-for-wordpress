<?php

namespace SecureS3StorageForWordpress\Backup;

use DateTimeImmutable;

final class DatabaseBackupResult
{
    public function __construct(
        private string $bucket,
        private string $key,
        private int $sizeBytes,
        private string $databaseName,
        private string $backend,
        private DateTimeImmutable $createdAt,
        private ?string $etag = null,
    ) {
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getDatabaseName(): string
    {
        return $this->databaseName;
    }

    public function getBackend(): string
    {
        return $this->backend;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEtag(): ?string
    {
        return $this->etag;
    }
}