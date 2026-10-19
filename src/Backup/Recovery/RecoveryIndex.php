<?php
declare(strict_types=1);
namespace SecureS3StorageForWordpress\Backup\Recovery;

use Throwable;

/** Rebuilt from the site-local records; no independently mutable cache/index. */
final class RecoveryIndex
{
    public static function latest(array $rows, string $source, ?string $profile, bool $truncated): array
    {
        Contract::id($source);
        Contract::require($profile === null || in_array($profile, ['database', 'database_uploads'], true));
        $views = []; $invalid = 0;
        foreach ($rows as $row) {
            try {
                Contract::require(is_string($row['option_name']) && preg_match('/^odbfs3_recovery_([a-f0-9]{32})$/D', $row['option_name'], $match) === 1);
                Contract::require(is_string($row['option_value']));
                $view = RecoveryStatus::read(new RecoveryRecordSnapshot($row['option_value']), $match[1]);
                Contract::require($view !== null);
                if ($view['source_id'] === $source && ($profile === null || $view['profile'] === $profile)) { $views[] = $view; }
            } catch (Throwable $e) { ++$invalid; }
        }
        usort($views, static fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']) ?: strcmp($b['set_id'], $a['set_id']));
        $complete = array_values(array_filter($views, static fn (array $r): bool => $r['phase'] === 'complete'));
        $valid = ! $truncated && $invalid === 0;
        return ['schema_version'=>1, 'source_id'=>$source, 'profile'=>$profile,
            'index_complete'=>$valid, 'records_scanned'=>count($rows), 'invalid_records'=>$invalid,
            'matching_records'=>count($views), 'truncated'=>$truncated,
            'reason_code'=>$truncated ? 'index_limit_exceeded' : ($invalid ? 'index_unreadable_records' : null),
            'latest_attempt'=>$valid ? ($views[0] ?? null) : null,
            'latest_complete'=>$valid ? ($complete[0] ?? null) : null];
    }
}
