<?php

use SecureS3StorageForWordpress\Backup\Media\MediaObjectClient;

/** Small-object disk transport for this WordPress fixture, never real S3. */
final class WordPressRecoveryRemote implements MediaObjectClient
{
    public function path(string $bucket, string $key): string
    {
        return '/remote/' . hash('sha256', $bucket . '/' . $key);
    }

    public function request(string $operation, array $a, int $deadline): array
    {
        $path = $this->path($a['Bucket'], $a['Key']);
        if ($operation === 'PutObject') {
            if (($a['IfNoneMatch'] ?? null) !== '*') { throw new RuntimeException('Conditional write required.'); }
            if (is_file($path)) { return ['exists' => true]; }
            $body = $a['Body'];
            if (is_resource($body)) { fseek($body, $a['RangeOffset'] ?? 0); $body = stream_get_contents($body, $a['ContentLength']); }
            if (strlen($body) !== $a['ContentLength'] || base64_encode(hash('sha256', $body, true)) !== $a['ChecksumSHA256']) {
                throw new RuntimeException('Fixture upload checksum mismatch.');
            }
            $file = fopen($path, 'xb');
            if (fwrite($file, $body) !== strlen($body)) { throw new RuntimeException('Fixture write failed.'); }
            fclose($file);
            return [];
        }
        if (! is_file($path)) {
            if ($operation === 'HeadObject') { return ['missing' => true]; }
            throw new RuntimeException('Fixture object missing.');
        }
        $head = ['ContentLength' => filesize($path), 'ChecksumSHA256' => base64_encode(hash_file('sha256', $path, true)), 'ETag' => hash_file('sha256', $path)];
        if ($operation === 'HeadObject') { return $head; }
        if ($operation === 'GetObject' && ($a['IfMatch'] ?? '') === $head['ETag']) { return $head + ['Body' => fopen($path, 'rb')]; }
        throw new RuntimeException('Unexpected fixture operation.');
    }
}
