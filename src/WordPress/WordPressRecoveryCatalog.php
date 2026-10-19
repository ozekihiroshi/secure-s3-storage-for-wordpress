<?php
declare(strict_types=1);
namespace SecureS3StorageForWordpress\WordPress;

use SecureS3StorageForWordpress\Backup\Recovery\{Contract, RecoveryCollector, RecoveryIndex, RecoveryManifest, RecoveryObservation, RecoveryObservationSummary, RecoveryRecordSnapshot};
use RuntimeException;
use wpdb;

/** Bounded direct reads. The sole write API explicitly appends a validated observation. */
final class WordPressRecoveryCatalog
{
    public const LIMIT = 100;
    public function __construct(private wpdb $database) {}

    private function scan(string $prefix, int $size): array
    {
        $previous = $this->database->suppress_errors(true);
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded site-local snapshot, bypass stale option cache.
            $rows = $this->database->get_results($this->database->prepare(
                "SELECT option_name, CASE WHEN OCTET_LENGTH(option_value) <= %d THEN option_value ELSE NULL END AS option_value FROM {$this->database->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT %d",
                $size, $this->database->esc_like($prefix).'%', self::LIMIT+1), ARRAY_A);
            if ($this->database->last_error !== '' || !is_array($rows)) { throw new RuntimeException('Unable to read recovery catalog.'); }
            return [array_slice($rows, 0, self::LIMIT), count($rows)>self::LIMIT];
        } finally { $this->database->suppress_errors($previous); }
    }

    public function latest(string $source, ?string $profile): array
    {
        Contract::id($source);
        Contract::require($profile === null || in_array($profile,['database','database_uploads'],true));
        [$rows,$truncated]=$this->scan('odbfs3_recovery_',196608);
        return RecoveryIndex::latest($rows,$source,$profile,$truncated);
    }

    private function manifest(string $id): ?RecoveryManifest
    {
        Contract::id($id);
        $previous=$this->database->suppress_errors(true);
        try {
            $bytes=WordPressJobStore::forRecovery($this->database,$id)->read();
            $r=(new RecoveryCollector(new RecoveryRecordSnapshot($bytes)))->current();
            if ($r===null) { return null; }
            Contract::require($r['intent']['id']===$id && $r['phase']==='complete');
            return RecoveryManifest::decode($r['manifest']);
        } finally { $this->database->suppress_errors($previous); }
    }

    public function observations(string $id, string $now, int $maxAge=86400): ?array
    {
        Contract::time($now); Contract::require($maxAge>=1 && $maxAge<=31536000);
        $manifest=$this->manifest($id);
        if ($manifest===null) { return null; }
        [$rows,$truncated]=$this->scan('odbfs3_observation_'.$id.'_',16384);
        return RecoveryObservationSummary::aggregate($manifest,$rows,$now,$maxAge,$truncated);
    }

    /** Trusted observer integration only; does not itself execute the claimed checks. */
    public function appendObservation(string $id, array $record, string $now): string
    {
        Contract::time($now);
        $manifest=$this->manifest($id); Contract::require($manifest!==null);
        $validated=RecoveryObservation::forManifest($manifest,$record)->toArray();
        Contract::require($validated['checked_at']<=$now);
        $ordered=[];
        foreach (['set_id','manifest_sha256','kind','state','checked_at','environment_id','checks'] as $key) { $ordered[$key]=$validated[$key]; }
        sort($ordered['checks']);
        $bytes=json_encode($ordered,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        Contract::require(strlen($bytes)<=16384);
        $name='odbfs3_observation_'.$id.'_'.hash('sha256',$bytes);
        $previous=$this->database->suppress_errors(true);
        try {
            // Append only; never overwrite observations or Collector evidence.
            $count=$this->database->query($this->database->prepare(
                "INSERT IGNORE INTO {$this->database->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",$name,$bytes));
            if ($count===false || $this->database->last_error!=='') { throw new RuntimeException('Unable to append observation.'); }
            $existing=$this->database->get_var($this->database->prepare("SELECT option_value FROM {$this->database->options} WHERE option_name = %s",$name));
            Contract::require($this->database->last_error==='' && $existing===$bytes);
            return hash('sha256',$bytes);
        } finally { $this->database->suppress_errors($previous); }
    }
}
