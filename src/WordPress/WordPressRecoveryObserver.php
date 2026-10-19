<?php
declare(strict_types=1);
namespace SecureS3StorageForWordpress\WordPress;

use SecureS3StorageForWordpress\Backup\Recovery\{Contract,ObservedRecoveryRun,RecoveryCollector,RecoveryManifest,RecoveryRecordSnapshot};
use wpdb;

/** Trusted in-process integration. Creating the wrapper does not execute work. */
final class WordPressRecoveryObserver
{
    public static function forSet(wpdb $database,string $setId,string $runId,string $kind,?string $environmentId):ObservedRecoveryRun
    {
        Contract::id($setId);Contract::id($runId);
        $previous=$database->suppress_errors(true);
        try {
            $r=(new RecoveryCollector(new RecoveryRecordSnapshot(WordPressJobStore::forRecovery($database,$setId)->read())))->current();
            Contract::require($r!==null && $r['intent']['id']===$setId && $r['phase']==='complete');
            $manifest=RecoveryManifest::decode($r['manifest']);
        } finally {$database->suppress_errors($previous);}
        $catalog=new WordPressRecoveryCatalog($database);
        return new ObservedRecoveryRun(WordPressJobStore::forObservationRun($database,$runId),$manifest,$kind,$environmentId,
            static fn(array $record):string=>$catalog->appendObservation($setId,$record,gmdate('Y-m-d\TH:i:s\Z')));
    }
}
