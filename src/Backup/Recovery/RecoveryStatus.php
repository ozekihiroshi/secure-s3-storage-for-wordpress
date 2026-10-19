<?php

declare(strict_types=1);

namespace SecureS3StorageForWordpress\Backup\Recovery;

use SecureS3StorageForWordpress\Backup\Job\JobStore;

/** Local evidence only. Never creates a workspace, verifies S3, or advances a job. */
final class RecoveryStatus
{
    public static function read(JobStore $store, string $id): ?array
    {
        Contract::id($id);
        $r = (new RecoveryCollector($store))->current();
        if ($r === null) { return null; }
        Contract::require($r['intent']['id'] === $id);
        $complete = $r['phase'] === 'complete';
        $manifest = $r['manifest'];
        $database = $r['database'];
        $media = $r['media'];
        return [
            'schema_version' => 1,
            'set_id' => $id,
            'source_id' => $r['intent']['sourceId'],
            'profile' => $r['intent']['profile'],
            'created_at' => $r['intent']['createdAt'],
            'phase' => $r['phase'],
            'completeness' => $complete ? 'complete' : (($database !== null || $media !== null) ? 'partial' : 'none'),
            'reason_code' => $r['error'] === '' ? null : $r['error'],
            'assets' => [
                'database_set_id' => $database['set_id'] ?? null,
                'media_set_id' => $media['set_id'] ?? null,
                'media_job_id' => $media['job_id'] ?? null,
            ],
            'verification' => [
                'database' => $database === null ? 'not_verified' : 'verified',
                'media' => $r['intent']['profile'] === 'database' ? 'not_included' : ($media === null ? 'not_verified' : 'verified'),
                'database_checked_at' => $database['verified_at'] ?? null,
                'media_checked_at' => $media['verified_at'] ?? null,
            ],
            'manifest' => [
                'local_record' => $manifest === null ? 'absent' : 'present',
                'sha256' => $manifest === null ? null : hash('sha256', $manifest),
                'publication' => $complete ? 'verified' : 'not_verified',
                'publication_checked_at' => $complete ? $r['checkpoint']['verified_at'] : null,
            ],
            'capture_consistency' => $manifest === null ? null : RecoveryManifest::decode($manifest)->toArray()['capture']['consistency'],
            'availability' => ['state' => 'unknown', 'checked_at' => null],
            'restore' => ['state' => 'not_tested', 'checked_at' => null],
            'observation_scope' => 'local_collector_record_only',
        ];
    }
}
