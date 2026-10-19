<?php

namespace SecureS3StorageForWordpress\WordPress;

use RuntimeException;
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use wpdb;

/** Site-local, non-autoloaded single-job slot. No schema migration required. */
final class WordPressJobStore implements JobStore
{
    public const OPTION_NAME = 'secure_s3_storage_background_job';

    public function __construct(private wpdb $database, private string $optionName = self::OPTION_NAME)
    {
        if ($optionName !== self::OPTION_NAME && preg_match('/^odbfs3_(?:recovery|observer_run|schedule|cleanup|execution|expectation)_[a-f0-9]{32}$/D', $optionName) !== 1) {
            throw new RuntimeException('Invalid backup state slot.');
        }
    }

    public function read(): ?string
    {
        // Direct DB reads are required for concurrency; cached options may be stale.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $value = $this->database->get_var($this->database->prepare(
            "SELECT option_value FROM {$this->database->options} WHERE option_name = %s",
            $this->optionName,
        ));
        if ($this->database->last_error !== '') {
            throw new RuntimeException('Unable to read backup job state.');
        }

        return $value === null ? null : (string) $value;
    }

    /** Dedicated non-autoloaded slot; never overwrites the standalone media job. */
    public static function forRecovery(wpdb $database, string $setId): self
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $setId) !== 1) {
            throw new RuntimeException('Invalid recovery state identity.');
        }
        return new self($database, 'odbfs3_recovery_' . $setId);
    }

    /** Caller persists/reuses this execution ID; never shares a Collector slot. */
    public static function forObservationRun(wpdb $database, string $runId): self
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $runId)!==1)throw new RuntimeException('Invalid observation execution identity.');
        return new self($database,'odbfs3_observer_run_'.$runId);
    }

    /** One persistent daily scheduler per source, shared by all workers. */
    public static function forRecoverySchedule(wpdb $database, string $sourceId): self
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $sourceId)!==1)throw new RuntimeException('Invalid schedule identity.');
        return new self($database, 'odbfs3_schedule_'.$sourceId);
    }

    public static function forRecoveryCleanup(wpdb $database, string $setId): self
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $setId)!==1)throw new RuntimeException('Invalid cleanup identity.');
        return new self($database, 'odbfs3_cleanup_'.$setId);
    }

    public static function forRecoveryExecution(wpdb $database, string $sourceId): self
    {
        return new self($database, 'odbfs3_execution_'.$sourceId);
    }

    public static function forRecoveryExpectation(wpdb $database, string $sourceId): self
    {
        return new self($database, 'odbfs3_expectation_'.$sourceId);
    }

    public function compareAndSwap(?string $expected, string $replacement): bool
    {
        if ($expected === null) {
            // The unique option_name index makes concurrent first submissions atomic.
            $query = $this->database->prepare(
                "INSERT IGNORE INTO {$this->database->options} (option_name, option_value, autoload)
                 VALUES (%s, %s, 'no')",
                $this->optionName,
                $replacement,
            );
        } else {
            // BINARY prevents case-insensitive collation from accepting a stale value.
            $query = $this->database->prepare(
                "UPDATE {$this->database->options} SET option_value = %s
                 WHERE option_name = %s AND BINARY option_value = BINARY %s",
                $replacement,
                $this->optionName,
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

        wp_cache_delete($this->optionName, 'options');
        wp_cache_delete('notoptions', 'options');

        return true;
    }
}
