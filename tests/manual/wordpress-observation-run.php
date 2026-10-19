<?php
// Dedicated fixture storage. Caller has a private /private directory and one worker.
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use SecureS3StorageForWordpress\Backup\Recovery\RecoveryManifest;
use SecureS3StorageForWordpress\WordPress\WordPressRecoveryCatalog;
final class FixtureObservationJournal implements JobStore
{
    public function __construct(private string $path) {}
    public function read():?string{return is_file($this->path)?file_get_contents($this->path):null;}
    public function compareAndSwap(?string $expected,string $replacement):bool {
        $lock=fopen($this->path.'.lock','c');flock($lock,LOCK_EX);
        try {
            if($this->read()!==$expected)return false;
            $out=fopen($this->path.'.next','wb');
            if(fwrite($out,$replacement)!==strlen($replacement)||!fflush($out)||!fsync($out))throw new RuntimeException('Fixture journal write failed');
            fclose($out);return rename($this->path.'.next',$this->path);
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
}
function fixtureRestoreCatalog(RecoveryManifest $manifest):WordPressRecoveryCatalog {
    // Independent lab operator table, never overwrite the restored site's job state.
    $operator=new wpdb('fixture',getenv('FIXTURE_PASSWORD'),'fixture','fixture-db');
    $operator->options='observer_options';
    $operator->query('CREATE TABLE IF NOT EXISTS observer_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL, autoload varchar(20) NOT NULL)');
    $bytes=file_get_contents('/expected/collector.json');$r=json_decode($bytes,true,24,JSON_THROW_ON_ERROR);
    if($r['manifest']!==$manifest->encode() || $r['phase']!=='complete')throw new RuntimeException('Operator evidence mismatch');
    $store=SecureS3StorageForWordpress\WordPress\WordPressJobStore::forRecovery($operator,$manifest->toArray()['set_id']);
    if($store->read()===null)$store->compareAndSwap(null,$bytes);
    if($store->read()!==$bytes)throw new RuntimeException('Operator evidence changed');
    return new WordPressRecoveryCatalog($operator);
}
