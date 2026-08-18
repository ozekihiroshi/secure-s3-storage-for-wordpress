<?php

namespace SecureS3StorageForWordpress\Aws;

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\S3\S3Client;
use Throwable;

class ConnectionTester
{
    public function test(
        S3Client $client,
        string $bucket,
        string $prefix = ''
    ): array {
        $testKey = '';
        $objectCreated = false;

        try {
            $client->headBucket([
                'Bucket' => $bucket,
            ]);

            $prefix = $this->normalizePrefix($prefix);

            $testKey = $prefix
                . 'ozeki-database-backup-for-s3-connection-test-'
                . bin2hex(random_bytes(8))
                . '.txt';

            /*
             * This content is temporary machine-generated test data,
             * not administrator-facing UI text, so it is intentionally
             * not translated.
             */
            $testContent = 'Ozeki Database Backup for S3 connection test: '
                . gmdate('c')
                . ' '
                . bin2hex(random_bytes(8));

            $client->putObject([
                'Bucket' => $bucket,
                'Key'    => $testKey,
                'Body'   => $testContent,
            ]);

            $objectCreated = true;

            $result = $client->getObject([
                'Bucket' => $bucket,
                'Key'    => $testKey,
            ]);

            $body = (string) $result['Body'];

            if (! hash_equals($testContent, $body)) {
                return [
                    'success' => false,
                    'message' => __(
                        'S3 test object content verification failed.',
                        'ozeki-database-backup-for-s3'
                    ),
                ];
            }

            $client->deleteObject([
                'Bucket' => $bucket,
                'Key'    => $testKey,
            ]);

            $objectCreated = false;

            return [
                'success' => true,
                'message' => __(
                    'S3 read/write/delete test successful.',
                    'ozeki-database-backup-for-s3'
                ),
            ];

        } catch (CredentialsException $e) {
            return [
                'success' => false,
                'message' => __(
                    'AWS credentials could not be found.',
                    'ozeki-database-backup-for-s3'
                ),
            ];

        } catch (AwsException $e) {
            return [
                'success' => false,
                'message' => $this->safeAwsErrorMessage($e),
            ];

        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => __(
                    'An unexpected error occurred while testing the S3 connection.',
                    'ozeki-database-backup-for-s3'
                ),
            ];

        } finally {
            if ($objectCreated && $testKey !== '') {
                try {
                    $client->deleteObject([
                        'Bucket' => $bucket,
                        'Key'    => $testKey,
                    ]);
                } catch (Throwable $e) {
                    // Do not expose cleanup errors or credentials.
                }
            }
        }
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix);
        $prefix = trim($prefix, '/');

        return $prefix === '' ? '' : $prefix . '/';
    }

    private function safeAwsErrorMessage(AwsException $e): string
    {
        $statusCode = $e->getStatusCode();
        $errorCode = $e->getAwsErrorCode();

        if ($statusCode === 403 || $errorCode === 'AccessDenied') {
            return __(
                'Access denied while testing S3 object operations.',
                'ozeki-database-backup-for-s3'
            );
        }

        if ($statusCode === 404 || $errorCode === 'NoSuchBucket') {
            return __(
                'The configured S3 bucket was not found.',
                'ozeki-database-backup-for-s3'
            );
        }

        return __(
            'Unable to complete the S3 connection test.',
            'ozeki-database-backup-for-s3'
        );
    }
}