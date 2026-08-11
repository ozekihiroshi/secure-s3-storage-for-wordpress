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
                . 'secure-s3-storage-connection-test-'
                . bin2hex(random_bytes(8))
                . '.txt';

            $testContent = 'Secure S3 Storage connection test: '
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
                    'message' => 'S3 test object content verification failed.',
                ];
            }

            $client->deleteObject([
                'Bucket' => $bucket,
                'Key'    => $testKey,
            ]);

            $objectCreated = false;

            return [
                'success' => true,
                'message' => 'S3 read/write/delete test successful.',
            ];

        } catch (CredentialsException $e) {
            return [
                'success' => false,
                'message' => 'AWS credentials could not be found.',
            ];

        } catch (AwsException $e) {
            return [
                'success' => false,
                'message' => $this->safeAwsErrorMessage($e),
            ];

        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'An unexpected error occurred while testing the S3 connection.',
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
            return 'Access denied while testing S3 object operations.';
        }

        if ($statusCode === 404 || $errorCode === 'NoSuchBucket') {
            return 'The configured S3 bucket was not found.';
        }

        return 'Unable to complete the S3 connection test.';
    }
}