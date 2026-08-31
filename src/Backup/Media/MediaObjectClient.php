<?php

namespace SecureS3StorageForWordpress\Backup\Media;

/** Narrow S3 seam; no credentials or SDK objects enter persistent job state. */
interface MediaObjectClient
{
    public function request(string $operation, array $arguments, int $deadline): array;
}
