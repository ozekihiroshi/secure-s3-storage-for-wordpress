<?php
// Dedicated MU integration for the automated-Cron lab. Not a shipped feature.
if (getenv('ODBFS3_AUTO_CRON')!=='1') { return; }
require_once '/repo/vendor/autoload.php';
use SecureS3StorageForWordpress\Backup\Recovery\{RecoverySet,RecoveryWorkspace,RecoveryPipeline,RecoveryPublisher,RecoveryStep,RecoveryLock,RecoveryProgress};
use SecureS3StorageForWordpress\Backup\Database\{DatabaseConnection,NativeMySqlDumper};
use SecureS3StorageForWordpress\Backup\Database\Php\PhpMySqlDumper;
use SecureS3StorageForWordpress\Backup\Media\MediaSource;
use SecureS3StorageForWordpress\Aws\MediaS3Client;
use SecureS3StorageForWordpress\WordPress\WordPressRecoverySchedule;
function cronLabRecord(array $row):void {
    file_put_contents('/private/cron-events.jsonl',json_encode(['at'=>microtime(true),'pid'=>getmypid(),'doing_cron'=>defined('DOING_CRON')&&DOING_CRON]+$row,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
}
final class CronLabStopGate implements RecoveryStep,RecoveryLock {
    public function __construct(private RecoveryPipeline $inner) {}
    public function acquire():bool{return $this->inner->acquire();}
    public function release():void{$this->inner->release();}
    public function execute(RecoverySet $set,string $phase,array $checkpoint,int $deadline):RecoveryProgress {
        $result=$this->inner->execute($set,$phase,$checkpoint,$deadline);
        if ($phase==='database' && $result->evidence!==null && is_file('/private/stop-after-db')) {
            file_put_contents('/private/gate-reached.json',json_encode(['set_id'=>$set->id,'deadline'=>$deadline,'evidence'=>$result->evidence],JSON_THROW_ON_ERROR));
            cronLabRecord(['event'=>'database_saved_gate','set_id'=>$set->id]);
            while (is_file('/private/stop-after-db')) { usleep(100000); }
        }
        return $result;
    }
}
add_filter('cron_schedules',static fn(array $s):array=>$s+['fixture_fast'=>['interval'=>2,'display'=>'Lab only']]);
// Permit only WordPress's own loopback Cron request; the fixture guard blocks all other HTTP/mail.
add_filter('pre_http_request',static function($pre,$args,$url) {
    $allowed=getenv('FIXTURE_HTTP_URL').'/wp-cron.php';
    return ($url===$allowed || str_starts_with($url,$allowed.'?')) ? false : $pre;
},PHP_INT_MAX,3);
add_filter('http_request_host_is_external',static fn($external,$host)=>$host==='127.0.0.1'?true:$external,10,2);
$config=json_decode(file_get_contents('/private/cron-config.json'),true,16,JSON_THROW_ON_ERROR);
WordPressRecoverySchedule::register($GLOBALS['wpdb'],$config,static function(RecoverySet $set,string $backend):array {
    $uploads=wp_get_upload_dir()['basedir'];
    $workspace=new RecoveryWorkspace('/private',$set,'/site',$uploads);
    $client=MediaS3Client::create('ap-northeast-1');
    $connection=new DatabaseConnection('127.0.0.1',(int)getenv('FIXTURE_DB_PORT'),'fixture','fixture',getenv('FIXTURE_PASSWORD'));
    $step=new RecoveryPipeline($workspace,$backend==='native'?new NativeMySqlDumper():new PhpMySqlDumper(chunkSize:20),$connection,$backend,$client,new MediaSource($uploads),'/site');
    return [new CronLabStopGate($step),new RecoveryPublisher($client,$workspace)];
},static function(array $result):void{cronLabRecord(['event'=>'result','result'=>$result]);});
