<?php

// Negative filesystem and retransmission tests. No database or AWS connection.
define('ODBFS3_UPLOAD_HELPERS_ONLY', true);
require __DIR__ . '/test-media-upload.php';

use SecureS3StorageForWordpress\Backup\Recovery\RecoveryWorkspace;
use SecureS3StorageForWordpress\Backup\Recovery\RecoveryPipeline;
use SecureS3StorageForWordpress\Backup\Recovery\RecoverySet;
use SecureS3StorageForWordpress\Backup\Database\DatabaseDumper;
use SecureS3StorageForWordpress\Backup\Database\DatabaseConnection;
use SecureS3StorageForWordpress\Backup\Database\DumpResult;
use SecureS3StorageForWordpress\Backup\Media\MediaSource;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;

$root = sys_get_temp_dir() . '/odbfs3-workspace-test-' . bin2hex(random_bytes(12));
mkdir($root, 0700);
foreach (['web', 'web/uploads', 'work', 'remote'] as $name) { mkdir($root . '/' . $name, 0700); }
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void { if (! $ok) { throw new RuntimeException($label); } ++$checks; };
$reject = static function (callable $call, string $label) use ($check): void {
    try { $call(); } catch (RuntimeException $e) { $check(true, $label); return; }
    $check(false, $label);
};
try {
    $set = RecoverySet::create(str_repeat('a', 32), 'database', 'test-bucket', 'us-east-1', 'test', gmdate('Y-m-d\TH:i:s\Z'));
    $work = new RecoveryWorkspace($root . '/work', $set, $root . '/web', $root . '/web/uploads');
    $check($work->acquire(), 'workspace lock');
    $second = new RecoveryWorkspace($root . '/work', $set, $root . '/web', $root . '/web/uploads');
    $check(! $second->acquire(), 'second handle blocked');
    $work->release();
    $check($second->acquire(), 'lock released'); $second->release();
    $foreign = RecoverySet::intent($set->id, $set->sourceId, 'database', 'other-bucket', $set->region, $set->prefix, $set->createdAt);
    $reject(static fn () => (new RecoveryWorkspace($root . '/work', $foreign, $root . '/web', $root . '/web/uploads'))->acquire(), 'changed destination rejected');
    $reject(static fn () => new RecoveryWorkspace($root . '/web', $set, $root . '/web', $root . '/web/uploads'), 'web root forbidden');
    chmod($root . '/work', 0755);
    $reject(static fn () => new RecoveryWorkspace($root . '/work', $set, $root . '/web', $root . '/web/uploads'), 'public permissions forbidden');
    chmod($root . '/work', 0700); clearstatcache();
    $dumper = new class($root) implements DatabaseDumper {
        public int $calls = 0;
        public function __construct(private string $root) {}
        public function dump(DatabaseConnection $connection): DumpResult {
            ++$this->calls;
            $path = $this->root . '/fixture.sql'; $s = SecureTemporaryFile::openForWriting($path);
            fwrite($s, 'CREATE TABLE fixture (id INT);'); fclose($s);
            return new DumpResult($path, filesize($path), 'fixture', 'mysql', new DateTimeImmutable());
        }
    };
    $remote = new UploadTestS3($root . '/remote');
    $pipeline = new RecoveryPipeline($work, $dumper, new DatabaseConnection('unused', 3306, 'fixture', 'unused', 'unused'),
        'php', $remote, new MediaSource($root . '/web/uploads'), $root . '/web');
    $check($pipeline->acquire(), 'pipeline lock');
    $prepared = $pipeline->execute($set, 'database', [], time() + 60);
    $check($dumper->calls === 1 && $remote->calls === [], 'durable gzip precedes any remote operation');
    $evidence = $pipeline->execute($set, 'database', $prepared->checkpoint, time() + 60)->evidence;
    $pipeline->release();
    $pipeline = new RecoveryPipeline(new RecoveryWorkspace($root . '/work', $set, $root . '/web', $root . '/web/uploads'),
        $dumper, new DatabaseConnection('unused', 3306, 'fixture', 'unused', 'unused'), 'php', $remote, new MediaSource($root . '/web/uploads'), $root . '/web');
    $check($pipeline->acquire(), 'reconstructed pipeline');
    $pipeline->execute($set, 'database', [], time() + 60);
    $check($dumper->calls === 1 && count(array_filter($remote->calls, static fn ($x) => $x === 'PutObject')) === 1, 'replay reconciles instead of redump or PUT');
    $remote->corruptHead = true;
    $reject(static fn () => $pipeline->execute($set, 'database', [], time() + 60), 'remote mismatch rejected');
    $remote->corruptHead = false;
    $gzip = $work->path('database.sql.gz');
    $bytes = file_get_contents($gzip);
    file_put_contents($gzip, 'corrupt'); $before = count($remote->calls);
    $reject(static fn () => $pipeline->execute($set, 'database', [], time() + 60), 'local corruption rejected');
    $check(count($remote->calls) === $before && $dumper->calls === 1, 'corruption causes no transfer or recapture');
    file_put_contents($gzip, $bytes);
    rename($gzip, $root . '/saved.gz'); symlink($root . '/saved.gz', $gzip);
    $reject(static fn () => $pipeline->execute($set, 'database', [], time() + 60), 'symlink rejected');
    unlink($gzip); rename($root . '/saved.gz', $gzip);
    chmod($gzip, 0644); clearstatcache();
    $reject(static fn () => $pipeline->execute($set, 'database', [], time() + 60), 'exposed gzip rejected');
    chmod($gzip, 0600); clearstatcache();
    $reject(static fn () => $work->resetUncommittedDatabase(), 'committed artifacts cannot be reset');
    $check(hash_file('sha256', $gzip) === $evidence['artifact']['sha256'], 'prepared artifact retained');
    $pipeline->release();
    echo "PASS recovery workspace: {$checks} checks\n";
} finally {
    if (isset($pipeline)) { $pipeline->release(); } if (isset($work)) { $work->release(); }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { if ($item->isDir() && ! $item->isLink()) { rmdir($item->getPathname()); } else { unlink($item->getPathname()); } }
    rmdir($root);
}
