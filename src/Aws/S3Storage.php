<?php

namespace SecureS3StorageForWordpress\Aws;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use RuntimeException;
use Throwable;

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
            $result = $this->client->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'SourceFile' => $sourcePath,
                'ContentType' => 'application/gzip',
            ]);

            $etag = isset($result['ETag'])
                ? trim((string) $result['ETag'], '"')
                : null;

            return new S3UploadResult(
                bucket: $bucket,
                key: $key,
                sizeBytes: $size,
                etag: $etag
            );

        } catch (AwsException $e) {
            throw new RuntimeException(
                $this->safeAwsErrorMessage($e),
                0,
                $e
            );

        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to upload backup to Amazon S3.',
                0,
                $e
            );
        }
    }

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