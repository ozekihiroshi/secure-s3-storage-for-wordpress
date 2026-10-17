<?php

namespace SecureS3StorageForWordpress\Backup\Recovery;

final class RecoveryProgress
{
    public function __construct(public readonly array $checkpoint, public readonly ?array $evidence = null)
    {
        Contract::require(strlen(json_encode($checkpoint, JSON_THROW_ON_ERROR, 16)) <= 32768);
        Contract::require($evidence === null || $checkpoint === []);
    }
}
