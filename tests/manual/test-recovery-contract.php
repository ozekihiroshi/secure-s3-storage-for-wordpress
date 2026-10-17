<?php

declare(strict_types=1);

use SecureS3StorageForWordpress\Backup\Recovery\RecoveryManifest;
use SecureS3StorageForWordpress\Backup\Recovery\RecoverySet;
use SecureS3StorageForWordpress\Backup\Recovery\RecoveryObservation;

// Pure domain contract: no WordPress, AWS, Composer, network or filesystem writes.
spl_autoload_register(static function (string $class): void {
    $prefix = 'SecureS3StorageForWordpress\\Backup\\Recovery\\';
    if (str_starts_with($class, $prefix)) {
        require_once __DIR__ . '/../../src/Backup/Recovery/' . substr($class, strlen($prefix)) . '.php';
    }
});

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (! $ok) { throw new RuntimeException($label); }
    ++$checks;
};
$reject = static function (callable $action, string $label) use ($check): void {
    try { $action(); } catch (RuntimeException | JsonException | TypeError $e) {
        $check(true, $label);
        return;
    }
    $check(false, 'Accepted invalid contract: ' . $label);
};

$start = '2026-10-17T00:00:00Z';
$end = '2026-10-17T00:01:00Z';
$set = RecoverySet::create(str_repeat('a', 32), 'database_uploads', 'example-backups', 'ap-northeast-1', 'site-a', $start);
$other = RecoverySet::create(str_repeat('a', 32), 'database_uploads', 'example-backups', 'ap-northeast-1', 'site-a', $start);
$check($set->id !== $other->id && preg_match('/^[a-f0-9]{32}$/D', $set->id) === 1, 'random IDs');
$artifact = static fn (string $key): array => ['key' => $key, 'size_bytes' => 100, 'sha256' => str_repeat('b', 64), 'version_id' => null];
$base = ['set_id' => $set->id, 'source_id' => $set->sourceId, 'capture' => ['start' => $start, 'end' => $start],
    'transfer' => ['start' => $start, 'end' => $end], 'verified_at' => $end];
$job = str_repeat('c', 32);
$prefix = $set->root() . 'media/' . $job . '/';
$fixture = [
    'format' => 'odbfs3-recovery-set', 'schema_version' => 1, 'set_id' => $set->id, 'source_id' => $set->sourceId,
    'profile' => $set->profile, 'created_at' => $start, 'completed_at' => $end,
    'destination' => ['bucket' => $set->bucket, 'region' => $set->region, 'prefix' => $set->prefix],
    'environment' => ['wordpress' => '6.6', 'php' => '8.1', 'plugin' => 'development', 'database_server' => '11.8-MariaDB', 'table_prefix' => 'wp_'],
    'capture' => ['consistency' => 'uncoordinated', 'assertion' => null],
    'database' => $base + ['artifact' => $artifact($set->root() . 'database/dump.sql.gz'), 'backend' => 'native',
        'raw_sql' => ['size_bytes' => 200, 'sha256' => str_repeat('d', 64)], 'method' => 'sql_gzip_s3_sha256'],
    'media' => $base + ['job_id' => $job, 'prefix' => $prefix, 'inventory' => $artifact($prefix . 'inventory.jsonl'),
        'marker' => $artifact($prefix . 'complete.json'), 'file_count' => 0, 'total_bytes' => 0,
        'method' => 'inventory_full_sha256_objects_full_or_composite_sha256',
        'marker_evidence' => ['format' => 'odbfs3-media-complete', 'version' => 1, 'run' => $job, 'inventory' => 'inventory.jsonl',
            'inventory_sha256' => str_repeat('b', 64), 'files' => 0, 'bytes' => 0, 'object_key_rule' => 'files/sha256(UTF-8 relative path)']],
    'verification' => ['database' => 'verified', 'media' => 'verified'],
    'scope' => ['included' => ['database', 'uploads'], 'excluded' => RecoveryManifest::EXCLUDED],
    'requirements' => RecoveryManifest::REQUIREMENTS,
];
$manifest = RecoveryManifest::fromArray($fixture);
$json = $manifest->encode();
$check(RecoveryManifest::decode($json)->toArray() === $fixture, 'empty uploads round trip');
$spaced = " \n" . $json . "\t";
$check(RecoveryManifest::decode($spaced)->encode() === $spaced, 'downloaded manifest preserves exact digest bytes');
$copy = $manifest->toArray();
$copy['media'] = null;
$check($manifest->toArray() === $fixture, 'immutable record');
$db = $fixture;
$db['profile'] = 'database';
$db['media'] = null;
$db['verification']['media'] = 'not_included';
$db['scope']['included'] = ['database'];
$db['scope']['excluded'][] = 'uploads';
$check(RecoveryManifest::decode(RecoveryManifest::fromArray($db)->encode())->toArray() === $db, 'database-only profile');
$php = $db;
$php['database']['backend'] = 'php';
$check(RecoveryManifest::fromArray($php)->toArray() === $php, 'PHP backend');
$populated = $fixture;
$populated['media']['file_count'] = $populated['media']['marker_evidence']['files'] = 2;
$populated['media']['total_bytes'] = $populated['media']['marker_evidence']['bytes'] = 30;
$check(RecoveryManifest::fromArray($populated)->toArray() === $populated, 'populated uploads');
$quiet = $fixture;
$quiet['capture'] = ['consistency' => 'operator_asserted_quiescence', 'assertion' => [
    'interval' => ['start' => $start, 'end' => $end], 'method' => 'operator_stopped_writes']];
