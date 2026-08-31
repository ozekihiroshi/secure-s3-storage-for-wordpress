<?php

namespace SecureS3StorageForWordpress\Backup\Media;

use RuntimeException;
use SecureS3StorageForWordpress\Backup\Job\BackupJob;
use SecureS3StorageForWordpress\Backup\Job\JobStep;
use SecureS3StorageForWordpress\Backup\Job\StepResult;

/** One multipart operation per checkpoint, except bounded completion verification. */
final class MediaUploadStep implements JobStep
{
    public function __construct(private MediaObjectClient $client)
    {
    }

    public function execute(BackupJob $job, int $deadline): StepResult
    {
        $state = $job->checkpoint;
        $plan = new MediaUploadPlan($state['directory']);
        if ($plan->metadata() !== $state['metadata']) {
            throw new RuntimeException('Prepared media plan changed.');
        }
        [$object, $next] = $plan->record($state['offset']);
        $base = ['Bucket' => $state['bucket']];
        $prefix = $state['prefix'] . 'backups/media/' . $job->id . '/';
        if ($object === null) {
            if ($state['offset'] !== $state['metadata']['plan_size'] || ! ($state['inventory_done'] ?? false)
                || ($state['plan_chain'] ?? '') !== $state['metadata']['plan_chain']
                || $job->processedFiles !== $state['metadata']['files']
                || $job->processedBytes !== $state['metadata']['bytes']) {
                throw new RuntimeException('Media upload is incomplete.');
            }
            $body = json_encode(['format' => 'odbfs3-media-complete', 'version' => 1,
                'run' => $job->id, 'inventory' => 'inventory.jsonl',
                'inventory_sha256' => $state['metadata']['inventory_sha256'],
                'files' => $job->processedFiles, 'bytes' => $job->processedBytes,
                'object_key_rule' => 'files/sha256(UTF-8 relative path)'], JSON_THROW_ON_ERROR) . "\n";
            $key = $base + ['Key' => $prefix . 'complete.json'];
            $hash = base64_encode(hash('sha256', $body, true));
            $this->client->request('PutObject', $key + ['Body' => $body, 'ContentLength' => strlen($body),
                'ContentType' => 'application/json', 'ChecksumSHA256' => $hash, 'IfNoneMatch' => '*'], $deadline);
            $this->verify($key, strlen($body), $hash, $deadline);
            return new StepResult($state, $job->processedFiles, $job->processedBytes, true);
        }
        if ($state['inventory_done'] ?? false) {
            throw new RuntimeException('Media plan contains records after inventory.');
        }
        $isInventory = $object['path'] === null;
        if ($isInventory && ($object['sha256'] !== $state['metadata']['inventory_sha256']
            || $next !== $state['metadata']['plan_size'])) {
            throw new RuntimeException('Unexpected inventory object.');
        }
        $key = $base + ['Key' => $prefix . ($isInventory ? 'inventory.jsonl' : 'files/' . hash('sha256', $object['path']))];
        if ($object['size'] <= 8388608) {
            $hash = base64_encode(hex2bin($object['sha256']));
            if ($object['size'] === 0 && $object['sha256'] !== hash('sha256', '')) {
                throw new RuntimeException('Invalid empty file checksum.');
            }
            $stream = $object['size'] === 0 ? null : ($isInventory ? $plan->open('inventory.jsonl')
                : (new MediaSource($state['metadata']['root']))->openFile($object['path']));
            try {
                if (is_resource($stream) && fstat($stream)['size'] !== $object['size']) {
                    throw new RuntimeException('Media file size changed.');
                }
                $arguments = $key + ['Body' => $stream ?? '', 'ContentLength' => $object['size'],
                    'ChecksumSHA256' => $hash, 'IfNoneMatch' => '*'];
                if (is_resource($stream)) {
                    $arguments['RangeOffset'] = 0;
                }
                $this->client->request('PutObject', $arguments, $deadline);
                $this->verify($key, $object['size'], $hash, $deadline);
            } finally {
                if (is_resource($stream)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    fclose($stream);
                }
            }
            return $this->advance($job, $state, $object, $next);
        }
        if (! isset($state['upload_id'])) {
            $result = $this->client->request('CreateMultipartUpload', $key + [
                'ChecksumAlgorithm' => 'SHA256', 'ContentType' => $isInventory ? 'application/x-ndjson' : 'application/octet-stream',
            ], $deadline);
            if (! is_string($result['UploadId'] ?? null) || $result['UploadId'] === '') {
                throw new RuntimeException('Missing multipart upload identifier.');
            }
            $state['upload_id'] = $result['UploadId'];
            $state['part'] = 1;
            return new StepResult($state, $job->processedFiles, $job->processedBytes);
        }
        $upload = $key + ['UploadId' => $state['upload_id']];
        $part = $state['part'];
        if ($part <= count($object['parts'])) {
            $stream = $isInventory ? $plan->open('inventory.jsonl')
                : (new MediaSource($state['metadata']['root']))->openFile($object['path']);
            try {
                if (fstat($stream)['size'] !== $object['size']) {
                    throw new RuntimeException('Media file size changed.');
                }
                $offset = ($part - 1) * $object['part_size'];
                $length = min($object['part_size'], $object['size'] - $offset);
                // S3 rejects changed bytes using the checksum computed during preparation.
                $result = $this->client->request('UploadPart', $upload + ['PartNumber' => $part,
                    'Body' => $stream, 'RangeOffset' => $offset, 'ContentLength' => $length,
                    'ChecksumSHA256' => $object['parts'][$part - 1]], $deadline);
                if (($result['ChecksumSHA256'] ?? null) !== $object['parts'][$part - 1]) {
                    throw new RuntimeException('Multipart checksum verification failed.');
                }
            } finally {
                if (is_resource($stream)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    fclose($stream);
                }
            }
            ++$state['part'];
            return new StepResult($state, $job->processedFiles, $job->processedBytes);
        }
        $hash = hash_init('sha256');
        foreach ($object['parts'] as $checksum) {
            hash_update($hash, base64_decode($checksum, true));
        }
        $composite = base64_encode(hash_final($hash, true)) . '-' . count($object['parts']);
        // Recover acknowledgement loss after CompleteMultipartUpload without repeating it.
        $head = $this->client->request('HeadObject', $key + ['ChecksumMode' => 'ENABLED'], $deadline);
        if ($head['missing'] ?? false) {
            $parts = [];
            $marker = 0;
            do {
                $page = $this->client->request('ListParts', $upload + ['PartNumberMarker' => $marker, 'MaxParts' => 1000], $deadline);
                foreach ($page['Parts'] ?? [] as $remote) {
                    $number = count($parts) + 1;
                    $length = min($object['part_size'], $object['size'] - ($number - 1) * $object['part_size']);
                    if (($remote['PartNumber'] ?? null) !== $number
                        || ($remote['ChecksumSHA256'] ?? null) !== ($object['parts'][$number - 1] ?? null)
                        // REST-XML long values may be decimal strings in the PHP SDK.
                        || ! in_array($remote['Size'] ?? null, [$length, (string) $length], true)
                        || ! is_string($remote['ETag'] ?? null)) {
                        throw new RuntimeException('Unexpected uploaded media part.');
                    }
                    $parts[] = ['PartNumber' => $number, 'ETag' => $remote['ETag'], 'ChecksumSHA256' => $remote['ChecksumSHA256']];
                }
                $more = ! empty($page['IsTruncated']);
                $nextMarker = $page['NextPartNumberMarker'] ?? 0;
                if ($more && (! is_int($nextMarker) || $nextMarker <= $marker || count($parts) >= 10000)) {
                    throw new RuntimeException('Invalid multipart pagination.');
                }
                $marker = $nextMarker;
            } while ($more);
            if (count($parts) !== count($object['parts'])) {
                throw new RuntimeException('Missing uploaded media parts.');
            }
            $this->client->request('CompleteMultipartUpload', $upload + ['MultipartUpload' => ['Parts' => $parts], 'IfNoneMatch' => '*'], $deadline);
            $this->verify($key, $object['size'], $composite, $deadline);
        } else {
            $this->assertHead($head, $object['size'], $composite);
        }
        return $this->advance($job, $state, $object, $next);
    }

    private function verify(array $key, int $size, string $hash, int $deadline): void
    {
        $this->assertHead($this->client->request('HeadObject', $key + ['ChecksumMode' => 'ENABLED'], $deadline), $size, $hash);
    }

    private function assertHead(array $head, int $size, string $hash): void
    {
        if (($head['ContentLength'] ?? null) !== $size || ($head['ChecksumSHA256'] ?? null) !== $hash) {
            throw new RuntimeException('Remote media checksum verification failed.');
        }
    }

    private function advance(BackupJob $job, array $state, array $object, int $next): StepResult
    {
        $state['offset'] = $next;
        $state['plan_chain'] = hash('sha256', ($state['plan_chain'] ?? str_repeat('0', 64)) . $object['record_hash']);
        unset($state['upload_id'], $state['part']);
        $inventory = $object['path'] === null;
        if ($inventory) {
            $state['inventory_done'] = true;
        }
        return new StepResult($state, $job->processedFiles + ($inventory ? 0 : 1),
            $job->processedBytes + ($inventory ? 0 : $object['size']));
    }
}
