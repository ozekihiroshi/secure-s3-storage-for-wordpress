<?php

declare(strict_types=1);

require __DIR__ . '/test-recovery-collector.php';
require_once __DIR__ . '/../../src/Backup/Media/MediaObjectClient.php';
require_once __DIR__ . '/../../src/Backup/SecureTemporaryFile.php';

use SecureS3StorageForWordpress\Backup\Media\MediaObjectClient;
use SecureS3StorageForWordpress\Backup\Job\RetryableJobException;
use SecureS3StorageForWordpress\Backup\Recovery\{RecoveryCollector, RecoveryWorkspace, RecoveryPublisher, RecoveryProgress};

final class PublicationRemote implements MediaObjectClient
{
    public ?string $bytes = null;
    public string $fault = '';
    public int $puts = 0;
    public array $calls = [];
    public ?Closure $onGet = null;
    public function request(string $operation, array $a, int $deadline): array
    {
        $this->calls[] = $operation;
        if ($operation === 'HeadObject') {
            if ($this->bytes === null) { return ['missing' => true]; }
            return ['ContentLength' => strlen($this->bytes), 'ChecksumSHA256' => $this->fault === 'head' ? 'wrong' : base64_encode(hash('sha256', $this->bytes, true)),
                'ETag' => 'fixture-etag', 'VersionId' => 'v1'];
        }
        if ($operation === 'PutObject') {
            if (($a['IfNoneMatch'] ?? '') !== '*' || $this->bytes !== null) { throw new RuntimeException('Unconditional overwrite'); }
            if ($this->fault === 'before') { $this->fault = ''; throw new RetryableJobException('before'); }
            ++$this->puts;
            $this->bytes = $this->fault === 'race' ? str_repeat('x', strlen($a['Body'])) : $a['Body'];
            if ($this->fault === 'after') { $this->fault = ''; throw new RetryableJobException('lost acknowledgement'); }
            return [];
        }
        if ($operation === 'GetObject') {
            if (($a['IfMatch'] ?? '') !== 'fixture-etag' || ($a['VersionId'] ?? '') !== 'v1'
                || $a['Range'] !== 'bytes=0-' . strlen($this->bytes)) { throw new RuntimeException('Unpinned/unbounded GET'); }
            if ($this->fault === 'get') { $this->fault = ''; throw new RetryableJobException('GET unavailable'); }
            if ($this->onGet !== null) { ($this->onGet)(); }
            $body = fopen('php://temp', 'w+b');
            $payload = match ($this->fault) {
                'body' => str_repeat('x', strlen($this->bytes)),
                'oversized' => $this->bytes . 'x',
                'truncated' => substr($this->bytes, 0, -1),
                default => $this->bytes,
            };
            fwrite($body, $payload);
            rewind($body);
            return ['Body' => $body, 'ContentLength' => strlen($this->bytes), 'VersionId' => $this->fault === 'version' ? 'v2' : 'v1'];
        }
        throw new RuntimeException('Unexpected request');
    }
}

