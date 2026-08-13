<?php

namespace SecureS3StorageForWordpress\Backup\History;

final class BackupHistoryRepository
{
    private const OPTION_NAME =
        'secure_s3_storage_backup_history';

    private const MAX_ENTRIES = 20;

    public function add(
        BackupHistoryEntry $entry
    ): void {
        $history = $this->all();

        array_unshift(
            $history,
            $entry->toArray()
        );

        $history = array_slice(
            $history,
            0,
            self::MAX_ENTRIES
        );

        update_option(
            self::OPTION_NAME,
            $history,
            false
        );
    }

    public function all(): array
    {
        $history = get_option(
            self::OPTION_NAME,
            []
        );

        return is_array($history)
            ? $history
            : [];
    }
}