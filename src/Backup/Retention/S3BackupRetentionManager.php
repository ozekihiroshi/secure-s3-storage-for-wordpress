<?php

namespace SecureS3StorageForWordpress\Backup\Retention;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use Throwable;

final class S3BackupRetentionManager
{
    private const DATABASE_BACKUP_PATH =
        'backups/database/';

    public function __construct(
        private S3Client $client
    ) {
    }

    /**
     * Find database backup objects that are outside
     * the configured retention window.
     *
     * This method does not delete any S3 objects.
     *
     * @return list<RetentionCandidate>
     */
    public function findDeletionCandidates(
        string $bucket,
        string $prefix,
        RetentionPolicy $policy
    ): array {
        if ($bucket === '') {
            throw new RuntimeException(
                'S3 bucket is required.'
            );
        }

        $backupPrefix =
            $this->buildDatabaseBackupPrefix(
                $prefix
            );

        try {
            $objects =
                $this->listBackupObjects(
                    $bucket,
                    $backupPrefix
                );

            usort(
                $objects,
                static function (
                    RetentionCandidate $a,
                    RetentionCandidate $b
                ): int {
                    $comparison =
                        $b->getLastModified()
                            ->getTimestamp()
                        <=>
                        $a->getLastModified()
                            ->getTimestamp();

                    if ($comparison !== 0) {
                        return $comparison;
                    }

                    return strcmp(
                        $b->getKey(),
                        $a->getKey()
                    );
                }
            );

            return array_values(
                array_slice(
                    $objects,
                    $policy->getKeepCount()
                )
            );
        } catch (AwsException $e) {
            /*
             * Do not expose AWS response details,
             * request IDs, credentials, or raw SDK errors.
             */
            throw new RuntimeException(
                'Unable to inspect S3 backup retention candidates.'
            );
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to inspect S3 backup retention candidates.'
            );
        }
    }

    /**
     * @return list<RetentionCandidate>
     */
    private function listBackupObjects(
        string $bucket,
        string $backupPrefix
    ): array {
        $objects = [];
        $continuationToken = null;

        do {
            $arguments = [
                'Bucket' => $bucket,
                'Prefix' => $backupPrefix,
            ];

            if ($continuationToken !== null) {
                $arguments['ContinuationToken'] =
                    $continuationToken;
            }

            $result =
                $this->client->listObjectsV2(
                    $arguments
                );

            $contents =
                $result['Contents'] ?? [];

            if (is_array($contents)) {
                foreach ($contents as $object) {
                    if (! is_array($object)) {
                        continue;
                    }

                    $candidate =
                        $this->createCandidate(
                            $bucket,
                            $backupPrefix,
                            $object
                        );

                    if ($candidate !== null) {
                        $objects[] = $candidate;
                    }
                }
            }

            $isTruncated =
                (bool) (
                    $result['IsTruncated']
                    ?? false
                );

            $nextToken =
                $result['NextContinuationToken']
                ?? null;

            $continuationToken =
                is_string($nextToken)
                && $nextToken !== ''
                    ? $nextToken
                    : null;
        } while (
            $isTruncated
            && $continuationToken !== null
        );

        return $objects;
    }

    private function createCandidate(
        string $bucket,
        string $backupPrefix,
        array $object
    ): ?RetentionCandidate {
        $key =
            isset($object['Key'])
                ? (string) $object['Key']
                : '';

        if (
            ! $this->isDatabaseBackupKey(
                $key,
                $backupPrefix
            )
        ) {
            return null;
        }

        $sizeBytes =
            isset($object['Size'])
            && is_numeric($object['Size'])
                ? (int) $object['Size']
                : 0;

        $lastModified =
            $this->normalizeLastModified(
                $object['LastModified']
                ?? null
            );

        if ($lastModified === null) {
            /*
             * If S3 metadata is incomplete, do not consider
             * the object safe for automatic retention.
             */
            return null;
        }

        return new RetentionCandidate(
            bucket: $bucket,
            key: $key,
            sizeBytes: $sizeBytes,
            lastModified: $lastModified
        );
    }

    private function isDatabaseBackupKey(
        string $key,
        string $backupPrefix
    ): bool {
        if (
            $key === ''
            || ! str_starts_with(
                $key,
                $backupPrefix
            )
        ) {
            return false;
        }

        $fileName =
            basename($key);

        if (
            ! str_starts_with(
                $fileName,
                'db-'
            )
        ) {
            return false;
        }

        return str_ends_with(
            $fileName,
            '.sql.gz'
        );
    }

    private function buildDatabaseBackupPrefix(
        string $prefix
    ): string {
        $normalizedPrefix =
            trim(
                $prefix,
                '/'
            );

        if ($normalizedPrefix === '') {
            return self::DATABASE_BACKUP_PATH;
        }

        return $normalizedPrefix
            . '/'
            . self::DATABASE_BACKUP_PATH;
    }

    private function normalizeLastModified(
        mixed $value
    ): ?DateTimeImmutable {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface(
                $value
            );
        }

        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable(
                    $value
                );
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }
}