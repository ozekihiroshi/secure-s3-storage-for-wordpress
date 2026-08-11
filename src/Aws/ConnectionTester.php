<?php

namespace SecureS3StorageForWordpress\Aws;

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\S3\S3Client;
use Throwable;

class ConnectionTester
{
    public function test(S3Client $client, string $bucket): array
    {
        try {
            $client->headBucket([
                'Bucket' => $bucket,
            ]);

            return [
                'success' => true,
                'message' => 'S3 bucket connection successful.',
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
        }
    }

    private function safeAwsErrorMessage(AwsException $e): string
    {
        $statusCode = $e->getStatusCode();
        $errorCode = $e->getAwsErrorCode();

        if ($statusCode === 403 || $errorCode === 'AccessDenied') {
            return 'Access denied to the configured S3 bucket.';
        }

        if ($statusCode === 404 || $errorCode === 'NoSuchBucket') {
            return 'The configured S3 bucket was not found.';
        }

        return 'Unable to connect to the configured S3 bucket.';
    }
}