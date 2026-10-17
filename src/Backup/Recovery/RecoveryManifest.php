<?php

declare(strict_types=1);

namespace SecureS3StorageForWordpress\Backup\Recovery;

/** Component verification evidence, NOT evidence of publication or successful restore. */
final class RecoveryManifest
{
    public const EXCLUDED = ['wordpress_core', 'themes', 'plugins', 'wp_config', 'other_files', 'server_configuration'];
    public const REQUIREMENTS = ['supply_software_and_configuration_separately', 'not_an_atomic_snapshot', 'restore_test_required'];

    private function __construct(private array $record, private ?string $originalBytes = null)
    {
    }

    public static function decode(string $json): self
    {
        $validated = self::fromArray(Contract::decode($json));
        // Evidence binds exact downloaded bytes, including whitespace/key order.
        // Re-encoding a remote manifest would silently change its identity.
        return new self($validated->toArray(), $json);
    }

    public static function fromArray(array $r): self
    {
        Contract::fields($r, ['format', 'schema_version', 'set_id', 'source_id', 'profile', 'created_at', 'completed_at',
            'destination', 'environment', 'capture', 'database', 'media', 'verification', 'scope', 'requirements']);
        Contract::require($r['format'] === 'odbfs3-recovery-set' && $r['schema_version'] === 1);
        Contract::fields($r['destination'], ['bucket', 'region', 'prefix']);
        $set = RecoverySet::intent($r['set_id'], $r['source_id'], $r['profile'], $r['destination']['bucket'],
            $r['destination']['region'], $r['destination']['prefix'], $r['created_at']);
        Contract::time($r['completed_at']);
        Contract::require($r['created_at'] <= $r['completed_at']);
        self::validateEnvironment($r['environment']);
        Contract::fields($r['capture'], ['consistency', 'assertion']);
        Contract::require(in_array($r['capture']['consistency'], ['uncoordinated', 'operator_asserted_quiescence'], true));
        if ($r['capture']['consistency'] === 'uncoordinated') {
            Contract::require($r['capture']['assertion'] === null);
        } else {
            Contract::fields($r['capture']['assertion'], ['interval', 'method']);
            Contract::interval($r['capture']['assertion']['interval'], $r['created_at'], $r['completed_at']);
            Contract::require(in_array($r['capture']['assertion']['method'], ['operator_stopped_writes', 'external_snapshot'], true));
        }

        self::database($r['database'], $r, $set);
        if ($r['profile'] === 'database') {
            Contract::require($r['media'] === null);
        } else {
            self::media($r['media'], $r, $set);
        }
        Contract::fields($r['verification'], ['database', 'media']);
        Contract::require($r['verification']['database'] === 'verified'
            && $r['verification']['media'] === ($r['profile'] === 'database' ? 'not_included' : 'verified'));
        Contract::fields($r['scope'], ['included', 'excluded']);
        Contract::require($r['scope']['included'] === ($r['profile'] === 'database' ? ['database'] : ['database', 'uploads'])
            && $r['scope']['excluded'] === ($r['profile'] === 'database' ? array_merge(self::EXCLUDED, ['uploads']) : self::EXCLUDED)
            && $r['requirements'] === self::REQUIREMENTS);
        $manifest = new self($r);
        Contract::require(strlen($manifest->encode()) <= 65536);
        return $manifest;
    }

    /** Validate one completed component before persisting its evidence. */
    public static function validateEnvironment(array $environment): void
    {
        Contract::fields($environment, ['wordpress', 'php', 'plugin', 'database_server', 'table_prefix']);
        foreach ($environment as $value) {
            Contract::require(is_string($value) && preg_match('/^[A-Za-z0-9_.+ -]{1,128}$/D', $value) === 1);
        }
        Contract::require(preg_match('/^[A-Za-z0-9_]+$/D', $environment['table_prefix']) === 1);
    }

    public static function validateEvidence(RecoverySet $set, string $kind, array $evidence): void
    {
        Contract::require(in_array($kind, ['database', 'media'], true));
        $r = ['set_id' => $set->id, 'source_id' => $set->sourceId, 'created_at' => $set->createdAt,
            'completed_at' => $evidence['verified_at'] ?? '', 'capture' => ['assertion' => null]];
        Contract::time($r['completed_at']);
        if ($kind === 'database') {
            self::database($evidence, $r, $set);
        } else {
            Contract::require($set->profile === 'database_uploads');
            self::media($evidence, $r, $set);
        }
    }

