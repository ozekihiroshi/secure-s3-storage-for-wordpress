<?php

// Real MariaDB dump/gzip and media preparation/upload; disk S3 double only.
// Child processes are deliberately SIGKILLed after durable remote acceptance.
require __DIR__ . '/../../vendor/autoload.php';
define('ODBFS3_UPLOAD_HELPERS_ONLY', true);
require __DIR__ . '/test-media-upload.php';
define('ODBFS3_RESTORE_HELPERS_ONLY', true);
require __DIR__ . '/test-database-restore.php';

use SecureS3StorageForWordpress\Backup\Recovery\RecoverySet;
use SecureS3StorageForWordpress\Backup\Recovery\RecoveryWorkspace;
use SecureS3StorageForWordpress\Backup\Recovery\RecoveryPipeline;
use SecureS3StorageForWordpress\Backup\Recovery\RecoveryCollector;
use SecureS3StorageForWordpress\Backup\Recovery\RecoveryPublisher;
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use SecureS3StorageForWordpress\Backup\Media\MediaObjectClient;
use SecureS3StorageForWordpress\Backup\Media\MediaSource;
use SecureS3StorageForWordpress\Backup\Media\MediaManifest;
use SecureS3StorageForWordpress\Backup\Database\DatabaseConnection;
use SecureS3StorageForWordpress\Backup\Database\DatabaseDumper;
use SecureS3StorageForWordpress\Backup\Database\DumpResult;
use SecureS3StorageForWordpress\Backup\Database\NativeMySqlDumper;
use SecureS3StorageForWordpress\Backup\Database\Php\PhpMySqlDumper;
use SecureS3StorageForWordpress\Backup\Compression\GzipVerifier;

