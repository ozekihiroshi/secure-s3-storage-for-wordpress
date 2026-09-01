<?php

namespace SecureS3StorageForWordpress\Backup\Media;

use RuntimeException;
use SecureS3StorageForWordpress\Backup\Job\BackupJob;
use SecureS3StorageForWordpress\Backup\Job\JobRunner;
use SecureS3StorageForWordpress\Backup\Job\JobStep;
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use SecureS3StorageForWordpress\Backup\Job\PreparationRequiresCliException;
use SecureS3StorageForWordpress\Backup\Job\RetryableJobException;
use SecureS3StorageForWordpress\Backup\Job\StepResult;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;

/** Preparation only. The controller switches to MediaUploadStep after ready.json. */
final class MediaPreparationStep implements JobStep
{
    private MediaPreparationWorkspace $work;
    private ?JobStore $activeStore = null;

    public function __construct(private MediaSource $source, string $directory, string $documentRoot,
        private int $sortBatch = 128, private float $directorySeconds = 10.0)
    {
        if ($sortBatch < 1 || $sortBatch > 128 || $directorySeconds <= 0 || $directorySeconds > 10) {
            throw new RuntimeException('Invalid preparation budget.');
        }
        $this->work = new MediaPreparationWorkspace($directory, $source, $documentRoot);
    }

    /** Creates only private metadata, not a directory scan or any S3 operation. */
    public static function initialize(MediaSource $source, string $parent, string $documentRoot): array
    {
        $parent = $source->externalDirectory($parent);
        $web = realpath($documentRoot);
        if ($web === false || $parent === rtrim($web, '/') || str_starts_with($parent, rtrim($web, '/') . '/')) {
            throw new RuntimeException('Preparation storage must be outside the web root.');
        }
        $directory = $parent . '/odbfs3-preparation-' . bin2hex(random_bytes(16));
        // Private from creation. Abandoned work remains private; no automatic deletion.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        if (! mkdir($directory, 0700)) { throw new RuntimeException('Cannot create preparation workspace.'); }
        $work = new MediaPreparationWorkspace($directory, $source, $documentRoot);
        $lock = SecureTemporaryFile::openForWriting($directory . '/worker.lock');
        MediaPreparationWorkspace::finish($lock);
        if (! $work->acquire()) { throw new RuntimeException('Cannot initialize preparation workspace.'); }
        try {
            $queue = $work->output('directories.jsonl', 0);
            try {
                MediaInventoryIO::write($queue, MediaPreparationWorkspace::encode(['path' => '', 'identity' => $source->snapshot('')]));
                $size = MediaPreparationWorkspace::finish($queue);
            } finally {
                if (is_resource($queue)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    fclose($queue);
                }
            }
            return ['phase' => 'enumerate', 'directory' => $directory, 'root' => $source->rootPath(),
                'workspace_identity' => $work->identity(), 'queue_cursor' => 0, 'queue_size' => $size,
                'work_identity' => MediaFailedJobCleanup::captureIdentity(
                    $directory, [$documentRoot, $source->rootPath()]),
                'paths_size' => 0, 'expected_files' => 0, 'expected_bytes' => 0];
        } finally { $work->release(); }
    }

    /** Hold flock before job read/claim and through CAS, not just during execute(). */
    public function tick(JobStore $store, string $id): string
    {
        if (! $this->work->acquire()) { return 'busy'; }
        $this->activeStore = $store;
        try {
            // A controller may have selected this handler just before another
            // worker finished preparation. Recheck under the lock before claim.
            $record = $store->read();
            $current = $record === null ? null : BackupJob::decode($record);
            if ($current !== null && $current->id === $id && ! isset($current->checkpoint['phase'])) {
                return $current->terminal() ? $current->status : 'running';
            }
            return (new JobRunner($store))->tick($id, 'media', $this);
        }
        finally { $this->activeStore = null; $this->work->release(); }
    }

