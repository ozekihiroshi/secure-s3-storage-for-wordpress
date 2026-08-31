<?php

namespace SecureS3StorageForWordpress\WordPress;

use Closure;
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use RuntimeException;
use SecureS3StorageForWordpress\Aws\MediaS3Client;
use SecureS3StorageForWordpress\Backup\Job\BackupJob;
use SecureS3StorageForWordpress\Backup\Job\JobRunner;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadPlan;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadStep;
use Throwable;

/** Explicit CLI submission; no media backup starts merely by loading the plugin. */
final class MediaJobController
{
    public const HOOK = 'secure_s3_storage_media_worker';
    public const INTERVAL = 'secure_s3_storage_worker_minute';

    public function __construct(private ?JobStore $jobStore = null, private ?Closure $clientFactory = null)
    {
    }

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'schedules']);
        add_action(self::HOOK, [$this, 'run'], 10, 1);
        add_action('init', [$this, 'recoverSchedule']);
    }

    public function schedules(array $schedules): array
    {
        $schedules[self::INTERVAL] = ['interval' => 60, 'display' => 'Media backup worker'];
        return $schedules;
    }

    public function current(): ?BackupJob
    {
        $record = $this->store()->read();
        return $record === null ? null : BackupJob::decode($record);
    }

    public function start(MediaUploadPlan $plan): BackupJob
    {
        $metadata = $plan->metadata();
        self::assertOutsideWebRoot($plan->directory);
        $source = (new WordPressMediaSourceFactory())->create();
        if ($source->rootPath() !== $metadata['root']) {
            throw new RuntimeException('Media plan belongs to another upload root.');
        }
        $options = get_option('secure_s3_storage_settings', []);
        $region = $options['region'] ?? '';
        $bucket = $options['bucket'] ?? '';
        $prefix = $options['prefix'] ?? '';
        if (! is_string($region) || $region === '' || ! is_string($bucket) || $bucket === ''
            || ! is_string($prefix) || strlen($prefix) + 128 > 1024 || str_contains($prefix, "\0")) {
            throw new RuntimeException('Valid AWS destination settings are required.');
        }
        $prefix = trim($prefix, '/');
        $prefix = $prefix === '' ? '' : $prefix . '/';
        $store = $this->store();
        $observed = $store->read();
        if ($observed !== null) {
            $old = BackupJob::decode($observed);
            if (! $old->terminal() || $old->type !== 'media') {
                throw new RuntimeException('A backup job is already active.');
            }
            // Archive before replacing the slot. Concurrent starters write identical history.
            $name = 'secure_s3_storage_media_result_' . $old->id;
            if (! add_option($name, $observed, '', false) && get_option($name) !== $observed) {
                throw new RuntimeException('Unable to archive the previous backup result.');
            }
        }
        $job = new BackupJob(bin2hex(random_bytes(16)), 'media', checkpoint: [
            'directory' => $plan->directory, 'metadata' => $metadata,
            'region' => $region, 'bucket' => $bucket, 'prefix' => $prefix, 'offset' => 0,
        ]);
        if (! $store->compareAndSwap($observed, $job->encode())) {
            throw new RuntimeException('Another backup submission won the slot.');
        }
        // If scheduling fails the durable queued job remains recoverable by CLI/init.
        $this->schedule($job->id);
        return $job;
    }

    public function recoverSchedule(): void
    {
        try {
            $job = $this->current();
            if ($job !== null && $job->type === 'media' && ! $job->terminal()) {
                $this->schedule($job->id);
            }
        } catch (Throwable $e) {
            // Do not break page requests or expose DB/SDK details. CLI reports safe status.
        }
    }

    public function run(string $id): string
    {
        try {
            $job = $this->current();
            if ($job === null || $job->id !== $id || $job->type !== 'media') {
                wp_clear_scheduled_hook(self::HOOK, [$id]);
                return 'mismatch';
            }
            $runner = new JobRunner($this->store());
            $step = new MediaUploadStep($this->clientFactory === null
                ? MediaS3Client::create($job->checkpoint['region'])
                : ($this->clientFactory)($job->checkpoint['region']));
            $until = microtime(true) + 20;
            for ($count = 0; $count < 100; ++$count) {
                $status = $runner->tick($id, 'media', $step);
                if ($status !== 'running' || microtime(true) >= $until
                    || ($this->current()?->attempts ?? 0) > 0) {
                    break;
                }
            }
            if (in_array($status, ['succeeded', 'failed'], true)) {
                wp_clear_scheduled_hook(self::HOOK, [$id]);
            }
            return $status;
        } catch (Throwable $e) {
            return 'worker_unavailable';
        }
    }

    /** Explicit CLI cleanup only; no object deletion or cleanup on uninstall. */
    public function abortFailedUpload(): void
    {
        $job = $this->current();
        if ($job === null || $job->type !== 'media' || $job->status !== 'failed') {
            throw new RuntimeException('Only a failed media job can be cleaned up.');
        }
        $state = $job->checkpoint;
        if (! isset($state['upload_id'])) {
            return;
        }
        [$object] = (new MediaUploadPlan($state['directory']))->record($state['offset']);
        if ($object === null) {
            throw new RuntimeException('Missing failed upload descriptor.');
        }
        $key = $state['prefix'] . 'backups/media/' . $job->id . '/'
            . ($object['path'] === null ? 'inventory.jsonl' : 'files/' . hash('sha256', $object['path']));
        MediaS3Client::create($state['region'])->request('AbortMultipartUpload', [
            'Bucket' => $state['bucket'], 'Key' => $key, 'UploadId' => $state['upload_id'],
        ], time() + 30);
    }

    private function schedule(string $id): void
    {
        if (wp_next_scheduled(self::HOOK, [$id]) === false) {
            $result = wp_schedule_event(time() + 5, self::INTERVAL, self::HOOK, [$id], true);
            if (is_wp_error($result) || $result === false) {
                throw new RuntimeException('Media job is saved but scheduling failed.');
            }
        }
    }

    /** Delete only this plugin's archived local job metadata, never S3/filesystem data. */
    public static function purgeArchivedResults(): void
    {
        global $wpdb;
        $pattern = $wpdb->esc_like('secure_s3_storage_media_result_') . '%';
        do {
            // Bounded batches; delete_option handles WordPress cache invalidation.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $names = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 100",
                $pattern,
            ));
            if (! is_array($names) || $wpdb->last_error !== '') {
                throw new RuntimeException('Unable to read archived media jobs.');
            }
            foreach ($names as $name) {
                if (! delete_option($name)) {
                    throw new RuntimeException('Unable to remove archived media job metadata.');
                }
            }
        } while ($names !== []);
    }

    public static function assertOutsideWebRoot(string $path): void
    {
        $path = realpath($path);
        $root = realpath(ABSPATH);
        if ($path === false || $root === false) {
            throw new RuntimeException('Media work directory does not exist.');
        }
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }
        if ($path === $root || str_starts_with($path, $root . '/')) {
            throw new RuntimeException('Media work directory must be outside the web root.');
        }
    }

    private function store(): JobStore
    {
        if ($this->jobStore !== null) {
            return $this->jobStore;
        }
        global $wpdb;
        return new WordPressJobStore($wpdb);
    }
}
