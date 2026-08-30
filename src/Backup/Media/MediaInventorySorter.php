<?php

namespace SecureS3StorageForWordpress\Backup\Media;

use Generator;
use InvalidArgumentException;
use RuntimeException;

/** External merge sort: bounded batches, two input streams, logarithmic run metadata. */
final class MediaInventorySorter
{
    public function __construct(private int $batchBytes = 2097152)
    {
        if ($batchBytes < 1) {
            throw new InvalidArgumentException('Invalid media sort budget.');
        }
    }

    /** @param iterable<MediaEntry> $entries @return Generator<int, MediaEntry> */
    public function sorted(iterable $entries, string $workParent): Generator
    {
        $work = new MediaWorkDirectory($workParent);
        $levels = [];
        $batch = [];
        $size = 0;
        try {
            foreach ($entries as $entry) {
                if (! $entry instanceof MediaEntry) {
                    throw new RuntimeException('Invalid media sort input.');
                }
                $batch[] = $entry;
                $size += strlen($entry->encode());
                if ($size >= $this->batchBytes) {
                    $this->carry($this->spill($batch, $work), $levels, $work);
                    $batch = [];
                    $size = 0;
                }
            }
            if ($batch !== []) {
                $this->carry($this->spill($batch, $work), $levels, $work);
            }
            unset($batch);
            $final = null;
            foreach ($levels as $path) {
                $final = $final === null ? $path : $this->merge($final, $path, $work);
            }
            if ($final === null) {
                return;
            }
            $stream = MediaInventoryIO::openRead($final);
            try {
                $previous = null;
                while (($line = MediaInventoryIO::readLine($stream)) !== null) {
                    $entry = MediaEntry::decode($line);
                    if ($previous !== null && strcmp($previous, $entry->path) >= 0) {
                        throw new RuntimeException('Duplicate or unordered media path.');
                    }
                    $previous = $entry->path;
                    yield $entry;
                }
            } finally {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($stream);
            }
        } finally {
            $work->close();
        }
    }

    private function spill(array $batch, MediaWorkDirectory $work): string
    {
        usort($batch, static fn (MediaEntry $a, MediaEntry $b): int => strcmp($a->path, $b->path));
        [$path, $stream] = $work->createFile();
        try {
            foreach ($batch as $entry) {
                MediaInventoryIO::write($stream, $entry->encode());
            }
            MediaInventoryIO::finish($stream);
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
        }
        return $path;
    }

    private function carry(string $path, array &$levels, MediaWorkDirectory $work): void
    {
        $level = 0;
        while (isset($levels[$level])) {
            $path = $this->merge($levels[$level], $path, $work);
            unset($levels[$level]);
            ++$level;
        }
        $levels[$level] = $path;
    }

    private function merge(string $left, string $right, MediaWorkDirectory $work): string
    {
        $streams = [];
        try {
            $streams[] = MediaInventoryIO::openRead($left);
            $streams[] = MediaInventoryIO::openRead($right);
            [$path, $output] = $work->createFile();
            $streams[] = $output;
            $a = $this->next($streams[0]);
            $b = $this->next($streams[1]);
            while ($a !== null || $b !== null) {
                if ($a !== null && $b !== null && $a->path === $b->path) {
                    throw new RuntimeException('Duplicate media path.');
                }
                if ($b === null || ($a !== null && strcmp($a->path, $b->path) < 0)) {
                    MediaInventoryIO::write($output, $a->encode());
                    $a = $this->next($streams[0]);
                } else {
                    MediaInventoryIO::write($output, $b->encode());
                    $b = $this->next($streams[1]);
                }
            }
            MediaInventoryIO::finish($output);
        } finally {
            foreach ($streams as $stream) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($stream);
            }
        }
        $work->remove($left);
        $work->remove($right);
        return $path;
    }

    /** @param resource $stream */
    private function next($stream): ?MediaEntry
    {
        $line = MediaInventoryIO::readLine($stream);
        return $line === null ? null : MediaEntry::decode($line);
    }
}