$check(RecoveryManifest::fromArray($quiet)->toArray() === $quiet, 'operator assertion is explicit');

// Each mutation must fail independently, not accidentally pass due to another bad field.
$mutations = [
    'missing media' => [['media'], null], 'missing database' => [['database'], null],
    'foreign set' => [['database', 'set_id'], $other->id], 'foreign source' => [['media', 'source_id'], str_repeat('e', 32)],
    'schema' => [['schema_version'], 2], 'schema type' => [['schema_version'], '1'],
    'ID type' => [['set_id'], 123], 'hash' => [['database', 'artifact', 'sha256'], 'bad'],
    'composite hash' => [['media', 'inventory', 'sha256'], str_repeat('b', 64) . '-2'],
    'negative size' => [['database', 'artifact', 'size_bytes'], -1], 'float count' => [['media', 'file_count'], 0.0],
    'numeric string' => [['database', 'raw_sql', 'size_bytes'], '200'],
    'empty raw SQL' => [['database', 'raw_sql', 'size_bytes'], 0],
    'invalid date' => [['completed_at'], '2026-02-30T00:01:00Z'],
    'non UTC' => [['completed_at'], '2026-10-17T00:01:00+09:00'],
    'reverse capture' => [['database', 'capture', 'start'], $end],
    'verification before transfer' => [['database', 'verified_at'], $start],
    'unsafe prefix' => [['destination', 'prefix'], '../site-a'],
    'foreign object' => [['database', 'artifact', 'key'], $other->root() . 'database/dump.sql.gz'],
    'foreign inventory' => [['media', 'inventory', 'key'], 'inventory.jsonl'],
    'stale marker job' => [['media', 'marker_evidence', 'run'], str_repeat('f', 32)],
    'marker digest' => [['media', 'marker_evidence', 'inventory_sha256'], str_repeat('e', 64)],
    'marker count' => [['media', 'marker_evidence', 'files'], 1],
    'marker mapping' => [['media', 'marker_evidence', 'object_key_rule'], 'files/path'],
    'empty inventory bytes' => [['media', 'total_bytes'], 1],
    'unverified DB' => [['verification', 'database'], 'unverified'],
    'unverified uploads' => [['verification', 'media'], 'unverified'],
    'atomic claim' => [['capture', 'consistency'], 'atomic'],
    'unknown field' => [['secret'], 'do-not-echo-this'],
    'unknown nested field' => [['database', 'password'], 'do-not-echo-this'],
    'missing exclusion' => [['scope', 'excluded'], []],
    'missing requirement' => [['requirements'], []],
];
foreach ($mutations as $label => [$path, $value]) {
    $bad = $fixture;
    $target = &$bad;
    foreach ($path as $part) { $target = &$target[$part]; }
    $target = $value;
    unset($target);
    $reject(static fn () => RecoveryManifest::fromArray($bad), $label);
    $badWire = json_encode($bad, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    $reject(static fn () => RecoveryManifest::decode($badWire), $label . ' on wire');
}
foreach (array_keys($fixture) as $field) {
    $bad = $fixture;
    unset($bad[$field]);
    $reject(static fn () => RecoveryManifest::fromArray($bad), 'missing required field ' . $field);
}
$bad = $db;
$bad['media'] = $fixture['media'];
$reject(static fn () => RecoveryManifest::fromArray($bad), 'database profile cannot hide media');
$bad = $quiet;
$bad['capture']['assertion']['interval']['start'] = $end;
$reject(static fn () => RecoveryManifest::fromArray($bad), 'quiescence excludes capture');
foreach (['{"schema_version":1,' . substr($json, 1), str_replace('"schema_version":1', '"schema_version":1,"schema_versi\\u006fn":1', $json),
    str_replace('"size_bytes":100', '"size_bytes":100,"size_bytes":100', $json),
    str_replace('["database","uploads"]', '{"0":"database","1":"uploads"}', $json),
    str_repeat(' ', 65537) . $json, '{', '[]', str_repeat('[', 17) . '0' . str_repeat(']', 17)] as $badJson) {
    $reject(static fn () => RecoveryManifest::decode($badJson), 'invalid JSON contract');
}
$publishing = $set->publish($manifest);
$check($set->state === 'collecting' && $publishing->state === 'publishing' && $publishing->manifestBytes === $json, 'immutable publication');
$reject(static fn () => $set->confirm($set->bucket, $set->root() . 'manifest.json', strlen($json), hash('sha256', $json)), 'no skipped publication');
$reject(static fn () => $publishing->publish($manifest), 'no repeated transition');
$reject(static fn () => $other->publish($manifest), 'foreign intent');
foreach ([['wrong', $set->root() . 'manifest.json', strlen($json), hash('sha256', $json)],
    [$set->bucket, 'wrong', strlen($json), hash('sha256', $json)],
    [$set->bucket, $set->root() . 'manifest.json', strlen($json) + 1, hash('sha256', $json)],
    [$set->bucket, $set->root() . 'manifest.json', strlen($json), str_repeat('0', 64)]] as $receipt) {
    $reject(static fn () => $publishing->confirm(...$receipt), 'incorrect receipt');
}
$done = $publishing->confirm($set->bucket, $set->root() . 'manifest.json', strlen($json), hash('sha256', $json));
$check($done->state === 'complete' && $done->manifestBytes === $json, 'verified receipt completes');
$reject(static fn () => $done->fail(), 'complete is terminal');
$failed = $publishing->fail();
$check($failed->state === 'failed' && $failed->manifestBytes === $json, 'failure preserves evidence');
$reject(static fn () => $failed->publish($manifest), 'failed is terminal');
$reject(static fn () => $failed->confirm($set->bucket, $set->root() . 'manifest.json', strlen($json), hash('sha256', $json)), 'failed cannot complete');
$observation = ['set_id' => $set->id, 'manifest_sha256' => hash('sha256', $json), 'kind' => 'availability',
    'state' => 'inaccessible', 'checked_at' => $end, 'environment_id' => null, 'checks' => []];
$check(RecoveryObservation::forManifest($manifest, $observation)->toArray()['state'] === 'inaccessible', 'inaccessible is not missing');
$available = $observation;
$available['state'] = 'available';
$reject(static fn () => RecoveryObservation::forManifest($manifest, $available), 'no availability without component checks');
$available['checks'] = ['database_object', 'inventory_object', 'marker_object', 'all_media_objects'];
$check(RecoveryObservation::forManifest($manifest, $available)->toArray() === $available, 'availability evidence');
$reject(static fn () => RecoveryObservation::forManifest(RecoveryManifest::decode($spaced), $available), 'equivalent JSON is not identical evidence');
$restore = $observation;
$restore['kind'] = 'restore';
$restore['state'] = 'not_tested';
$check(RecoveryObservation::forManifest($manifest, $restore)->toArray() === $restore, 'explicit untested restore');
$restore['state'] = 'passed';
$restore['environment_id'] = str_repeat('e', 32);
$restore['checks'] = ['sql_import'];
$reject(static fn () => RecoveryObservation::forManifest($manifest, $restore), 'SQL alone is not WordPress restore');
$restore['checks'] = ['sql_import', 'wordpress_content', 'media_hashes', 'attachment_references'];
$check(RecoveryObservation::forManifest($manifest, $restore)->toArray() === $restore, 'restore evidence');
$check($manifest->encode() === $json && $done->state === 'complete', 'observations do not rewrite backup evidence');
$restore['manifest_sha256'] = str_repeat('0', 64);
$reject(static fn () => RecoveryObservation::forManifest($manifest, $restore), 'report binds exact manifest');
$observation['state'] = 'passed';
$reject(static fn () => RecoveryObservation::forManifest($manifest, $observation), 'no mixing observation states');
$reordered = $fixture;
$reordered['destination'] = array_reverse($reordered['destination'], true);
$check($set->publish(RecoveryManifest::fromArray($reordered))->state === 'publishing', 'map order is not semantic');
foreach (['', '/site-a', 'site-a/', 'site-a//x', 'site-a/../x', 'site-a\\x'] as $prefixInput) {
    if ($prefixInput === '') {
        $check(str_starts_with(RecoverySet::create($set->sourceId, 'database', $set->bucket, $set->region, '', $start)->root(), 'backups/'), 'empty root prefix');
    } else {
        $reject(static fn () => RecoverySet::create($set->sourceId, 'database', $set->bucket, $set->region, $prefixInput, $start), 'non-normalized prefix');
    }
}
echo "PASS recovery contract: {$checks} checks\n";
