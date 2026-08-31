<?php

/**
 * Feasibility gate for background preparation, NOT a production checkpoint API.
 * Only generated synthetic bytes and same-run, parent-created hash states enter
 * the child process. No site paths, credentials, files or AWS access are used.
 */

function checkpointTestBytes(int $offset, int $length): string
{
    $pattern = '';
    for ($i = 0; $i < 256; ++$i) {
        $pattern .= chr($i);
    }
    return substr(str_repeat($pattern, intdiv($length + 255, 256) + 1), $offset % 256, $length);
}

function checkpointTestHashRange(HashContext $hash, int $offset, int $end): void
{
    while ($offset < $end) {
        $length = min(65536, $end - $offset);
        hash_update($hash, checkpointTestBytes($offset, $length));
        $offset += $length;
    }
}

if (($argv[1] ?? '') === '--child') {
    // JSON bounds input before decoding; only the parent's own synthetic state
    // is accepted by this test. This must not become a public import endpoint.
    $input = stream_get_contents(STDIN, 4097);
    if (strlen($input) > 4096) {
        throw new RuntimeException('Oversized test input.');
    }
    $data = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
    if (($data['runtime'] ?? null) !== PHP_VERSION_ID . ':' . PHP_INT_SIZE
        || ! is_int($data['offset'] ?? null) || ! is_int($data['end'] ?? null)
        || $data['offset'] < 0 || $data['end'] < $data['offset'] || $data['end'] > 20000000
        || ! is_string($data['state'] ?? null)) {
        throw new RuntimeException('Invalid test checkpoint.');
    }
    $serialized = base64_decode($data['state'], true);
    if ($serialized === false || strlen($serialized) > 2048) {
        throw new RuntimeException('Invalid test hash state.');
    }
    // Allow exactly the PHP built-in class; never permit application/SDK objects.
    $hash = unserialize($serialized, ['allowed_classes' => [HashContext::class], 'max_depth' => 8]);
    if (! $hash instanceof HashContext || ($hash->__serialize()[0] ?? '') !== 'sha256') {
        throw new RuntimeException('Unexpected test hash algorithm.');
    }
    checkpointTestHashRange($hash, $data['offset'], $data['end']);
    echo json_encode([
        'pid' => getmypid(),
        'state' => base64_encode(serialize($hash)),
        'digest' => hash_final(hash_copy($hash)),
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

function checkpointTestChild(array $data): array
{
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=32M', __FILE__, '--child'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start independent PHP test process.');
    }
    $input = json_encode($data, JSON_THROW_ON_ERROR);
    $offset = 0;
    while ($offset < strlen($input)) {
        $written = fwrite($pipes[0], substr($input, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Cannot send test state.');
        }
        $offset += $written;
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Child failed: ' . $error);
    }
    $result = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
    if (($result['pid'] ?? null) === getmypid()) {
        throw new RuntimeException('Hash must resume in a different process.');
    }
    return $result;
}

// Padding, block, buffer and multipart boundaries, including an empty file.
$cases = [[0, 0], [1, 0], [55, 54], [56, 55], [63, 62], [64, 63],
    [65, 64], [127, 65], [65537, 65535], [8388673, 8388607], [17825929, 1048579]];
$checks = 0;
foreach ($cases as [$length, $split]) {
    $hash = hash_init('sha256');
    checkpointTestHashRange($hash, 0, $split);
    $checkpoint = ['runtime' => PHP_VERSION_ID . ':' . PHP_INT_SIZE, 'offset' => $split,
        'end' => $length, 'state' => base64_encode(serialize($hash))];
    // Both children resume the same committed state: replay must be idempotent.
    $first = checkpointTestChild($checkpoint);
    $replay = checkpointTestChild($checkpoint);
    $reference = hash_init('sha256');
    checkpointTestHashRange($reference, 0, $length);
    if ($first['digest'] !== hash_final($reference) || $first['digest'] !== $replay['digest']) {
        throw new RuntimeException('Resumed or replayed SHA-256 differs.');
    }
    ++$checks;
}

// Multiple successive process exits within the same large file.
$state = base64_encode(serialize(hash_init('sha256')));
$offset = 0;
foreach ([63, 1048583, 8388611, 17825929] as $end) {
    $result = checkpointTestChild(['runtime' => PHP_VERSION_ID . ':' . PHP_INT_SIZE,
        'offset' => $offset, 'end' => $end, 'state' => $state]);
    $state = $result['state'];
    $offset = $end;
}
$reference = hash_init('sha256');
checkpointTestHashRange($reference, 0, $offset);
if ($result['digest'] !== hash_final($reference)) {
    throw new RuntimeException('Repeated cross-process SHA-256 differs.');
}
++ $checks;
echo 'php=' . PHP_VERSION . PHP_EOL;
echo 'checks=' . $checks . PHP_EOL;
echo 'php_peak_bytes=' . memory_get_peak_usage(true) . PHP_EOL;
echo "result=native_sha256_checkpoint_verified\n";
