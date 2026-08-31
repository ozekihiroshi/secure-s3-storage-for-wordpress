<?php

namespace SecureS3StorageForWordpress\Backup\Media;

use RuntimeException;

/** Bounded external merge sort. All cursors are selected by the locked job CAS. */
final class MediaPreparationSort
{
    public function __construct(private MediaPreparationWorkspace $work, private int $batch = 128) {}

    public function step(array $s, int $deadline): array
    {
        $until = min(microtime(true) + 2, $deadline - 3);
        if ($s['phase'] === 'sort_runs') { return $this->runs($s, $until); }
        if (! isset($s['merge_pair'])) {
            $a = $this->descriptor($s);
            if ($a === null) {
                if ($s['out_count'] === 1) {
                    $cursor = 0;
                    $one = json_decode($this->work->line('runs-' . ($s['pass'] + 1) . '.jsonl', $cursor, $s['out_list_size']), true, 8, JSON_THROW_ON_ERROR);
                    $s['sorted'] = $one;
                    $s['phase'] = 'files';
                    return $s;
                }
                ++$s['pass'];
                $s['run_list_size'] = $s['out_list_size'];
                $s['run_cursor'] = $s['out_count'] = $s['out_list_size'] = 0;
                return $s;
            }
            $b = $this->descriptor($s);
            if ($b === null) {
                $this->appendDescriptor($s, $a);
                return $s;
            }
            $s['merge_pair'] = [$a, $b];
            $s['merge_offsets'] = [0, 0];
            $s['merge_output'] = 0;
        }
        $name = 'sort-' . ($s['pass'] + 1) . '-' . $s['out_count'] . '.jsonl';
        $out = $this->work->output($name, $s['merge_output']);
        $written = 0;
        try {
            for ($count = 0; $count < $this->batch && $written < 262144
                && ($count === 0 || microtime(true) < $until); ++$count) {
                $cursors = $s['merge_offsets'];
                $lines = [];
                foreach ([0, 1] as $i) {
                    $descriptor = $s['merge_pair'][$i];
                    $lines[$i] = $this->work->line($descriptor['name'], $cursors[$i], $descriptor['size']);
                }
                if ($lines === [null, null]) { break; }
                if ($lines[0] === null) { $take = 1; }
                elseif ($lines[1] === null) { $take = 0; }
                else {
                    $a = json_decode($lines[0], true, 8, JSON_THROW_ON_ERROR);
                    $b = json_decode($lines[1], true, 8, JSON_THROW_ON_ERROR);
                    $compare = strcmp($a['path'], $b['path']);
                    if ($compare === 0) { throw new RuntimeException('Duplicate media path.'); }
                    $take = $compare < 0 ? 0 : 1;
                }
                MediaInventoryIO::write($out, $lines[$take]);
                $written += strlen($lines[$take]);
                $s['merge_offsets'][$take] = $cursors[$take];
            }
            $s['merge_output'] = MediaPreparationWorkspace::finish($out);
        } finally {
            if (is_resource($out)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($out);
            }
        }
        if ($s['merge_offsets'][0] === $s['merge_pair'][0]['size']
            && $s['merge_offsets'][1] === $s['merge_pair'][1]['size']) {
            $this->appendDescriptor($s, ['name' => $name, 'size' => $s['merge_output']]);
            unset($s['merge_pair'], $s['merge_offsets'], $s['merge_output']);
        }
        return $s;
    }

    private function runs(array $s, float $until): array
    {
        if ($s['sort_cursor'] === $s['paths_size']) {
            if ($s['run_count'] <= 1) {
                $cursor = 0;
                $s['sorted'] = $s['run_count'] === 0 ? null
                    : json_decode($this->work->line('runs-0.jsonl', $cursor, $s['run_list_size']), true, 8, JSON_THROW_ON_ERROR);
                $s['phase'] = 'files';
            } else {
                $s['phase'] = 'sort_merge';
                $s['pass'] = $s['run_cursor'] = $s['out_count'] = $s['out_list_size'] = 0;
            }
            return $s;
        }
        $records = [];
        $bytes = 0;
        while (count($records) < $this->batch && $bytes < 262144
            && ($records === [] || microtime(true) < $until)
            && ($line = $this->work->line('paths.jsonl', $s['sort_cursor'], $s['paths_size'])) !== null) {
            $record = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            MediaEntry::validatePath($record['path']);
            $records[] = $record;
            $bytes += strlen($line);
        }
        usort($records, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        $name = 'sort-0-' . $s['run_count'] . '.jsonl';
        $out = $this->work->output($name, 0);
        try {
            $last = null;
            foreach ($records as $record) {
                if ($last === $record['path']) { throw new RuntimeException('Duplicate media path.'); }
                $last = $record['path'];
                MediaInventoryIO::write($out, MediaPreparationWorkspace::encode($record));
            }
            $size = MediaPreparationWorkspace::finish($out);
        } finally {
            if (is_resource($out)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($out);
            }
        }
        $out = $this->work->output('runs-0.jsonl', $s['run_list_size']);
        try {
            MediaInventoryIO::write($out, MediaPreparationWorkspace::encode(['name' => $name, 'size' => $size]));
            $s['run_list_size'] = MediaPreparationWorkspace::finish($out);
        } finally {
            if (is_resource($out)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($out);
            }
        }
        ++$s['run_count'];
        return $s;
    }

    private function descriptor(array &$s): ?array
    {
        $line = $this->work->line('runs-' . $s['pass'] . '.jsonl', $s['run_cursor'], $s['run_list_size']);
        return $line === null ? null : json_decode($line, true, 8, JSON_THROW_ON_ERROR);
    }

    private function appendDescriptor(array &$s, array $descriptor): void
    {
        $out = $this->work->output('runs-' . ($s['pass'] + 1) . '.jsonl', $s['out_list_size']);
        try {
            MediaInventoryIO::write($out, MediaPreparationWorkspace::encode($descriptor));
            $s['out_list_size'] = MediaPreparationWorkspace::finish($out);
        } finally {
            if (is_resource($out)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($out);
            }
        }
        ++$s['out_count'];
    }
}