$root = sys_get_temp_dir() . '/odbfs3-publication-' . bin2hex(random_bytes(12)); mkdir($root, 0700);
foreach (['work', 'web', 'web/uploads'] as $dir) { mkdir($root . '/' . $dir, 0700); }
$files = []; $initialChecks = $checks;
try {
    $workspace = new RecoveryWorkspace($root . '/work', $set, $root . '/web', $root . '/web/uploads');
    $newReady = static function () use ($newStore, $set, $fixture): array {
        $store = $newStore(); $collector = new RecoveryCollector($store);
        $collector->enqueue($set, $fixture['environment']);
        $step = new RecoveryTestStep(static fn ($s, $p): RecoveryProgress => new RecoveryProgress([], $fixture[$p]));
        for ($i = 0; $i < 3; ++$i) { $collector->tick($set->id, $step); }
        return [$store, $collector];
    };
    foreach (['', 'before', 'after', 'get'] as $fault) {
        [$store, $collector] = $newReady(); $remote = new PublicationRemote(); $remote->fault = $fault;
        $bytes = $collector->current()['manifest'];
        $publisher = new RecoveryPublisher($remote, $workspace);
        $phase = $collector->publish($set->id, $publisher);
        if ($fault !== '') {
            $check($phase === 'publishing', 'Transient error remains resumable');
            $collector = new RecoveryCollector($store);
            $phase = $collector->publish($set->id, new RecoveryPublisher($remote, $workspace));
        }
        $check($phase === 'complete' && $remote->puts === 1 && $remote->bytes === $bytes, 'Exact bytes, one PUT');
        $check($collector->current()['checkpoint']['sha256'] === hash('sha256', $bytes), 'Durable verified receipt');
        $before = count($remote->calls);
        $check($collector->publish($set->id, $publisher) === 'complete' && count($remote->calls) === $before, 'Terminal completion does not upload again');
    }
    foreach (['head', 'body', 'version', 'race', 'oversized', 'truncated'] as $fault) {
        [$store, $collector] = $newReady(); $remote = new PublicationRemote(); $remote->fault = $fault;
        $check($collector->publish($set->id, new RecoveryPublisher($remote, $workspace)) === 'failed', 'Mismatch fails closed: ' . $fault);
        $check($collector->current()['error'] === 'publication_failed', 'Publication failure is distinct');
    }
    [$store, $collector] = $newReady(); $remote = new PublicationRemote(); $remote->bytes = 'conflict';
    $check($collector->publish($set->id, new RecoveryPublisher($remote, $workspace)) === 'failed' && $remote->puts === 0, 'Existing conflict never overwritten');

    [$store, $collector] = $newReady(); $remote = new PublicationRemote(); $store->rejectWrite = true;
    $check($collector->publish($set->id, new RecoveryPublisher($remote, $workspace)) === 'contended' && $remote->calls === [], 'CAS claim before network');
    $store->rejectWrite = false;
    $otherLock = new RecoveryWorkspace($root . '/work', $set, $root . '/web', $root . '/web/uploads'); $otherLock->acquire();
    $check($collector->publish($set->id, new RecoveryPublisher($remote, $workspace)) === 'busy' && $remote->calls === [], 'Workspace lock before network'); $otherLock->release();

    // Lose the final local CAS, then restart from the still-durable publishing claim.
    $clockValue = time(); $clock = static function () use (&$clockValue): int { return $clockValue; };
    $collector = new RecoveryCollector($store, $clock);
    $remote->onGet = static function () use ($store): void { $store->rejectWrite = true; };
    $check($collector->publish($set->id, new RecoveryPublisher($remote, $workspace)) === 'lease_lost', 'No success without durable final CAS');
    $store->rejectWrite = false; $remote->onGet = null; $clockValue += 61;
    $check((new RecoveryCollector($store, $clock))->publish($set->id, new RecoveryPublisher($remote, $workspace)) === 'complete'
        && $remote->puts === 1, 'Resume after local commit loss without re-PUT');
    $tampered = json_decode($store->read(), true); $tampered['checkpoint']['sha256'] = str_repeat('0', 64);
    $store->compareAndSwap($store->read(), json_encode($tampered));
    $reject(static fn () => (new RecoveryCollector($store))->current(), 'Reject forged completion digest');

    [$store, $collector] = $newReady(); $remote = new PublicationRemote();
    for ($i = 0; $i < 3; ++$i) {
        $remote->fault = 'before';
        $check($collector->publish($set->id, new RecoveryPublisher($remote, $workspace)) === 'publishing', 'Bounded retry');
    }
    $before = count($remote->calls);
    $check($collector->publish($set->id, new RecoveryPublisher($remote, $workspace)) === 'failed'
        && $collector->current()['error'] === 'recovery_exhausted' && count($remote->calls) === $before, 'Retry exhaustion stops network work');

    [$store] = $newReady(); $remote = new PublicationRemote(); $clockValue = time();
    $collector = new RecoveryCollector($store, $clock);
    $remote->onGet = static function () use (&$clockValue): void { $clockValue += 61; };
    $check($collector->publish($set->id, new RecoveryPublisher($remote, $workspace)) === 'lease_lost'
        && $collector->current()['phase'] === 'publishing', 'Expired worker cannot report complete');
    $remote->onGet = null;
    $check($collector->publish($set->id, new RecoveryPublisher($remote, $workspace)) === 'complete' && $remote->puts === 1, 'New lease reconciles unchanged object');
    echo 'PASS recovery publication: ' . ($checks - $initialChecks) . " checks\n";
} finally {
    foreach ($files as $file) { unlink($file); }
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($walk as $file) { $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
