<?php

namespace SecureS3StorageForWordpress\Aws;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use RuntimeException;
use Throwable;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;

final class S3Storage
{
    public function __construct(
        private S3Client $client
    ) {
    }

    public function upload(
        string $sourcePath,
        string $bucket,
        string $key
    ): S3UploadResult {
        if (! is_file($sourcePath)) {
            throw new RuntimeException(
                'S3 upload source file does not exist.'
            );
        }

        $size = filesize($sourcePath);

        if ($size === false || $size <= 0) {
            throw new RuntimeException(
                'S3 upload source file is empty.'
            );
        }

        $key = $this->normalizeKey($key);

        if ($bucket === '' || $key === '') {
            throw new RuntimeException(
                'S3 bucket and object key are required.'
            );
        }

        try {
            $sha256 = hash_file('sha256', $sourcePath);
            if ($sha256 === false) { throw new RuntimeException('Unable to checksum backup.'); }
            $result = $this->client->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'SourceFile' => $sourcePath,
                'ContentType' => 'application/gzip',
                'ChecksumSHA256' => base64_encode(hex2bin($sha256)),
                'IfNoneMatch' => '*',
            ]);

            $head = $this->client->headObject([
                'Bucket' => $bucket, 'Key' => $key, 'ChecksumMode' => 'ENABLED',
            ]);
            if (($head['ContentLength'] ?? null) !== $size
                || ($head['ChecksumSHA256'] ?? null) !== base64_encode(hex2bin($sha256))) {
                throw new RuntimeException('Remote database backup verification failed.');
            }

            $etag = isset($result['ETag'])
                ? trim((string) $result['ETag'], '"')
                : null;

            return new S3UploadResult(
                bucket: $bucket,
                key: $key,
                sizeBytes: $size,
                etag: $etag,
                sha256: $sha256,
            );

        } catch (AwsException $e) {
            throw new RuntimeException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message is not HTML output.
                $this->safeAwsErrorMessage($e),
                0,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is chained, not output.
                $e
            );

        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to upload backup to Amazon S3.',
                0,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is chained, not output.
                $e
            );
        }
    }

    /** Download to a NEW private file; expected evidence must come from a trusted backup record. */
    // phpcs:disable WordPress.WP.AlternativeFunctions -- Private streamed downloads require direct resource I/O and exact local-file cleanup.
    public function downloadVerified(string $bucket, string $key, string $destination, int $expectedSize, string $expectedSha256): void
    {
        if ($bucket === '' || $key === '' || $expectedSize <= 0 || preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1) {
            throw new RuntimeException('Valid backup verification evidence is required.');
        }
        $output = null;
        $body = null;
        $sink = null;
        $created = $success = false;
        try {
            $output = SecureTemporaryFile::openForWriting($destination, true);
            $created = true;
            // SDK response-checksum validation rewinds the response. Keep it on
            // private disk, seekable and bounded, rather than a live HTTP stream.
            $stream = Utils::streamFor($output);
            $sink = FnStream::decorate($stream, [
                'write' => static function (string $data) use ($stream, $expectedSize): int {
                    if (strlen($data) > $expectedSize - $stream->tell()) {
                        throw new RuntimeException('Backup download exceeds expected size.');
                    }
                    return $stream->write($data);
                },
            ]);
            $result = $this->client->getObject([
                'Bucket' => $bucket, 'Key' => $key, 'ChecksumMode' => 'ENABLED',
                '@http' => ['sink' => $sink],
            ]);
            $body = $sink;
            $body->rewind();
            if (($result['ContentLength'] ?? null) !== $expectedSize) {
                throw new RuntimeException('Downloaded backup size differs.');
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            while (! $body->eof()) {
                $chunk = $body->read(1048576);
                if ($chunk === '' && ! $body->eof()) { throw new RuntimeException('Backup download stalled.'); }
                if (strlen($chunk) > $expectedSize - $bytes) { throw new RuntimeException('Backup download exceeds expected size.'); }
                $bytes += strlen($chunk);
                hash_update($hash, $chunk);
            }
            if ($bytes !== $expectedSize || ! hash_equals($expectedSha256, hash_final($hash))) {
                throw new RuntimeException('Downloaded backup checksum differs.');
            }
            if (! fflush($output)) { throw new RuntimeException('Unable to flush backup download.'); }
            $success = true;
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to download and verify database backup.',
                0,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is chained internally, not rendered as output.
                $e
            );
        } finally {
            if ($body !== null) { $body->close(); }
            elseif ($sink !== null) { $sink->close(); }
            if (is_resource($output)) { fclose($output); }
            if ($created && ! $success) { @unlink($destination); }
        }
    }
    // phpcs:enable WordPress.WP.AlternativeFunctions

    private function normalizeKey(string $key): string
    {
        return ltrim(trim($key), '/');
    }

    private function safeAwsErrorMessage(
        AwsException $exception
    ): string {
        $statusCode = $exception->getStatusCode();
        $errorCode = $exception->getAwsErrorCode();

        if (
            $statusCode === 403
            || $errorCode === 'AccessDenied'
        ) {
            return 'Access denied while uploading backup to Amazon S3.';
        }

        if (
            $statusCode === 404
            || $errorCode === 'NoSuchBucket'
        ) {
            return 'The configured S3 bucket was not found.';
        }

        return 'Amazon S3 backup upload failed.';
    }
}
