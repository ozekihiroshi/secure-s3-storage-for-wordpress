<?php

namespace SecureS3StorageForWordpress\Backup\Media;

use RuntimeException;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;

/** Private, prepared upload plan. Preparation is CLI-only, not a short Cron step. */
final class MediaUploadPlan
{
    public function __construct(public readonly string $directory)
    {
        $stat = @lstat($directory);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000
            || (DIRECTORY_SEPARATOR !== '\\' && ($stat['mode'] & 0077) !== 0)) {
            throw new RuntimeException('Media plan directory must be private.');
        }
    }

    public static function prepare(MediaSource $source, string $parent): self
    {
        $parent = $source->externalDirectory($parent);
        $directory = $parent . '/odbfs3-' . bin2hex(random_bytes(16));
        // Private from creation; do not remove partial plans automatically.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create media plan.');
        }
        $plan = new self($directory);
        $manifest = $directory . '/inventory.jsonl';
        $summary = (new MediaManifest())->create($source, $manifest, $directory);
        $output = SecureTemporaryFile::openForWriting($directory . '/objects.jsonl');
        $chain = str_repeat('0', 64);
        try {
            foreach ((new MediaManifest())->entries($manifest) as $entry) {
                $stream = $source->openFile($entry->path);
                try {
                    $record = self::describe($stream, $entry->path, $entry->size, $entry->sha256);
                    $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
                    $chain = hash('sha256', $chain . hash('sha256', $line));
                    MediaInventoryIO::write($output, $line);
                } finally {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    fclose($stream);
                }
            }
            $stream = MediaInventoryIO::openRead($manifest);
            try {
                $size = fstat($stream)['size'];
                $manifestHash = hash_file('sha256', $manifest);
                $record = self::describe($stream, null, $size, $manifestHash);
                $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
                $chain = hash('sha256', $chain . hash('sha256', $line));
                MediaInventoryIO::write($output, $line);
            } finally {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($stream);
            }
            MediaInventoryIO::finish($output);
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($output);
        }
        // Published last: incomplete preparation cannot be submitted.
        $metadata = ['version' => 1, 'root' => $source->rootPath(), 'files' => $summary['files'],
            'bytes' => $summary['bytes'], 'inventory_sha256' => $manifestHash,
            'plan_size' => filesize($directory . '/objects.jsonl'), 'plan_chain' => $chain];
        $output = SecureTemporaryFile::openForWriting($directory . '/ready.json');
        try {
            MediaInventoryIO::write($output, json_encode($metadata, JSON_THROW_ON_ERROR) . "\n");
            MediaInventoryIO::finish($output);
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($output);
        }
        return $plan;
    }

    public function metadata(): array
    {
        $stream = $this->open('ready.json');
        try {
            $value = json_decode(MediaInventoryIO::readLine($stream) ?? '', true, 8, JSON_THROW_ON_ERROR);
            if (($value['version'] ?? null) !== 1 || ! is_string($value['root'] ?? null)
                || ! is_int($value['files'] ?? null) || $value['files'] < 0
                || ! is_int($value['bytes'] ?? null) || $value['bytes'] < 0
                || ! is_int($value['plan_size'] ?? null) || $value['plan_size'] < 1
                || ! preg_match('/^[a-f0-9]{64}$/D', $value['plan_chain'] ?? '')
                || ! preg_match('/^[a-f0-9]{64}$/D', $value['inventory_sha256'] ?? '')) {
                throw new RuntimeException('Invalid media plan.');
            }
            return $value;
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
        }
    }

    /** @return array{0: array|null, 1: int} */
    public function record(int $offset): array
    {
        $stream = $this->open('objects.jsonl');
        try {
            if ($offset < 0 || fseek($stream, $offset) !== 0) {
                throw new RuntimeException('Invalid media plan cursor.');
            }
            // At most 10,000 S3 parts per file; this bounds one descriptor, not the dataset.
            $line = fgets($stream, 2097153);
            if ($line === false && feof($stream)) {
                return [null, $offset];
            }
            if ($line === false || ! str_ends_with($line, "\n")) {
                throw new RuntimeException('Invalid media object record.');
            }
            $record = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($record) || ! array_key_exists('path', $record)
                || ! is_int($record['size'] ?? null) || $record['size'] < 0
                || ! is_int($record['part_size'] ?? null) || $record['part_size'] < 8388608
                || $record['part_size'] > 5368709120
                || ! preg_match('/^[a-f0-9]{64}$/D', $record['sha256'] ?? '')
                // Avoid ambiguity with WordPress's later array_is_list() polyfill.
                || ! is_array($record['parts'] ?? null)
                || array_values($record['parts']) !== $record['parts']
                || count($record['parts']) > 10000
                || count($record['parts']) !== (int) ceil($record['size'] / $record['part_size'])) {
                throw new RuntimeException('Invalid media object record.');
            }
            if ($record['path'] !== null) {
                MediaEntry::validatePath($record['path']);
            }
            foreach ($record['parts'] as $hash) {
                if (! is_string($hash) || strlen((string) base64_decode($hash, true)) !== 32) {
                    throw new RuntimeException('Invalid media part checksum.');
                }
            }
            $record['record_hash'] = hash('sha256', $line);
            return [$record, ftell($stream)];
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
        }
    }

    /** @return resource */
    public function open(string $name)
    {
        if (! in_array($name, ['ready.json', 'objects.jsonl', 'inventory.jsonl'], true)) {
            throw new RuntimeException('Invalid media plan file.');
        }
        $stream = MediaInventoryIO::openRead($this->directory . '/' . $name);
        $stat = fstat($stream);
        if ($stat['nlink'] !== 1 || (DIRECTORY_SEPARATOR !== '\\' && ($stat['mode'] & 0077) !== 0)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
            throw new RuntimeException('Media plan file must be private.');
        }
        return $stream;
    }

    /** @param resource $stream */
    private static function describe($stream, ?string $path, int $size, string $expected): array
    {
        // Adapt to S3's 10,000-part limit without an arbitrary plugin size cap.
        $partSize = max(8388608, (int) ceil($size / 10000 / 1048576) * 1048576);
        if ($partSize > 5368709120) {
            throw new RuntimeException('Media object exceeds multipart limits.');
        }
        $whole = hash_init('sha256');
        $parts = [];
        $total = 0;
        while ($total < $size) {
            $hash = hash_init('sha256');
            $remaining = min($partSize, $size - $total);
            while ($remaining > 0) {
                // Fixed-size reads keep preparation memory independent of file size.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                $chunk = fread($stream, min(1048576, $remaining));
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Media file changed during preparation.');
                }
                hash_update($whole, $chunk);
                hash_update($hash, $chunk);
                $remaining -= strlen($chunk);
                $total += strlen($chunk);
            }
            $parts[] = base64_encode(hash_final($hash, true));
        }
        // A changed source must never get a prepared plan with the old full-file hash.
        if (fstat($stream)['size'] !== $size || ! hash_equals($expected, hash_final($whole))) {
            throw new RuntimeException('Media content no longer matches the inventory.');
        }
        return ['path' => $path, 'size' => $size, 'sha256' => $expected,
            'part_size' => $partSize, 'parts' => $parts];
    }
}
