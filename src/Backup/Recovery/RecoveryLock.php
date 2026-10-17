<?php

namespace SecureS3StorageForWordpress\Backup\Recovery;

interface RecoveryLock
{
    public function acquire(): bool;
    public function release(): void;
}