    public static function assemble(RecoverySet $set, array $environment, array $database, ?array $media): self
    {
        return self::fromArray(['format' => 'odbfs3-recovery-set', 'schema_version' => 1, 'set_id' => $set->id,
            'source_id' => $set->sourceId, 'profile' => $set->profile, 'created_at' => $set->createdAt,
            'completed_at' => max($database['verified_at'], $media['verified_at'] ?? $database['verified_at']),
            'destination' => ['bucket' => $set->bucket, 'region' => $set->region, 'prefix' => $set->prefix],
            'environment' => $environment, 'capture' => ['consistency' => 'uncoordinated', 'assertion' => null],
            'database' => $database, 'media' => $media,
            'verification' => ['database' => 'verified', 'media' => $set->profile === 'database' ? 'not_included' : 'verified'],
            'scope' => ['included' => $set->profile === 'database' ? ['database'] : ['database', 'uploads'],
                'excluded' => $set->profile === 'database' ? array_merge(self::EXCLUDED, ['uploads']) : self::EXCLUDED],
            'requirements' => self::REQUIREMENTS]);
    }

    private static function component(array $c, array $r): void
    {
        Contract::require($c['set_id'] === $r['set_id'] && $c['source_id'] === $r['source_id']);
        Contract::interval($c['capture'], $r['created_at'], $r['completed_at']);
        Contract::interval($c['transfer'], $c['capture']['end'], $r['completed_at']);
        Contract::time($c['verified_at']);
        Contract::require($c['transfer']['end'] <= $c['verified_at'] && $c['verified_at'] <= $r['completed_at']);
        if ($r['capture']['assertion'] !== null) {
            $interval = $r['capture']['assertion']['interval'];
            Contract::require($interval['start'] <= $c['capture']['start'] && $interval['end'] >= $c['capture']['end']);
        }
    }

    private static function artifact(array $a, string $key): void
    {
        Contract::fields($a, ['key', 'size_bytes', 'sha256', 'version_id']);
        Contract::require($a['key'] === $key && strlen($key) <= 1024);
        Contract::count($a['size_bytes'], 1);
        Contract::hash($a['sha256']);
        Contract::require($a['version_id'] === null || (is_string($a['version_id']) && strlen($a['version_id']) >= 1
            && strlen($a['version_id']) <= 1024 && preg_match('/^[\x21-\x7e]+$/D', $a['version_id']) === 1));
    }

    private static function database(array $c, array $r, RecoverySet $set): void
    {
        Contract::fields($c, ['set_id', 'source_id', 'capture', 'transfer', 'verified_at', 'artifact', 'backend', 'raw_sql', 'method']);
        self::component($c, $r);
        self::artifact($c['artifact'], $set->root() . 'database/dump.sql.gz');
        Contract::require(in_array($c['backend'], ['native', 'php'], true) && $c['method'] === 'sql_gzip_s3_sha256');
        Contract::fields($c['raw_sql'], ['size_bytes', 'sha256']);
        Contract::count($c['raw_sql']['size_bytes'], 1);
        Contract::hash($c['raw_sql']['sha256']);
    }

    private static function media(array $c, array $r, RecoverySet $set): void
    {
        Contract::fields($c, ['set_id', 'source_id', 'capture', 'transfer', 'verified_at', 'job_id', 'prefix', 'inventory', 'marker',
            'file_count', 'total_bytes', 'marker_evidence', 'method']);
        self::component($c, $r);
        Contract::id($c['job_id']);
        Contract::require($c['prefix'] === $set->root() . 'media/' . $c['job_id'] . '/');
        self::artifact($c['inventory'], $c['prefix'] . 'inventory.jsonl');
        self::artifact($c['marker'], $c['prefix'] . 'complete.json');
        Contract::count($c['file_count']);
        Contract::count($c['total_bytes']);
        Contract::require($c['file_count'] !== 0 || $c['total_bytes'] === 0);
        Contract::require($c['method'] === 'inventory_full_sha256_objects_full_or_composite_sha256');
        $m = $c['marker_evidence'];
        Contract::fields($m, ['format', 'version', 'run', 'inventory', 'inventory_sha256', 'files', 'bytes', 'object_key_rule']);
        Contract::require($m['format'] === 'odbfs3-media-complete' && $m['version'] === 1
            && $m['run'] === $c['job_id'] && $m['inventory'] === 'inventory.jsonl'
            && $m['inventory_sha256'] === $c['inventory']['sha256'] && $m['files'] === $c['file_count']
            && $m['bytes'] === $c['total_bytes'] && $m['object_key_rule'] === 'files/sha256(UTF-8 relative path)');
        // Remote marker bytes/hash must be verified by the future storage adapter.
        // This model validates the semantic binding, not an invented canonical hash.
    }

    public function toArray(): array
    {
        return $this->record;
    }

    public function encode(): string
    {
        return $this->originalBytes ?? json_encode($this->record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES, 16) . "\n";
    }
}
