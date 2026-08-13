<?php

namespace SecureS3StorageForWordpress\Backup\Retention;

use DateTimeImmutable;

final class RetentionCandidate
{
    public function __construct(
        private string $bucket,
        private string $key,
        private int $sizeBytes,
        private DateTimeImmutable $lastModified
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

    public function getLastModified(): DateTimeImmutable
    {
        return $this->lastModified;
    }
}