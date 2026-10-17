<?php

namespace SecureS3StorageForWordpress\Aws;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use RuntimeException;
use SecureS3StorageForWordpress\Backup\Job\RetryableJobException;
use SecureS3StorageForWordpress\Backup\Media\MediaObjectClient;

final class MediaS3Client implements MediaObjectClient
{
    public function __construct(private S3Client $client)
    {
    }

    public static function create(string $region): self
    {
        return new self(new S3Client([
            'version' => 'latest', 'region' => $region, 'retries' => 0,
            'http' => ['connect_timeout' => 5, 'timeout' => 20],
        ]));
    }

    public function request(string $operation, array $arguments, int $deadline): array
    {
        $remaining = $deadline - time() - 2;
        if ($remaining < 1) {
            throw new RetryableJobException('S3 worker deadline reached.');
        }
        $borrowedBody = null;
        try {
            if (isset($arguments['RangeOffset'])) {
                // The caller owns raw resources and closes them after this request.
                // Keep the wrapper alive, then detach it even when the SDK throws.
                $stream = Utils::streamFor($arguments['Body']);
                if (is_resource($arguments['Body'])) { $borrowedBody = $stream; }
                $arguments['Body'] = new LimitStream(
                    $stream, $arguments['ContentLength'], $arguments['RangeOffset'],
                );
                unset($arguments['RangeOffset']);
            }
            $arguments['@http'] = ['connect_timeout' => min(5, $remaining), 'timeout' => min(20, $remaining)];
            if ($operation === 'GetObject') { $arguments['@http']['stream'] = true; }
            return $this->client->execute($this->client->getCommand($operation, $arguments))->toArray();
        } catch (S3Exception $e) {
            // Distinguish only explicitly recoverable responses, never expose signed requests.
            if ($operation === 'HeadObject' && $e->getStatusCode() === 404) {
                return ['missing' => true];
            }
            if ($operation === 'PutObject' && $e->getStatusCode() === 412) {
                return ['exists' => true];
            }
            if ($operation === 'AbortMultipartUpload' && $e->getStatusCode() === 404) {
                return ['missing' => true];
            }
            $status = $e->getStatusCode();
            if ($status === null || $status === 408 || $status === 429 || $status >= 500
                || ($operation === 'PutObject' && $status === 409)) {
                throw new RetryableJobException('Temporary media S3 failure.');
            }
            throw new RuntimeException('Media S3 operation failed.');
        } finally {
            if ($borrowedBody !== null) { $borrowedBody->detach(); }
        }
    }
}