    public function execute(BackupJob $job, int $deadline): StepResult
    {
        $s = $job->checkpoint;
        if (! $this->work->locked() || $this->activeStore?->read() !== $job->encode()
            || $job->type !== 'media' || ($s['directory'] ?? null) !== $this->work->directory
            || ($s['root'] ?? null) !== $this->source->rootPath()
            || ($s['workspace_identity'] ?? null) !== $this->work->identity()) {
            throw new RuntimeException('Invalid or unlocked preparation job.');
        }
        if (time() >= $deadline - 3) { throw new RetryableJobException('Preparation needs a fresh lease.'); }
        switch ($s['phase']) {
            case 'enumerate': $s = $this->enumerate($s, $deadline); break;
            case 'sort_runs':
            case 'sort_merge': $s = (new MediaPreparationSort($this->work, $this->sortBatch))->step($s, $deadline); break;
            case 'files': $s = $this->nextFile($s, $job->id); break;
            case 'file_hash':
                $this->assertSource($s);
                return (new MediaFileHashStep($this->source, $this->work->checkpoints))->execute($job, $deadline);
            case 'file_hashed':
                $s['object'] = ['path' => $s['path'], 'size' => $s['file_size'], 'sha256' => $s['file_sha256']];
                $s = $this->beginParts($s, $job->id);
                break;
            case 'parts': $s = $this->parts($s, $job->id, $deadline); break;
            case 'validate_directories': $s = $this->validateDirectory($s); break;
            default: throw new RuntimeException('Unknown preparation phase.');
        }
        return new StepResult($s, $job->processedFiles, $job->processedBytes);
    }

    private function enumerate(array $s, int $deadline): array
    {
        $cursor = $s['queue_cursor'];
        $line = $this->work->line('directories.jsonl', $cursor, $s['queue_size']);
        if ($line === null) {
            $s['phase'] = 'sort_runs';
            $s['sort_cursor'] = $s['run_count'] = $s['run_list_size'] = 0;
            return $s;
        }
        $record = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        $before = $this->source->snapshot($record['path']);
        if ($before !== $record['identity']) { throw new RuntimeException('Media directory changed.'); }
        $until = min(microtime(true) + $this->directorySeconds, $deadline - 3);
        // One complete folder per step. Do not pretend PHP can persist a dir handle.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_opendir
        $dir = opendir($this->source->directoryPath($record['path']));
        if ($dir === false) { throw new RuntimeException('Cannot enumerate media directory.'); }
        $queue = $paths = null;
        try {
            $queue = $this->work->output('directories.jsonl', $s['queue_size']);
            $paths = $this->work->output('paths.jsonl', $s['paths_size']);
            while (true) {
                if (microtime(true) >= $until) {
                    throw new PreparationRequiresCliException('Directory enumeration requires CLI preparation.');
                }
                $name = readdir($dir);
                if ($name === false) { break; }
                if ($name === '.' || $name === '..') { continue; }
                $path = $record['path'] === '' ? $name : $record['path'] . '/' . $name;
                MediaEntry::validatePath($path);
                $stat = $this->source->snapshot($path);
                $kind = $stat['mode'] & 0170000;
                $encoded = MediaPreparationWorkspace::encode(['path' => $path, 'identity' => $stat]);
                if ($kind === 0040000) {
                    MediaInventoryIO::write($queue, $encoded);
                } elseif ($kind === 0100000 && ($stat['mode'] & 0444) !== 0) {
                    MediaInventoryIO::write($paths, $encoded);
                    ++$s['expected_files'];
                    $s['expected_bytes'] = self::add($s['expected_bytes'], $stat['size']);
                } else { throw new RuntimeException('Unsupported or unreadable media entry.'); }
            }
            if ($this->source->snapshot($record['path']) !== $before) { throw new RuntimeException('Directory changed during enumeration.'); }
            $s['queue_size'] = MediaPreparationWorkspace::finish($queue);
            $s['paths_size'] = MediaPreparationWorkspace::finish($paths);
            $s['queue_cursor'] = $cursor;
            return $s;
        } finally {
            closedir($dir);
            foreach ([$queue, $paths] as $stream) {
                if (is_resource($stream)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    fclose($stream);
                }
            }
        }
    }

