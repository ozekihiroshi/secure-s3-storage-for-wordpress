<?php
declare(strict_types=1);
require __DIR__.'/test-recovery-status.php';
use SecureS3StorageForWordpress\Backup\Recovery\{RecoveryCleanupPlan,RecoveryWorkspace,RecoveryRecordSnapshot};
$initial=$checks;umask(0077);
$root=sys_get_temp_dir().'/odb-cleanup-'.bin2hex(random_bytes(8));mkdir($root,0700);
foreach(['private','web','uploads']as $name)mkdir($root.'/'.$name,0700);
$workspace=new RecoveryWorkspace($root.'/private',$set,$root.'/web',$root.'/uploads');
$workspace->acquire();$workspace->release();$dir=$workspace->directory;
file_put_contents($dir.'/database.sql','synthetic sql');file_put_contents($dir.'/database.sql.gz','synthetic gzip');
file_put_contents($dir.'/database.json','{}');
$store=new RecoveryRecordSnapshot(json_encode($completeRecord));
$plan=static fn($s=null,$p=null)=>RecoveryCleanupPlan::inspect($s??$store,$set->id,$p??$root.'/private',[$root.'/web',$root.'/uploads']);
$snapshot=static function()use($dir):array{
    $out=[];foreach(scandir($dir)as $n){if($n==='.'||$n==='..')continue;
        clearstatcache(true,$dir.'/'.$n);$s=lstat($dir.'/'.$n);
        $out[$n]=['hash'=>is_file($dir.'/'.$n)&&!is_link($dir.'/'.$n)?hash_file('sha256',$dir.'/'.$n):null,'size'=>$s['size'],'mode'=>$s['mode'],'mtime'=>$s['mtime'],'ino'=>$s['ino']];
    }return $out;
};
try{
    $before=$snapshot();$r=$plan();
    $check($r['inspection_complete'] && $r['candidate_bytes']===strlen('synthetic sqlsynthetic gzip'),'completed DB temporary candidates');
    $check(count(array_filter($r['entries'],fn($e)=>$e['disposition']==='candidate'))===2,'only two temporary files');
    $check($r['recursive_bytes']===null && $r['dry_run'],'no inferred recursive total');
    $check($before===$snapshot(),'files names content mode inode mtime unchanged');
    $check($store->read()===json_encode($completeRecord),'collector unchanged');
    $workspace->acquire();$r=$plan();$check($r['protection_reasons']===['workspace_busy'] && $r['candidate_bytes']===0,'active workspace protected');$workspace->release();
    $failed=$completeRecord;$failed['phase']='failed';$failed['error']='publication_failed';$failed['checkpoint']=[];
    $r=$plan(new RecoveryRecordSnapshot(json_encode($failed)));$check(in_array('failed_requires_review',$r['protection_reasons']) && $r['candidate_bytes']===0,'failed not cleanup permission');
    $active=$completeRecord;$active['phase']='database';$active['database']=null;$active['media']=null;$active['manifest']=null;$active['checkpoint']=[];
    $r=$plan(new RecoveryRecordSnapshot(json_encode($active)));$check(in_array('incomplete_or_resumable',$r['protection_reasons']) && $r['candidate_bytes']===0,'incomplete protected');
    file_put_contents($dir.'/unexpected','private');$r=$plan();$check(in_array('unknown_entry',$r['protection_reasons']) && $r['candidate_bytes']===0,'unknown blocks all');unlink($dir.'/unexpected');
    file_put_contents($dir.'/'.chr(255),'');$r=$plan();
    $check(is_string(json_encode($r,JSON_THROW_ON_ERROR)) && $r['candidate_bytes']===0,'invalid UTF-8 filename remains safe JSON');unlink($dir.'/'.chr(255));
    rename($dir.'/database.sql',$dir.'/saved');symlink($root.'/web',$dir.'/database.sql');
    $r=$plan();$check(in_array('unsafe_type_owner_mode_or_links',$r['protection_reasons']) && $r['candidate_bytes']===0,'symlink never followed');unlink($dir.'/database.sql');rename($dir.'/saved',$dir.'/database.sql');
    link($dir.'/database.sql',$root.'/linked');$r=$plan();$check($r['candidate_bytes']===0,'hardlink protected');unlink($root.'/linked');
    chmod($dir.'/database.sql',0644);$check($plan()['candidate_bytes']===0,'unsafe file mode');chmod($dir.'/database.sql',0600);
    chmod($dir,0755);$check(!$plan()['inspection_complete'],'unsafe directory mode');chmod($dir,0700);
    $intent=file_get_contents($dir.'/intent.json');file_put_contents($dir.'/intent.json','{}');$check(!$plan()['inspection_complete'],'intent mismatch');file_put_contents($dir.'/intent.json',$intent);
    $check(!$plan(new RecoveryRecordSnapshot('{'))['inspection_complete'],'corrupt collector');
    rename($dir.'/worker.lock',$dir.'/saved-lock');$r=$plan();
    $check(!$r['inspection_complete'] && !file_exists($dir.'/worker.lock'),'missing lock never created');rename($dir.'/saved-lock',$dir.'/worker.lock');
    file_put_contents($dir.'/intent.json',str_repeat('x',8193));$check(!$plan()['inspection_complete'],'bounded intent read');file_put_contents($dir.'/intent.json',$intent);
    $check(!$plan(null,$root.'/web')['inspection_complete'],'public workspace parent rejected');
    $check(!$plan()['deletion_authorized'],'plan never authorizes deletion');
    $check($plan(new RecoveryRecordSnapshot(null))['protection_reasons']===['set_not_found'],'missing set');
    mkdir($root.'/empty',0700);$check(!$plan(null,$root.'/empty')['inspection_complete'] && scandir($root.'/empty')===['.','..'],'missing workspace never created');
    symlink($root.'/private',$root.'/alias');$check(!$plan(null,$root.'/alias')['inspection_complete'],'symlink parent rejected');unlink($root.'/alias');
    mkdir($dir.'/odbfs3-preparation-'.str_repeat('b',32),0700);$r=$plan();
    $check($r['inspection_complete'] && in_array('media_subtree_not_inspected',array_column($r['entries'],'reason_code')),'media subtree retained without traversal');
    rmdir($dir.'/odbfs3-preparation-'.str_repeat('b',32));
    for($i=0;$i<257;$i++)file_put_contents($dir.'/extra-'.$i,'');
    $check(!$plan()['inspection_complete'] && $plan()['candidate_bytes']===0,'bounded directory scan');
    for($i=0;$i<257;$i++)unlink($dir.'/extra-'.$i);
    $changing=new class($store->read()) implements \SecureS3StorageForWordpress\Backup\Job\JobStore {
        private int $reads=0;public function __construct(private string $bytes){}
        public function read():?string{return ++$this->reads===1?$this->bytes:null;}
        public function compareAndSwap(?string $e,string $r):bool{throw new RuntimeException('Unexpected write');}
    };
    $check(!$plan($changing)['inspection_complete'] && $plan($changing)['candidate_bytes']===0,'state change invalidates plan');
    // Exercise command JSON, exits and WordPress's non-creating uploads lookup.
    define('ABSPATH',$root.'/web');$GLOBALS['cleanup_uploads']=$root.'/uploads';
    function wp_upload_dir($time=null,$create=true,$refresh=true){if($create)throw new RuntimeException('Creation requested');return ['basedir'=>$GLOBALS['cleanup_uploads'],'error'=>false];}
    $GLOBALS['wpdb']->bytes=$store->read();$GLOBALS['wpdb']->last_error='';WP_CLI::$lines=[];
    (new \SecureS3StorageForWordpress\Cli\RecoverySetCommand())->cleanup_plan([$set->id],['workspace-parent'=>$root.'/private','format'=>'json']);
    $cli=json_decode(WP_CLI::$lines[0],true,32,JSON_THROW_ON_ERROR);
    $check(count(WP_CLI::$lines)===1 && $cli['candidate_bytes']>0,'CLI single JSON document');
    $before=$snapshot();WP_CLI::$lines=[];
    (new \SecureS3StorageForWordpress\Cli\RecoverySetCommand())->cleanup_plan([$set->id],['workspace-parent'=>$root.'/private','format'=>'json']);
    $check($snapshot()===$before && $GLOBALS['wpdb']->bytes===$store->read(),'CLI no file or state writes');
    $code=0;try{(new \SecureS3StorageForWordpress\Cli\RecoverySetCommand())->cleanup_plan([$set->id],['workspace-parent'=>$root.'/private','delete'=>true,'format'=>'json']);}catch(StatusHalt $e){$code=$e->getCode();}
    $check($code===2,'no destructive option');
    echo 'PASS cleanup plan checks='.($checks-$initial)."\n";
}finally{
    $workspace->release();
    // Test-created entries only; never follow directory links.
    foreach(scandir($dir)as $n){if($n==='.'||$n==='..')continue;$p=$dir.'/'.$n;if(is_dir($p)&&!is_link($p))rmdir($p);else unlink($p);}
    rmdir($dir);foreach(['private','web','uploads','empty']as $n)if(is_dir($root.'/'.$n))rmdir($root.'/'.$n);rmdir($root);
}
