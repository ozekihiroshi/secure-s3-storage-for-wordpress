<?php

namespace SecureS3StorageForWordpress\Backup\History;

use DateTimeImmutable;

final class BackupHistoryEntry
{
    public function __construct(
        private bool $success,
        private DateTimeImmutable $createdAt,
        private string $databaseName,
        private string $backend,
        private ?string $bucket = null,
        private ?string $key = null,
        private ?int $sizeBytes = null,
        private ?string $message = null,
        private ?string $sha256 = null,
        private string $verification = 'unverified',
    ) {
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'databaseName' => $this->databaseName,
            'backend' => $this->backend,
            'bucket' => $this->bucket,
            'key' => $this->key,
            'sizeBytes' => $this->sizeBytes,
            'message' => $this->message,
            'sha256' => $this->sha256,
            'verification' => $this->verification,
        ];
    }
}