    private function nextFile(array $s, string $run): array
    {
        if (! isset($s['inventory_offset'])) {
            $header = MediaPreparationWorkspace::encode(['format' => 'odbfs3-media-inventory', 'version' => 1, 'algorithm' => 'sha256']);
            $s['inventory_offset'] = $this->append('inventory.jsonl', 0, $header);
            $hash = hash_init('sha256'); hash_update($hash, $header);
            $s['inventory_hash'] = $this->saveHashes($run, ['inventory' => $hash]);
            $s['file_cursor'] = $s['files'] = $s['bytes'] = $s['plan_size'] = 0;
            $s['plan_chain'] = str_repeat('0', 64);
            $s['previous_path'] = null;
            return $s;
        }
        $line = $s['sorted'] === null ? null
            : $this->work->line($s['sorted']['name'], $s['file_cursor'], $s['sorted']['size']);
        if ($line === null) {
            if ($s['files'] !== $s['expected_files'] || $s['bytes'] !== $s['expected_bytes']) {
                throw new RuntimeException('Enumeration and prepared totals differ.');
            }
            $hash = $this->loadHashes($run, $s['inventory_hash'])['inventory'];
            $footer = MediaPreparationWorkspace::encode(['type' => 'inventory_end', 'files' => $s['files'],
                'bytes' => $s['bytes'], 'sha256' => hash_final(hash_copy($hash))]);
            $s['inventory_offset'] = $this->append('inventory.jsonl', $s['inventory_offset'], $footer);
            hash_update($hash, $footer);
            $s['inventory_sha256'] = hash_final($hash);
            $s['object'] = ['path' => null, 'size' => $s['inventory_offset'], 'sha256' => $s['inventory_sha256']];
            return $this->beginParts($s, $run);
        }
        $record = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        if ($s['previous_path'] !== null && strcmp($s['previous_path'], $record['path']) >= 0) {
            throw new RuntimeException('Media paths are duplicated or unordered.');
        }
        $s['path'] = $record['path'];
        $s['expected_identity'] = $record['identity'];
        $this->assertSource($s);
        $s['phase'] = 'file_hash';
        unset($s['hash_checkpoint'], $s['hash_offset'], $s['file_size'], $s['file_sha256']);
        return $s;
    }

    private function beginParts(array $s, string $run): array
    {
        $size = $s['object']['size'];
        $s['part_size'] = max(8388608, (int) ceil($size / 10000 / 1048576) * 1048576);
        if ($s['part_size'] > 5368709120) { throw new RuntimeException('Media object exceeds S3 limits.'); }
        $s['part_offset'] = $s['part_bytes'] = $s['part_list_size'] = 0;
        $s['part_hashes'] = $this->saveHashes($run, ['whole' => hash_init('sha256'), 'part' => hash_init('sha256')]);
        $s['phase'] = 'parts';
        return $s;
    }

    private function parts(array $s, string $run, int $deadline): array
    {
        $object = $s['object'];
        $inventory = $object['path'] === null;
        if (! $inventory) { $this->assertSource($s); }
        $stream = $inventory ? $this->work->read('inventory.jsonl') : $this->source->openFile($object['path']);
        $list = null;
        try {
            if (fstat($stream)['size'] !== $object['size'] || fseek($stream, $s['part_offset']) !== 0) {
                throw new RuntimeException('Media object changed before part preparation.');
            }
            $hashes = $this->loadHashes($run, $s['part_hashes']);
            $list = $this->work->output('part-hashes.txt', $s['part_list_size']);
            $read = 0; $until = min(microtime(true) + 2, $deadline - 3);
            while ($s['part_offset'] < $object['size'] && $read < 8388608 && microtime(true) < $until) {
                $length = min(1048576, 8388608 - $read, $object['size'] - $s['part_offset'], $s['part_size'] - $s['part_bytes']);
                // Fixed-size reads for both complete and multipart SHA-256 values.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                $chunk = fread($stream, $length);
                if ($chunk === false || $chunk === '') { throw new RuntimeException('Incomplete media input.'); }
                hash_update($hashes['whole'], $chunk); hash_update($hashes['part'], $chunk);
                $length = strlen($chunk); $read += $length;
                $s['part_offset'] += $length; $s['part_bytes'] += $length;
                if ($s['part_bytes'] === $s['part_size'] || $s['part_offset'] === $object['size']) {
                    MediaInventoryIO::write($list, base64_encode(hash_final($hashes['part'], true)) . "\n");
                    $hashes['part'] = hash_init('sha256'); $s['part_bytes'] = 0;
                }
            }
            if (! $inventory) { $this->assertSource($s); }
            if (fstat($stream)['size'] !== $object['size']) { throw new RuntimeException('Media input size changed.'); }
            if ($read === 0 && $s['part_offset'] !== $object['size']) {
                throw new RetryableJobException('Part hashing needs a fresh lease.');
            }
            $s['part_list_size'] = MediaPreparationWorkspace::finish($list);
            $s['part_hashes'] = $this->saveHashes($run, $hashes);
            if ($s['part_offset'] !== $object['size']) { return $s; }
            if (! hash_equals($object['sha256'], hash_final($hashes['whole']))) {
                throw new RuntimeException('Media input no longer matches its inventory hash.');
            }
        } finally {
            foreach ([$stream, $list] as $handle) {
                if (is_resource($handle)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    fclose($handle);
                }
            }
        }
        // S3 itself bounds one descriptor to 10,000 hashes, independent of dataset size.
        $count = (int) ceil($object['size'] / $s['part_size']);
        if ($count > 10000 || $s['part_list_size'] !== $count * 45) { throw new RuntimeException('Invalid part list.'); }
        $list = $this->work->read('part-hashes.txt');
        $parts = [];
        try {
            for ($i = 0; $i < $count; ++$i) {
                $line = fgets($list, 46);
                if ($line === false || strlen($line) !== 45 || substr($line, -1) !== "\n"
                    || strlen((string) base64_decode(substr($line, 0, -1), true)) !== 32) {
                    throw new RuntimeException('Invalid prepared checksum.');
                }
                $parts[] = substr($line, 0, -1);
            }
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($list);
        }
        $line = json_encode($object + ['part_size' => $s['part_size'], 'parts' => $parts], JSON_THROW_ON_ERROR) . "\n";
        $s['plan_size'] = $this->append('objects.jsonl', $s['plan_size'], $line);
        $s['plan_chain'] = hash('sha256', $s['plan_chain'] . hash('sha256', $line));
        if ($inventory) {
            $s['phase'] = 'validate_directories'; $s['validation_cursor'] = 0;
        } else {
            $line = (new MediaEntry($object['path'], $object['size'], $object['sha256']))->encode();
            $s['inventory_offset'] = $this->append('inventory.jsonl', $s['inventory_offset'], $line);
            $hash = $this->loadHashes($run, $s['inventory_hash'])['inventory']; hash_update($hash, $line);
            $s['inventory_hash'] = $this->saveHashes($run, ['inventory' => $hash]);
            ++$s['files']; $s['bytes'] = self::add($s['bytes'], $object['size']);
            $s['previous_path'] = $object['path']; $s['phase'] = 'files';
        }
        return $s;
    }

