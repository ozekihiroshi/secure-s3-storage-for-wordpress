<?php

namespace SecureS3StorageForWordpress\Cli;

use SecureS3StorageForWordpress\Backup\Recovery\RecoveryStatus;
use SecureS3StorageForWordpress\WordPress\WordPressJobStore;
use Throwable;
use WP_CLI;

final class RecoverySetCommand
{
    /**
     * Read local Recovery Set evidence without advancing the job or contacting S3.
     *
     * ## OPTIONS
     *
     * <set-id>
     * : Exact 32-character lowercase hexadecimal Recovery Set ID.
     *
     * [--format=<format>]
     * : Output format: json or table. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp ozeki-database-backup-for-s3 recovery-set status <set-id> --format=json
     *
     * @when after_wp_load
     */
    public function status(array $args, array $assocArgs): void
    {
        $format = $assocArgs['format'] ?? 'table';
        if (count($args) !== 1 || ! is_string($args[0])
            || preg_match('/^[a-f0-9]{32}$/D', $args[0]) !== 1
            || ! in_array($format, ['json', 'table'], true)
            || array_diff(array_keys($assocArgs), ['format'])) {
            $this->output(['schema_version' => 1, 'phase' => 'unknown', 'reason_code' => 'invalid_request'], $format === 'json', 2);
            return;
        }
        try {
            $database = $GLOBALS['wpdb'];
            $previousSuppression = $database->suppress_errors(true);
            try {
                $record = RecoveryStatus::read(WordPressJobStore::forRecovery($database, $args[0]), $args[0]);
            } finally {
                $database->suppress_errors($previousSuppression);
            }
        } catch (Throwable $e) {
            $this->output(['schema_version' => 1, 'set_id' => $args[0], 'phase' => 'unknown', 'reason_code' => 'state_unreadable'], $format === 'json', 4);
            return;
        }
        if ($record === null) {
            $this->output(['schema_version' => 1, 'set_id' => $args[0], 'phase' => 'unknown', 'reason_code' => 'set_not_found'], $format === 'json', 3);
            return;
        }
        $this->output($record, $format === 'json', 0);
    }

    private function output(array $record, bool $json, int $code): void
    {
        if ($json) {
            WP_CLI::line(json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $rows = [];
            foreach ($record as $field => $value) {
                $rows[] = ['field' => $field, 'value' => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : ($value ?? '-')];
            }
            \WP_CLI\Utils\format_items('table', $rows, ['field', 'value']);
        }
        if ($code !== 0) { WP_CLI::halt($code); }
    }
}
