<?php
declare(strict_types=1);
namespace SecureS3StorageForWordpress\Backup\Recovery;

use Throwable;

/** Observations are local assertions, not a new availability probe or signature. */
final class RecoveryObservationSummary
{
    public static function aggregate(RecoveryManifest $manifest, array $rows, string $now, int $maxAge, bool $truncated): array
    {
        Contract::time($now); Contract::require($maxAge >= 1 && $maxAge <= 31536000);
        $groups = ['availability'=>[], 'restore'=>[]]; $invalid = 0;
        $id = $manifest->toArray()['set_id'];
        foreach ($rows as $row) {
            try {
                $bytes = $row['option_value'];
                Contract::require(is_string($bytes) && strlen($bytes) <= 16384);
                Contract::require($row['option_name'] === 'odbfs3_observation_'.$id.'_'.hash('sha256', $bytes));
                $r = RecoveryObservation::forManifest($manifest, json_decode($bytes, true, 16, JSON_THROW_ON_ERROR))->toArray();
                Contract::require($r['checked_at'] <= $now);
                $groups[$r['kind']][] = $r;
            } catch (Throwable $e) { ++$invalid; }
        }
        $valid = ! $truncated && $invalid === 0;
        $result = ['schema_version'=>1, 'set_id'=>$id, 'manifest_sha256'=>hash('sha256', $manifest->encode()),
            'observed_at'=>$now, 'max_age_seconds'=>$maxAge, 'records_scanned'=>count($rows),
            'aggregation_complete'=>$valid, 'invalid_records'=>$invalid, 'truncated'=>$truncated,
            'reason_code'=>$truncated ? 'observation_limit_exceeded' : ($invalid ? 'observation_records_invalid' : null)];
        foreach ($groups as $kind=>$records) {
            usort($records, static fn (array $a, array $b): int => strcmp($b['checked_at'], $a['checked_at']));
            $r = $records[0] ?? null;
            // Do not break conflicting same-time observations in favour of success.
            $conflict = false;
            if ($r !== null) {
                foreach ($records as $other) {
                    if ($other['checked_at'] !== $r['checked_at']) { break; }
                    $left=$r; $right=$other; sort($left['checks']); sort($right['checks']);
                    if ($left != $right) { $conflict=true; }
                }
            }
            $usable = $valid && ! $conflict && $r !== null;
            $stale = $usable && strtotime($now)-strtotime($r['checked_at']) > $maxAge;
            $result[$kind] = ['state'=>$usable && ! $stale ? $r['state'] : 'unknown',
                'recorded_state'=>$usable ? $r['state'] : null,
                'checked_at'=>$usable ? $r['checked_at'] : null,
                'freshness'=>!$usable ? 'unknown' : ($stale ? 'stale' : 'fresh'),
                'environment_id'=>$usable ? $r['environment_id'] : null,
                'checks'=>$usable ? $r['checks'] : [],
                'reason_code'=>!$valid ? 'aggregation_incomplete' : ($conflict ? 'observation_conflict' : ($r===null ? 'no_observation' : ($stale ? 'observation_stale' : null)))];
            if ($conflict) { $result['aggregation_complete']=false; $result['reason_code']='observation_conflict'; }
        }
        return $result;
    }
}
