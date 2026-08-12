<?php

namespace SecureS3StorageForWordpress\Backup\Database;

interface DatabaseDumper
{
    public function dump(DatabaseConnection $connection): DumpResult;
}