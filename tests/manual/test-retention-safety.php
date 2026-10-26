<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use SecureS3StorageForWordpress\Backup\Retention\RetentionCandidate;
use SecureS3StorageForWordpress\Backup\Retention\RetentionPolicy;
use SecureS3StorageForWordpress\Backup\Retention\S3BackupRetentionManager;

// SDK command fixtures only: no network, WordPress, credentials or remote deletion.
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (! $ok) { throw new RuntimeException($message); }
    ++$checks;
};
$key = static fn (int $day): string => sprintf('site/backups/database/2026/10/%02d/db-fixture-202610%02d-010203.sql.gz', $day, $day);
$object = static fn (int $day): array => ['Key' => $key($day), 'Size' => 42,
    'LastModified' => new DateTimeImmutable(sprintf('2026-10-%02dT01:02:03Z', $day))];
$hash = hash('sha256', 'fixture');
$goodHead = ['ContentLength' => 42, 'ChecksumSHA256' => base64_encode(hex2bin($hash)),
    'Metadata' => ['odbfs3-sha256' => $hash]];
$run = static function (array $pages, array $heads, int $keep = 2) use ($check): array {
    $calls = []; $deleted = []; $pageIndex = 0;
    $client = new S3Client(['version' => 'latest', 'region' => 'us-east-1',
        'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'retries' => 0,
        'handler' => static function ($command) use (&$calls, &$deleted, &$pageIndex, $pages, $heads) {
            $name = $command->getName(); $calls[] = $name;
            if ($name === 'ListObjectsV2') {
                if (! isset($pages[$pageIndex])) { throw new RuntimeException('Unexpected list call'); }
                $page = $pages[$pageIndex++];
                if (($command['ContinuationToken'] ?? null) !== ($page['_expected_token'] ?? null)) {
                    throw new LogicException('Wrong continuation token');
                }
                unset($page['_expected_token']);
                return Create::promiseFor(new Result($page));
            }
            if ($name === 'HeadObject') {
                if ($command['ChecksumMode'] !== 'ENABLED') { throw new LogicException('Missing checksum mode'); }
                $head = $heads[$command['Key']] ?? null;
                if ($head === null) { throw new RuntimeException('Synthetic missing/denied HEAD'); }
                return Create::promiseFor(new Result($head));
            }
            if ($name === 'DeleteObject') {
                $deleted[] = $command['Key'];
                return Create::promiseFor(new Result([]));
            }
            throw new LogicException('Unexpected command');
        }]);
    $manager = new S3BackupRetentionManager($client);
    $error = null;
    try {
        $candidates = $manager->findDeletionCandidates('fixture', 'site', new RetentionPolicy($keep));
        $manager->deleteCandidates($candidates);
    } catch (RuntimeException $e) { $error = $e->getMessage(); }
    return [$deleted, $error, $calls, $manager];
};
$pages = [['Contents' => [$object(1), $object(3)], 'IsTruncated' => true, 'NextContinuationToken' => 'next'],
    ['Contents' => [$object(2)], 'IsTruncated' => false, '_expected_token' => 'next']];
[$deleted, $error, $calls] = $run($pages, [$key(3) => $goodHead, $key(2) => $goodHead]);
$check($error === null && $deleted === [$key(1)], 'Valid paginated listing retains two verified backups');
$check($calls === ['ListObjectsV2', 'ListObjectsV2', 'HeadObject', 'HeadObject', 'DeleteObject'], 'All guards precede deletion');

foreach (['legacy', 'hash', 'size', 'missing-checksum', 'bad-metadata', 'denied'] as $fault) {
    $head = $goodHead;
    if ($fault === 'legacy') { unset($head['Metadata']); }
    if ($fault === 'hash') { $head['ChecksumSHA256'] = base64_encode(str_repeat('x', 32)); }
    if ($fault === 'size') { $head['ContentLength'] = 41; }
    if ($fault === 'missing-checksum') { unset($head['ChecksumSHA256']); }
    if ($fault === 'bad-metadata') { $head['Metadata']['odbfs3-sha256'] = 'invalid'; }
    $heads = [$key(3) => $goodHead, $key(2) => $head];
    if ($fault === 'denied') { unset($heads[$key(2)]); }
    [$deleted, $error] = $run($pages, $heads);
    $check($deleted === [] && $error !== null, 'Preserve all backups on retained-object fault: ' . $fault);
}
// Missing token, token cycles, absent completeness flag, and malformed contents.
foreach ([
    [['Contents' => [$object(1), $object(2), $object(3)], 'IsTruncated' => true]],
    [$pages[0], ['Contents' => [], 'IsTruncated' => true, 'NextContinuationToken' => 'next', '_expected_token' => 'next']],
    [['Contents' => [$object(1), $object(2), $object(3)]]],
    [['Contents' => 'invalid', 'IsTruncated' => false]],
    [$pages[0], ['Contents' => [$object(3)], 'IsTruncated' => false, '_expected_token' => 'next']],
] as $invalidPages) {
    [$deleted, $error, $calls] = $run($invalidPages, [$key(3) => $goodHead, $key(2) => $goodHead]);
    $check($deleted === [] && $error !== null && ! in_array('HeadObject', $calls, true), 'Incomplete listing never produces a deletion plan');
}
[$deleted, $error, $calls] = $run([['Contents' => [$object(1)], 'IsTruncated' => false]], []);
$check($deleted === [] && $error === null && $calls === ['ListObjectsV2'], 'Below keep count requires no verification or deletion');

$unrelated = $object(1); $unrelated['Key'] = 'site/backups/database/manual/db-precious.sql.gz';
[$deleted, $error, , $manager] = $run([['Contents' => [$object(1), $object(2), $object(3), $unrelated], 'IsTruncated' => false]],
    [$key(3) => $goodHead, $key(2) => $goodHead]);
$check($error === null && $deleted === [$key(1)], 'Unrelated similarly named object is protected');
$match = new ReflectionMethod($manager, 'isDatabaseBackupKey');
$deleteMatch = new ReflectionMethod($manager, 'looksLikeDatabaseBackupKey');
foreach ([substr($key(1), 5), 'backups/database/' . $key(1)] as $valid) {
    $check($deleteMatch->invoke($manager, $valid), 'Empty and nested configured prefixes remain supported');
}
foreach ([$key(1), str_replace('.sql.gz', '-' . str_repeat('a', 32) . '.sql.gz', $key(1))] as $valid) {
    $check($match->invoke($manager, $valid, 'site/backups/database/'), 'Legacy and random-suffix formats accepted');
}
foreach ([$unrelated['Key'], str_replace('/10/01/', '/10/02/', $key(1)), $key(1) . "\n",
    'site/backups/database/2026/10/01/nested/db-fixture-20261001-010203.sql.gz',
    str_replace('site/', 'other/', $key(1))] as $invalid) {
    $check(! $match->invoke($manager, $invalid, 'site/backups/database/'), 'Non-generated key excluded');
}
try {
    $manager->deleteCandidates([new RetentionCandidate('fixture', $unrelated['Key'], 42, new DateTimeImmutable())]);
    throw new LogicException('Direct unsafe delete accepted');
} catch (RuntimeException $e) { $check(true, 'Deletion guard also rejects unrelated names'); }
// An older legacy object can be retired once newer verified objects fill the window.
$check($deleted === [$key(1)], 'Legacy deletion remains supported after verified replacements');
echo "PASS retention safety checks=$checks\n";