    private function validateDirectory(array $s): array
    {
        $line = $this->work->line('directories.jsonl', $s['validation_cursor'], $s['queue_size']);
        if ($line !== null) {
            $record = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            if ($this->source->snapshot($record['path']) !== $record['identity']) {
                throw new RuntimeException('Media directory changed during preparation.');
            }
            return $s;
        }
        $metadata = ['version' => 1, 'root' => $s['root'], 'files' => $s['files'], 'bytes' => $s['bytes'],
            'inventory_sha256' => $s['inventory_sha256'], 'plan_size' => $s['plan_size'], 'plan_chain' => $s['plan_chain']];
        $this->append('ready.json', 0, MediaPreparationWorkspace::encode($metadata));
        // File completion is NOT remote backup success. Only now may upload start.
        return ['directory' => $s['directory'], 'metadata' => $metadata, 'region' => $s['region'],
            'bucket' => $s['bucket'], 'prefix' => $s['prefix'], 'offset' => 0,
            'work_identity' => $s['work_identity']];
    }

    private function assertSource(array $s): void
    {
        if ($this->source->snapshot($s['path']) !== $s['expected_identity']) {
            throw new RuntimeException('Enumerated media source changed.');
        }
    }

    private function append(string $name, int $offset, string $bytes): int
    {
        $stream = $this->work->output($name, $offset);
        try { MediaInventoryIO::write($stream, $bytes); return MediaPreparationWorkspace::finish($stream); }
        finally {
            if (is_resource($stream)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($stream);
            }
        }
    }

    private function saveHashes(string $run, array $hashes): array
    {
        return $this->work->checkpoints->save(['run' => $run, 'runtime' => MediaFileHashStep::runtime(),
            'hashes' => array_map(static fn ($hash): string => base64_encode(serialize($hash)), $hashes)]);
    }

    private function loadHashes(string $run, array $reference): array
    {
        $saved = $this->work->checkpoints->load($reference);
        if (($saved['run'] ?? null) !== $run || ($saved['runtime'] ?? null) !== MediaFileHashStep::runtime()
            || ! is_array($saved['hashes'] ?? null)) { throw new RuntimeException('Invalid preparation hash binding.'); }
        return array_map([MediaFileHashStep::class, 'restoreHash'], $saved['hashes']);
    }

    private static function add(int $total, int $size): int
    {
        if ($size < 0 || $size > PHP_INT_MAX - $total) { throw new RuntimeException('Media size overflow.'); }
        return $total + $size;
    }
}
