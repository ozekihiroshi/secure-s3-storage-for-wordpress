<?php

declare(strict_types=1);

namespace SecureS3StorageForWordpress\Backup\Recovery;

/** A point-in-time assertion from an observer; never mutates completion evidence. */
final class RecoveryObservation
{
    private function __construct(private array $record)
    {
    }

    public static function forManifest(RecoveryManifest $manifest, array $record): self
    {
        Contract::fields($record, ['set_id', 'manifest_sha256', 'kind', 'state', 'checked_at', 'environment_id', 'checks']);
        $source = $manifest->toArray();
        Contract::require($record['set_id'] === $source['set_id']
            && $record['manifest_sha256'] === hash('sha256', $manifest->encode()));
        Contract::time($record['checked_at']);
        Contract::require($record['checked_at'] >= $source['completed_at']);
        Contract::require(in_array($record['kind'], ['availability', 'restore'], true));
        $states = $record['kind'] === 'availability' ? ['unknown', 'available', 'missing', 'inaccessible'] : ['not_tested', 'passed', 'failed'];
        Contract::require(in_array($record['state'], $states, true));
        Contract::require(is_array($record['checks']) && array_is_list($record['checks']) && count($record['checks']) <= 32);
        $allowed = $record['kind'] === 'availability'
            ? ['database_object', 'inventory_object', 'marker_object', 'all_media_objects']
            : ['sql_import', 'wordpress_content', 'media_hashes', 'attachment_references'];
        foreach ($record['checks'] as $check) {
            Contract::require(is_string($check) && in_array($check, $allowed, true));
        }
        Contract::require(count(array_unique($record['checks'])) === count($record['checks']));
        if ($record['kind'] === 'availability') {
            Contract::require($record['environment_id'] === null);
            $required = $source['profile'] === 'database' ? ['database_object'] : $allowed;
            if ($record['state'] === 'available') {
                Contract::require(! array_diff($required, $record['checks']));
            }
        } elseif ($record['state'] === 'not_tested') {
            Contract::require($record['environment_id'] === null && $record['checks'] === []);
        } else {
            Contract::id($record['environment_id']);
            $required = $source['profile'] === 'database' ? ['sql_import', 'wordpress_content'] : $allowed;
            if ($record['state'] === 'passed') {
                Contract::require(! array_diff($required, $record['checks']));
            }
        }
        return new self($record);
    }

    public function toArray(): array
    {
        return $this->record;
    }
}
