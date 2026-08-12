<?php

namespace SecureS3StorageForWordpress\Backup;

use SecureS3StorageForWordpress\Backup\Database\DatabaseConnection;
use SecureS3StorageForWordpress\Backup\Database\DumpBackendSelector;
use SecureS3StorageForWordpress\Backup\Database\DumpResult;

final class BackupService
{
    public function __construct(
        private ?DumpBackendSelector $backendSelector = null
    ) {
        $this->backendSelector ??= new DumpBackendSelector();
    }

    public function backupDatabase(
        DatabaseConnection $connection
    ): DumpResult {
        $dumper = $this->backendSelector->select();

        return $dumper->dump($connection);
    }

    public function getSelectedBackendName(): string
    {
        return $this->backendSelector->getSelectedBackendName();
    }

    public function getDetectedUtility(): ?string
    {
        return $this->backendSelector->getDetectedUtility();
    }
}