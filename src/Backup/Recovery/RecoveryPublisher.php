<?php

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- Bounded reads and closure of caller-owned S3 response resources are stream operations, not WordPress filesystem transport operations.

namespace SecureS3StorageForWordpress\Backup\Recovery;

use SecureS3StorageForWordpress\Backup\Media\MediaObjectClient;
use SecureS3StorageForWordpress\Backup\Job\RetryableJobException;

/** Conditional publication plus independent, bounded download of the exact bytes. */
final class RecoveryPublisher implements RecoveryLock
{
    public function __construct(private MediaObjectClient $client, private RecoveryWorkspace $workspace) {}

    public function acquire(): bool { return $this->workspace->acquire(); }
    public function release(): void { $this->workspace->release(); }

    public function publish(RecoverySet $set, string $bytes, int $deadline): array
    {
        Contract::require($set->toIntent() === $this->workspace->set->toIntent());
        $pending = $set->publish(RecoveryManifest::decode($bytes));
        $size = strlen($bytes);
        $hash = hash('sha256', $bytes);
        $key = ['Bucket' => $set->bucket, 'Key' => $set->root() . 'manifest.json'];
        $head = $this->client->request('HeadObject', $key + ['ChecksumMode' => 'ENABLED'], $deadline);
        if ($head['missing'] ?? false) {
            // Never overwrite a conflicting set, even when HEAD and PUT race.
            $this->client->request('PutObject', $key + ['Body' => $bytes, 'ContentLength' => $size,
                'ContentType' => 'application/json', 'ChecksumSHA256' => base64_encode(hex2bin($hash)),
                'IfNoneMatch' => '*'], $deadline);
            $head = $this->client->request('HeadObject', $key + ['ChecksumMode' => 'ENABLED'], $deadline);
        }
        Contract::require(($head['ContentLength'] ?? null) === $size
            && ($head['ChecksumSHA256'] ?? null) === base64_encode(hex2bin($hash)));
        $version = $head['VersionId'] ?? null;
        Contract::require($version === null || (is_string($version) && strlen($version) <= 1024));
        Contract::require(is_string($head['ETag'] ?? null) && $head['ETag'] !== '');
        // Pin the HEAD observation; a changed unversioned object fails If-Match.
        $args = $key + ['IfMatch' => $head['ETag'], 'Range' => 'bytes=0-' . $size];
        if ($version !== null) { $args['VersionId'] = $version; }
        $result = $this->client->request('GetObject', $args, $deadline);
        $body = $result['Body'] ?? null;
        try {
            $download = '';
            if (is_string($body)) {
                Contract::require(strlen($body) <= $size);
                $download = $body;
            } else {
                Contract::require(is_resource($body) || $body instanceof \Psr\Http\Message\StreamInterface);
                while (strlen($download) <= $size) {
                    try {
                        $chunk = is_resource($body) ? fread($body, $size + 1 - strlen($download))
                            : $body->read($size + 1 - strlen($download));
                    } catch (\Throwable $e) {
                        throw new RetryableJobException('Manifest download interrupted.');
                    }
                    if (! is_string($chunk)) { throw new RetryableJobException('Manifest download interrupted.'); }
                    if ($chunk === '') { break; }
                    $download .= $chunk;
                }
            }
            Contract::require(($result['ContentLength'] ?? null) === $size && $download === $bytes);
            if ($version !== null) { Contract::require(($result['VersionId'] ?? null) === $version); }
            $downloaded = RecoveryManifest::decode($download);
            $pending->confirm($set->bucket, $key['Key'], strlen($download), hash('sha256', $downloaded->encode()));
        } finally {
            if (is_resource($body)) { fclose($body); }
            elseif ($body instanceof \Psr\Http\Message\StreamInterface) { $body->close(); }
        }
        return ['bucket' => $set->bucket, 'key' => $key['Key'], 'size_bytes' => $size, 'sha256' => $hash,
            'version_id' => $version, 'verified_at' => gmdate('Y-m-d\TH:i:s\Z'), 'method' => 'head_sha256_get_exact_bytes'];
    }

    public static function validateReceipt(RecoverySet $set, string $bytes, array $receipt): void
    {
        Contract::fields($receipt, ['bucket', 'key', 'size_bytes', 'sha256', 'version_id', 'verified_at', 'method']);
        $set->publish(RecoveryManifest::decode($bytes))->confirm($receipt['bucket'], $receipt['key'], $receipt['size_bytes'], $receipt['sha256']);
        Contract::time($receipt['verified_at']);
        Contract::require($receipt['verified_at'] >= RecoveryManifest::decode($bytes)->toArray()['completed_at']);
        Contract::require($receipt['method'] === 'head_sha256_get_exact_bytes'
            && ($receipt['version_id'] === null || (is_string($receipt['version_id']) && strlen($receipt['version_id']) <= 1024)));
    }
}
