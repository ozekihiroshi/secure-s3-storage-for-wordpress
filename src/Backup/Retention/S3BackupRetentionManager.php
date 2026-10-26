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

            if (count($objects) <= $policy->getKeepCount()) {
                return [];
            }

            // A failed/unverified upload must not displace a good recovery resource.
            // Legacy objects lack this evidence: retain everything until enough
            // newer, verifiable backups fill the requested keep window.
            foreach (array_slice($objects, 0, $policy->getKeepCount()) as $retained) {
                $head = $this->client->headObject([
                    'Bucket' => $bucket,
                    'Key' => $retained->getKey(),
                    'ChecksumMode' => 'ENABLED',
                ]);
                $hash = $head['Metadata']['odbfs3-sha256'] ?? null;
                if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
                    || $retained->getSizeBytes() <= 0
                    || ($head['ContentLength'] ?? null) !== $retained->getSizeBytes()
                    || ($head['ChecksumSHA256'] ?? null) !== base64_encode(hex2bin($hash))) {
                    throw new RuntimeException('Retention stopped: retained backup integrity is unverified.');
                }
            }

            return array_values(
                array_slice(
                    $objects,
                    $policy->getKeepCount()
                )
            );
        } catch (AwsException $e) {
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
     * Delete previously identified retention candidates.
     *
     * This method does not calculate retention policy.
     *
     * @param list<RetentionCandidate> $candidates
     */
    public function deleteCandidates(
        array $candidates
    ): RetentionDeleteResult {
        if ($candidates === []) {
            return new RetentionDeleteResult(
                []
            );
        }

        $deleted = [];

        foreach ($candidates as $candidate) {
            if (
                ! $candidate
                    instanceof RetentionCandidate
            ) {
                throw new RuntimeException(
                    'Invalid retention deletion candidate.'
                );
            }

            $this->deleteCandidate(
                $candidate
            );

            $deleted[] = $candidate;
        }

        return new RetentionDeleteResult(
            $deleted
        );
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
        $seenTokens = [];

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

            if (! is_bool($result['IsTruncated'] ?? null)
                || (isset($result['Contents']) && ! is_array($result['Contents']))) {
                throw new RuntimeException('Incomplete S3 retention listing.');
            }
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
                        if (isset($objects[$candidate->getKey()])) {
                            throw new RuntimeException('Duplicate object in S3 retention listing.');
                        }
                        $objects[$candidate->getKey()] = $candidate;
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
            if ($isTruncated) {
                if ($continuationToken === null || isset($seenTokens[$continuationToken])) {
                    throw new RuntimeException('Invalid S3 retention pagination.');
                }
                $seenTokens[$continuationToken] = true;
            }
        } while (
            $isTruncated
            && $continuationToken !== null
        );

        return array_values($objects);
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
             * If S3 metadata is incomplete,
             * do not consider the object safe
             * for automatic retention.
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

    private function deleteCandidate(
        RetentionCandidate $candidate
    ): void {
        $bucket =
            $candidate->getBucket();

        $key =
            $candidate->getKey();

        if (
            $bucket === ''
            || $key === ''
        ) {
            throw new RuntimeException(
                'Invalid retention deletion candidate.'
            );
        }

        /*
         * Defense in depth:
         *
         * Even though candidates normally come from
         * findDeletionCandidates(), validate the object
         * key again before issuing DeleteObject.
         */
        if (
            ! $this->looksLikeDatabaseBackupKey(
                $key
            )
        ) {
            throw new RuntimeException(
                'Unsafe retention deletion candidate.'
            );
        }

        try {
            $this->client->deleteObject(
                [
                    'Bucket' => $bucket,
                    'Key' => $key,
                ]
            );
        } catch (AwsException $e) {
            throw new RuntimeException(
                'Unable to delete an S3 backup object.'
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to delete an S3 backup object.'
            );
        }
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

        return $this->isBackupRelativeKey(substr($key, strlen($backupPrefix)));
    }

    private function looksLikeDatabaseBackupKey(
        string $key
    ): bool {
        $qualifiedKey = '/' . $key;
        $marker = '/' . self::DATABASE_BACKUP_PATH;
        $offset = strrpos($qualifiedKey, $marker);
        return $offset !== false && $this->isBackupRelativeKey(
            substr($qualifiedKey, $offset + strlen($marker))
        );
    }

    private function isBackupRelativeKey(string $key): bool
    {
        // Support both the original timestamp name and the newer random suffix.
        return preg_match(
            '~^(\d{4})/(\d{2})/(\d{2})/db-[A-Za-z0-9._-]+-\1\2\3-\d{6}(?:-[a-f0-9]{32})?\.sql\.gz$~D',
            $key
        ) === 1;
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

        if (
            is_string($value)
            && $value !== ''
        ) {
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
