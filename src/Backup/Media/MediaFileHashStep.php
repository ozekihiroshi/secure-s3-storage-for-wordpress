<?php

namespace SecureS3StorageForWordpress\Backup\Media;

use Closure;
use HashContext;
use RuntimeException;
use SecureS3StorageForWordpress\Backup\Job\BackupJob;
use SecureS3StorageForWordpress\Backup\Job\JobStep;
use SecureS3StorageForWordpress\Backup\Job\RetryableJobException;
use SecureS3StorageForWordpress\Backup\Job\StepResult;

/** One file's preparation phase, not a complete backup or an enumeration worker. */
final class MediaFileHashStep implements JobStep
{
    private Closure $clock;

    public function __construct(
        private MediaSource $source,
        private MediaHashCheckpointStore $store,
        private int $byteBudget = 8388608,
        ?Closure $clock = null,
    ) {
        if ($byteBudget < 1 || $byteBudget > 8388608) {
            throw new RuntimeException('Invalid file hash step budget.');
        }
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function execute(BackupJob $job, int $deadline): StepResult
    {
        $state = $job->checkpoint;
        if ($job->type !== 'media' || $job->leaseToken === '' || $deadline !== $job->leaseUntil
            || ($state['phase'] ?? null) !== 'file_hash' || ! is_string($state['path'] ?? null)) {
            throw new RuntimeException('Invalid file hash phase.');
        }
        if (($this->clock)() >= $deadline - 1) {
            throw new RetryableJobException('File hash lease has insufficient time.');
        }
        $started = hrtime(true);
        $stream = $this->source->openFile($state['path']);
        try {
            $identity = self::fileIdentity(fstat($stream));
            clearstatcache(true, $this->source->rootPath());
            $root = lstat($this->source->rootPath());
            $binding = ['run' => $job->id, 'root' => $this->source->rootPath(),
                'root_identity' => [$root['dev'], $root['ino'], $root['mode']],
                'path' => $state['path'], 'identity' => $identity];
            $offset = 0;
            $hash = hash_init('sha256');
            if (isset($state['hash_checkpoint'])) {
                $saved = $this->store->load($state['hash_checkpoint']);
                if (($saved['version'] ?? null) !== 1 || ($saved['runtime'] ?? null) !== self::runtime()
                    || ($saved['binding'] ?? null) !== $binding || ! is_int($saved['offset'] ?? null)
                    || $saved['offset'] < 0 || $saved['offset'] > $identity['size']
                    || ($state['hash_offset'] ?? null) !== $saved['offset']) {
                    throw new RuntimeException('Hash source, runtime or cursor changed.');
                }
                $offset = $saved['offset'];
                $hash = self::restoreHash($saved['hash'] ?? null);
            } elseif (isset($state['hash_offset'])) {
                throw new RuntimeException('Missing hash checkpoint.');
            }
            if (fseek($stream, $offset) !== 0) {
                throw new RuntimeException('Cannot seek media input.');
            }
            $read = 0;
            while ($offset < $identity['size'] && $read < $this->byteBudget) {
                // Cooperative time bound; a blocking filesystem syscall is not a watchdog.
                if (($this->clock)() >= $deadline - 1 || hrtime(true) - $started >= 2000000000) {
                    break;
                }
                $length = min(1048576, $this->byteBudget - $read, $identity['size'] - $offset);
                // Streaming avoids loading a large source file into memory.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                $chunk = fread($stream, $length);
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Media input ended or could not be read.');
                }
                hash_update($hash, $chunk);
                $offset += strlen($chunk);
                $read += strlen($chunk);
            }
            if (self::fileIdentity(fstat($stream)) !== $identity) {
                throw new RuntimeException('Media input changed during hashing.');
            }
            // Re-resolve the name after reading: catch replacement, links and root changes.
            $reopened = $this->source->openFile($state['path']);
            try {
                if (self::fileIdentity(fstat($reopened)) !== $identity) {
                    throw new RuntimeException('Media input path changed during hashing.');
                }
            } finally {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($reopened);
            }
            if ($read === 0 && $offset < $identity['size']) {
                throw new RetryableJobException('File hash step needs a fresh lease.');
            }
            $state['hash_checkpoint'] = $this->store->save(['version' => 1, 'runtime' => self::runtime(),
                'binding' => $binding, 'offset' => $offset, 'hash' => base64_encode(serialize($hash))]);
            $state['hash_offset'] = $offset;
            if ($offset === $identity['size']) {
                $state['phase'] = 'file_hashed';
                $state['file_sha256'] = hash_final($hash);
                $state['file_size'] = $offset;
            }
            // Upload counters and backup completion remain untouched. A future
            // preparation dispatcher must handle file_hashed and advance enumeration.
            return new StepResult($state, $job->processedFiles, $job->processedBytes);
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
        }
    }

    public static function runtime(): array
    {
        return [PHP_VERSION, PHP_INT_SIZE, PHP_OS_FAMILY, php_uname('m'), PHP_ZTS, PHP_DEBUG];
    }

    public static function restoreHash(mixed $encoded): HashContext
    {
        if (! is_string($encoded) || strlen($encoded) > 4096) {
            throw new RuntimeException('Invalid private hash state.');
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || strlen($bytes) > 2048 || ! str_starts_with($bytes, 'O:11:"HashContext":')) {
            throw new RuntimeException('Invalid private hash state encoding.');
        }
        // Only an integrity-checked, locally generated private file may reach this
        // call. Never use this as an import API; application/SDK classes are denied.
        $hash = unserialize($bytes, ['allowed_classes' => [HashContext::class], 'max_depth' => 8]);
        if (! $hash instanceof HashContext || ($hash->__serialize()[0] ?? null) !== 'sha256'
            || ($hash->__serialize()[1] ?? null) !== 0) {
            throw new RuntimeException('Unexpected hash context.');
        }
        return $hash;
    }

    private static function fileIdentity(array|false $stat): array
    {
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
            || ! is_int($stat['size']) || $stat['size'] < 0) {
            throw new RuntimeException('Invalid media file identity.');
        }
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime']));
    }
}
