<?php

// CLI-only synthetic data; no WordPress bootstrap, credentials, network or dependencies.
if (PHP_SAPI !== 'cli') {
    exit;
}

function odbfs3_fixture_number(string $value, int $minimum): int
{
    if (! preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
        throw new RuntimeException('Fixture sizes/counts must be decimal integers.');
    }
    $number = filter_var($value, FILTER_VALIDATE_INT);
    if ($number === false || $number < $minimum) {
        throw new RuntimeException('Fixture size/count is out of range.');
    }
    return $number;
}

function odbfs3_fixture_write($stream, string $data): void
{
    $offset = 0;
    while ($offset < strlen($data)) {
        $written = fwrite($stream, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Unable to write fixture data.');
        }
        $offset += $written;
    }
}

function odbfs3_fixture_png(int $width, int $height): string
{
    $chunk = static fn (string $type, string $data): string =>
        pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $pixels = '';
    for ($row = 0; $row < $height; ++$row) {
        $pixels .= "\0" . random_bytes($width * 3);
    }
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        . $chunk('IDAT', gzcompress($pixels))
        . $chunk('IEND', '');
}

try {
    if ($argc < 2 || in_array('--help', $argv, true)) {
        echo "Usage: php tools/create-media-fixtures.php NEW_DIRECTORY [--large-mib=256] [--small-files=1000] [--small-bytes=4096]\n";
        echo "NEW_DIRECTORY must not exist. Prefer build/ locally or a private test directory on the server.\n";
        exit($argc < 2 ? 1 : 0);
    }
    $values = ['large-mib' => 256, 'small-files' => 1000, 'small-bytes' => 4096];
    foreach (array_slice($argv, 2) as $argument) {
        if (! preg_match('/^--(large-mib|small-files|small-bytes)=(.+)$/D', $argument, $match)) {
            throw new RuntimeException('Unknown fixture argument.');
        }
        $values[$match[1]] = odbfs3_fixture_number($match[2], $match[1] === 'small-files' ? 0 : 1);
    }
    $largeBytes = $values['large-mib'] * 1048576;
    $smallBytes = $values['small-files'] * $values['small-bytes'];
    $required = $largeBytes + $smallBytes + 67108864;
    if (! is_int($required) || $required < 0) {
        throw new RuntimeException('Fixture byte count exceeds this PHP platform.');
    }

    $requested = rtrim($argv[1], '/\\');
    $parent = realpath(dirname($requested));
    $name = basename($requested);
    if ($parent === false || ! is_dir($parent) || in_array($name, ['', '.', '..'], true)) {
        throw new RuntimeException('The parent directory must already exist.');
    }
    $output = $parent . DIRECTORY_SEPARATOR . $name;
    $repository = realpath(__DIR__ . '/..');
    $normalized = str_replace('\\', '/', $output);
    $repo = str_replace('\\', '/', $repository);
    if (DIRECTORY_SEPARATOR === '\\') {
        $normalized = strtolower($normalized);
        $repo = strtolower($repo);
    }
    if (($normalized === $repo || str_starts_with($normalized, $repo . '/'))
        && ! str_starts_with($normalized, $repo . '/build/')) {
        throw new RuntimeException('Inside this repository, fixtures must be below ignored build/.');
    }
    if (file_exists($output) || is_link($output)) {
        throw new RuntimeException('Output already exists; it will not be overwritten.');
    }
    $available = disk_free_space($parent);
    if ($available === false || $available < $required) {
        throw new RuntimeException('Insufficient free disk space (64 MiB safety allowance required).');
    }
    umask(0077);
    if (! mkdir($output, 0700)) {
        throw new RuntimeException('Unable to create private fixture directory.');
    }
    echo 'fixture_directory=' . $output . PHP_EOL;
    echo 'planned_payload_bytes=' . ($largeBytes + $smallBytes) . PHP_EOL;
    foreach (['uploads', 'uploads/large', 'uploads/many', 'uploads/images'] as $directory) {
        if (! mkdir($output . '/' . $directory, 0700)) {
            throw new RuntimeException('Unable to create fixture subdirectory.');
        }
    }
    $expectedPath = $output . '/expected.jsonl';
    $expected = fopen($expectedPath, 'xb');
    if ($expected === false) {
        throw new RuntimeException('Unable to create expected checksums.');
    }
    $files = 0;
    $totalBytes = 0;
    $create = static function (string $relative, int $size, callable $supply) use ($output, $expected, &$files, &$totalBytes): void {
        $stream = fopen($output . '/uploads/' . $relative, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Unable to create fixture file.');
        }
        try {
            $hash = hash_init('sha256');
            $remaining = $size;
            while ($remaining > 0) {
                $length = min($remaining, 1048576);
                $data = $supply($length);
                if (strlen($data) !== $length) {
                    throw new RuntimeException('Invalid fixture data length.');
                }
                odbfs3_fixture_write($stream, $data);
                hash_update($hash, $data);
                $remaining -= $length;
            }
            if (! fflush($stream)) {
                throw new RuntimeException('Unable to flush fixture file.');
            }
        } finally {
            fclose($stream);
        }
        odbfs3_fixture_write($expected, json_encode([
            'path' => $relative, 'size' => $size, 'sha256' => hash_final($hash),
        ], JSON_THROW_ON_ERROR) . "\n");
        ++$files;
        $totalBytes += $size;
    };
    try {
        // Real allocated random bytes, not a sparse file or a compressible zero fill.
        // Deliberately .bin: load fixture, not a video/audio file to play.
        $create('large/payload.bin', $largeBytes, static fn (int $length): string => random_bytes($length));
        for ($i = 0; $i < $values['small-files']; ++$i) {
            $create(sprintf('many/file-%08d.txt', $i), $values['small-bytes'],
                static fn (int $length): string => substr(str_repeat("Synthetic backup test data.\n", (int) ceil($length / 28) + 1), 0, $length));
        }
        foreach (['original.png' => 512, 'thumbnail-150x150.png' => 150, '日本語-画像.png' => 32] as $filename => $side) {
            $png = odbfs3_fixture_png($side, $side);
            $create('images/' . $filename, strlen($png), static fn (int $length): string => substr($png, 0, $length));
        }
        $create('empty.bin', 0, static fn (): string => '');
        $create('.hidden', 32, static fn (int $length): string => str_repeat('h', $length));
        if (! fflush($expected)) {
            throw new RuntimeException('Unable to flush expected checksums.');
        }
    } finally {
        fclose($expected);
    }
    $summary = [
        'format' => 'odbfs3-synthetic-fixture', 'version' => 1,
        'files' => $files, 'bytes' => $totalBytes,
        'expected_sha256' => hash_file('sha256', $expectedPath),
        'large_mib' => $values['large-mib'], 'small_files' => $values['small-files'],
    ];
    $info = fopen($output . '/fixture-info.json', 'xb');
    if ($info === false) {
        throw new RuntimeException('Unable to create fixture completion record.');
    }
    try {
        odbfs3_fixture_write($info, json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        if (! fflush($info)) { throw new RuntimeException('Unable to flush fixture completion record.'); }
    } finally {
        fclose($info);
    }
    echo 'files=' . $files . PHP_EOL . 'bytes=' . $totalBytes . PHP_EOL;
    echo "result=fixture_created\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\nPartial fixtures, if any, are left for inspection; nothing is deleted automatically.\n");
    exit(1);
}
