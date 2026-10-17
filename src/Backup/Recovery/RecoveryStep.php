<?php

namespace SecureS3StorageForWordpress\Backup\Recovery;

interface RecoveryStep
{
    /**
     * One bounded, replay-safe operation. The same checkpoint can be repeated
     * after a crash. Implementations must preserve artifacts and reconcile remote
     * receipts, never blindly recapture or overwrite an already uploaded object.
     * No production adapter is registered by this interface.
     */
    public function execute(RecoverySet $set, string $phase, array $checkpoint, int $deadline): RecoveryProgress;
}