final class PipelineTestStore implements JobStore
{
    public function __construct(private string $path) {}
    public function read(): ?string { return is_file($this->path) ? file_get_contents($this->path) : null; }
    public function compareAndSwap(?string $expected, string $replacement): bool
    {
        $lock = fopen($this->path . '.lock', 'c+b'); chmod($this->path . '.lock', 0600); flock($lock, LOCK_EX);
        try {
            if ($this->read() !== $expected) { return false; }
            $temporary = $this->path . '.new';
            file_put_contents($temporary, $replacement); chmod($temporary, 0600);
            return rename($temporary, $this->path);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
final class PipelineTestRemote implements MediaObjectClient
{
    private UploadTestS3 $remote;
    public function __construct(private string $directory, private string $fault)
    {
        $this->remote = new UploadTestS3($directory);
        if (is_file($directory . '/state.json')) {
            $state = json_decode(file_get_contents($directory . '/state.json'), true, 32, JSON_THROW_ON_ERROR);
            foreach (['uploads', 'objects', 'calls'] as $key) { $this->remote->$key = $state[$key]; }
        }
    }
    public function request(string $operation, array $arguments, int $deadline): array
    {
        if ($this->fault === 'db-before' && $operation === 'PutObject' && str_ends_with($arguments['Key'], 'dump.sql.gz')) {
            posix_kill(getmypid(), SIGKILL);
        }
        if ($operation === 'GetObject') {
            $object = $this->remote->objects[$arguments['Bucket'] . '/' . $arguments['Key']];
            if (($arguments['IfMatch'] ?? null) !== hash_file('sha256', $object['path'])) { throw new RuntimeException('Changed object'); }
            $this->remote->calls[] = 'GetObject';
            $result = ['Body' => fopen($object['path'], 'rb'), 'ContentLength' => $object['ContentLength']];
        } else {
            $result = $this->remote->request($operation, $arguments, $deadline);
            if ($operation === 'HeadObject' && ! ($result['missing'] ?? false)) {
                $result['ETag'] = hash_file('sha256', $this->remote->objects[$arguments['Bucket'] . '/' . $arguments['Key']]['path']);
            }
        }
        $state = ['uploads' => $this->remote->uploads, 'objects' => $this->remote->objects, 'calls' => $this->remote->calls];
        file_put_contents($this->directory . '/state.new', json_encode($state, JSON_THROW_ON_ERROR));
        rename($this->directory . '/state.new', $this->directory . '/state.json');
        $kill = ($this->fault === 'db-after' && $operation === 'PutObject' && str_ends_with($arguments['Key'], 'dump.sql.gz'))
            || ($this->fault === 'part-after' && $operation === 'UploadPart')
            || ($this->fault === 'marker-after' && $operation === 'PutObject' && str_ends_with($arguments['Key'], 'complete.json'))
            || ($this->fault === 'manifest-after' && $operation === 'PutObject' && str_ends_with($arguments['Key'], 'manifest.json'))
            || ($this->fault === 'manifest-get' && $operation === 'GetObject');
        if ($kill) { posix_kill(getmypid(), SIGKILL); }
        return $result;
    }
}
final class CountedRealDumper implements DatabaseDumper
{
    public function __construct(private DatabaseDumper $inner, private string $counter) {}
    public function dump(DatabaseConnection $connection): DumpResult
    {
        file_put_contents($this->counter, "dump\n", FILE_APPEND);
        return $this->inner->dump($connection);
    }
}

$sourceName = getenv('WORDPRESS_DB_NAME') ?: '';
$restoreName = getenv('RESTORE_TEST_DB_NAME') ?: '';
$backend = getenv('RESTORE_TEST_BACKEND') ?: '';
if (getenv('RESTORE_TEST_FIXTURE') !== '1' || ! preg_match('/^secure_s3_restore_source_[a-f0-9]{12}$/D', $sourceName)
    || ! preg_match('/^secure_s3_restore_test_[a-f0-9]{12}$/D', $restoreName) || ! in_array($backend, ['native', 'php'], true)) {
    throw new RuntimeException('Disposable fixture environment required.');
}
[$host, $port] = parseHost(getenv('WORDPRESS_DB_HOST'));
if ($host !== '127.0.0.1') { throw new RuntimeException('Local fixture only.'); }
$user = getenv('WORDPRESS_DB_USER'); $password = getenv('WORDPRESS_DB_PASSWORD');
$connection = new DatabaseConnection($host, $port, $sourceName, $user, $password);

if (in_array($argv[1] ?? '', ['tick', 'hold'], true)) {
    $root = $argv[2];
    if (! preg_match('#^/tmp/odbfs3-pipeline-[a-f0-9]{24}$#D', $root) || realpath($root) !== $root) { throw new RuntimeException('Invalid fixture root.'); }
    $set = RecoverySet::fromIntent(json_decode(file_get_contents($root . '/intent.json'), true, 8, JSON_THROW_ON_ERROR));
    $work = new RecoveryWorkspace($root . '/work', $set, $root . '/web', $root . '/web/uploads');
    if ($argv[1] === 'hold') {
        if (! $work->acquire()) { exit(2); }
        echo "locked\n"; flush();
        sleep(60); exit;
    }
    $dumper = new CountedRealDumper($backend === 'native' ? new NativeMySqlDumper() : new PhpMySqlDumper(chunkSize: 2), $root . '/dumps');
    $pipeline = new RecoveryPipeline($work, $dumper, $connection, $backend,
        new PipelineTestRemote($root . '/remote', $argv[3] ?? ''), new MediaSource($root . '/web/uploads'), $root . '/web');
    $collector = new RecoveryCollector(new PipelineTestStore($root . '/collector.json'), leaseSeconds: 10);
    if (in_array($collector->current()['phase'], ['ready', 'publishing', 'complete'], true)) {
        echo $collector->publish($set->id, new RecoveryPublisher(new PipelineTestRemote($root . '/remote', $argv[3] ?? ''), $work)) . "\n";
    } else { echo $collector->tick($set->id, $pipeline) . "\n"; }
    exit;
}

$root = '/tmp/odbfs3-pipeline-' . bin2hex(random_bytes(12)); mkdir($root, 0700);
foreach (['web', 'web/uploads', 'work', 'remote', 'restored'] as $dir) { mkdir($root . '/' . $dir, 0700); }
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (! $ok) { throw new RuntimeException($label); } ++$checks;
};
$run = static function (string $fault = '') use ($root): array {
    $process = proc_open([PHP_BINARY, __FILE__, 'tick', $root, $fault], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process);
    if ($error !== '') { throw new RuntimeException($error); }
    return [$code, trim($out)];
};
$waitLease = static function () use ($root): void {
    $state = json_decode(file_get_contents($root . '/collector.json'), true);
    while (time() <= $state['until']) { usleep(200000); }
};
try {
    $file = fopen($root . '/web/uploads/large.bin', 'wb');
    for ($i = 0; $i < 17; ++$i) { fwrite($file, str_repeat(chr(65 + $i), 1048576)); } fclose($file);
    file_put_contents($root . '/web/uploads/日本語.txt', 'fixture'); file_put_contents($root . '/web/uploads/empty', '');
    $set = RecoverySet::create(str_repeat('a', 32), 'database_uploads', 'fixture-bucket', 'us-east-1', 'fixture', gmdate('Y-m-d\TH:i:s\Z'));
    file_put_contents($root . '/intent.json', json_encode($set->toIntent()));
    $store = new PipelineTestStore($root . '/collector.json');
    $collector = new RecoveryCollector($store);
    $collector->enqueue($set, ['wordpress' => 'fixture', 'php' => PHP_VERSION, 'plugin' => 'development', 'database_server' => 'MariaDB', 'table_prefix' => 'wp_']);
    $check($run()[1] === 'database', 'Actual DB dump and gzip prepared');
    echo "Pipeline {$backend}: DB prepared\n";
    $holder = proc_open([PHP_BINARY, __FILE__, 'hold', $root], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $holdPipes);
    fclose($holdPipes[0]);
    try {
        $check(trim(fgets($holdPipes[1])) === 'locked', 'Actual process holds workspace lock');
        $prior = $store->read();
        $check($run()[1] === 'busy' && $store->read() === $prior, 'Competing process makes no progress while flock held');
    } finally {
        proc_terminate($holder, 9); fclose($holdPipes[1]); fclose($holdPipes[2]); proc_close($holder);
    }
    $database = $root . '/work/recovery-' . $set->id . '/database.sql.gz';
    $digest = hash_file('sha256', $database);
    $before = connectDatabase($host, $port, $user, $password, $sourceName);
    $snapshot = [];
    foreach (tableNames($before) as $table) { $snapshot[$table] = rowFingerprints($before, $table); }
    $before->query('CREATE TABLE after_capture (id INT)'); $before->close();
    $check($run('db-before')[0] !== 0, 'SIGKILL before database upload'); $waitLease();
    echo "Pipeline {$backend}: killed before DB upload, resuming saved gzip\n";
    $check($run('db-after')[0] !== 0, 'SIGKILL after accepted database upload'); $waitLease();
    echo "Pipeline {$backend}: killed after DB acceptance, reconciling receipt\n";
    $check($run()[1] === 'media', 'Database receipt reconciled after process restart');
    $check(hash_file('sha256', $database) === $digest && substr_count(file_get_contents($root . '/dumps'), 'dump') === 1, 'Same gzip reused; changed source never recaptured');
    $partKilled = false; $markerKilled = false; $jobId = null;
    for ($i = 0; $i < 400; ++$i) {
        [$code, $phase] = $run(! $partKilled ? 'part-after' : (! $markerKilled ? 'marker-after' : ''));
        $metaPath = $root . '/work/recovery-' . $set->id . '/media.json';
        if (is_file($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true);
            if ($jobId === null) { $jobId = $meta['job_id']; }
            $check($jobId === $meta['job_id'], 'Same media child binding after reconstruction');
        }
        if ($code !== 0) {
            if (! $partKilled) { $partKilled = true; } else { $markerKilled = true; }
            echo "Pipeline {$backend}: media process killed, resuming same child\n";
            $waitLease(); continue;
        }
        $check($phase !== 'failed', 'Real pipeline did not fail');
        if ($phase === 'ready') { break; }
    }
    $check($partKilled && $markerKilled && $collector->current()['phase'] === 'ready', 'Media part and marker process kills recovered');
    $frozenManifest = $collector->current()['manifest'];
    $check($run('manifest-after')[0] !== 0, 'SIGKILL after accepted manifest PUT'); $waitLease();
    $check($collector->current()['phase'] === 'publishing', 'No premature completion after accepted PUT');
    $check($run('manifest-get')[0] !== 0, 'SIGKILL during independent manifest GET'); $waitLease();
    $check($run()[1] === 'complete', 'Publication resumes to independently verified complete');
    $check($collector->current()['manifest'] === $frozenManifest, 'Frozen manifest never regenerated');
    echo "Pipeline {$backend}: manifest publication and GET process kills recovered\n";
    $state = json_decode(file_get_contents($root . '/remote/state.json'), true);
    $objects = $state['objects'];
    $manifestObject = $objects[$set->bucket . '/' . $set->root() . 'manifest.json'];
    $check(file_get_contents($manifestObject['path']) === $frozenManifest, 'Published exact manifest bytes');
    $check($collector->current()['checkpoint']['sha256'] === hash('sha256', $frozenManifest), 'Durable publication digest');
    $manifest = \SecureS3StorageForWordpress\Backup\Recovery\RecoveryManifest::decode(file_get_contents($manifestObject['path']))->toArray();
    $dbObject = $objects[$manifest['destination']['bucket'] . '/' . $manifest['database']['artifact']['key']];
    $check(hash_file('sha256', $dbObject['path']) === $digest, 'Remote DB exact bytes');
    $check(count(array_filter($state['calls'], static fn ($x) => $x === 'CreateMultipartUpload')) === 1, 'Multipart session not recreated after part kill');
    $check($manifest['media']['job_id'] === $jobId, 'Manifest identifies real child');
    $inventory = $objects[$manifest['destination']['bucket'] . '/' . $manifest['media']['inventory']['key']]['path'];
    foreach ((new MediaManifest())->entries($inventory) as $entry) {
        copy($objects[$manifest['destination']['bucket'] . '/' . $manifest['media']['prefix'] . 'files/' . hash('sha256', $entry->path)]['path'], $root . '/restored/' . $entry->path);
    }
    $check((new MediaManifest())->verify(new MediaSource($root . '/restored'), $inventory, $root)->successful(), 'Independent media restore matches all hashes');
    $sql = $root . '/restore.sql';
    (new GzipVerifier())->verify($dbObject['path'], $manifest['database']['raw_sql']['size_bytes'], $sql);
    $optionPath = null;
    try { importDump($host, $port, $user, $password, $restoreName, $sql, $optionPath); }
    finally { if ($optionPath !== null && is_file($optionPath)) { unlink($optionPath); } }
    $restored = connectDatabase($host, $port, $user, $password, $restoreName);
    $check(tableNames($restored) === array_keys($snapshot), 'Restored original capture excludes later source table');
    foreach ($snapshot as $table => $rows) { $check(rowFingerprints($restored, $table) === $rows, 'Restored original rows'); }
    $restored->close();
    echo "PASS real recovery pipeline backend={$backend} checks={$checks}; six SIGKILL boundaries; isolated MariaDB + mock S3\n";
} finally {
    // Only this test-created absolute root, never a caller-supplied directory.
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { if ($item->isDir() && ! $item->isLink()) { rmdir($item->getPathname()); } else { unlink($item->getPathname()); } }
    rmdir($root);
}
