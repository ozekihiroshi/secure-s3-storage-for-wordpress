<?php

namespace SecureS3StorageForWordpress\WordPress;

use RuntimeException;
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use wpdb;

/** Site-local, non-autoloaded single-job slot. No schema migration required. */
final class WordPressJobStore implements JobStore
{
    public const OPTION_NAME = 'secure_s3_storage_background_job';

    public function __construct(private wpdb $database)
    {
    }

    public function read(): ?string
    {
        // Direct DB reads are required for concurrency; cached options may be stale.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $value = $this->database->get_var($this->database->prepare(
            "SELECT option_value FROM {$this->database->options} WHERE option_name = %s",
            self::OPTION_NAME,
        ));
        if ($this->database->last_error !== '') {
            throw new RuntimeException('Unable to read backup job state.');
        }

        return $value === null ? null : (string) $value;
    }

    public function compareAndSwap(?string $expected, string $replacement): bool
    {
        if ($expected === null) {
            // The unique option_name index makes concurrent first submissions atomic.
            $query = $this->database->prepare(
                "INSERT IGNORE INTO {$this->database->options} (option_name, option_value, autoload)
                 VALUES (%s, %s, 'no')",
                self::OPTION_NAME,
                $replacement,
            );
        } else {
            // BINARY prevents case-insensitive collation from accepting a stale value.
            $query = $this->database->prepare(
                "UPDATE {$this->database->options} SET option_value = %s
                 WHERE option_name = %s AND BINARY option_value = BINARY %s",
                $replacement,
                self::OPTION_NAME,
                $expected,
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Both branches prepare all values above; table name comes from wpdb.
        $affected = $this->database->query($query);
        if ($affected === false) {
            throw new RuntimeException('Unable to save backup job state.');
        }
        if ($affected !== 1) {
            return false;
        }

        wp_cache_delete(self::OPTION_NAME, 'options');
        wp_cache_delete('notoptions', 'options');

        return true;
    }
}
