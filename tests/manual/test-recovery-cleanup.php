<?php
declare(strict_types=1);
require __DIR__.'/test-recovery-status.php';
require __DIR__.'/cleanup-unlink-fault.php';
use SecureS3StorageForWordpress\Backup\Recovery\{RecoveryCleanup,RecoveryWorkspace,RecoverySet,RecoveryManifest,RecoveryCleanupPlan};
use SecureS3StorageForWordpress\Backup\Job\JobStore;
final class CleanupStore implements JobStore {
    public ?Closure $before=null;public ?Closure $after=null;
    public function __construct(public ?string $bytes=null){}
    public function read():?string{return $this->bytes;}
    public function compareAndSwap(?string $expected,string $replacement):bool {
        if($this->bytes!==$expected)return false;
        if($this->before)($this->before)($replacement);
        $this->bytes=$replacement;if($this->after)($this->after)($replacement);return true;
    }
}
$initial=$checks;umask(0077);$sql='generated database temporary';$gzip=gzencode($sql);
$r=$completeRecord;$r['database']['raw_sql']=['size_bytes'=>strlen($sql),'sha256'=>hash('sha256',$sql)];
$r['database']['artifact']['size_bytes']=strlen($gzip);$r['database']['artifact']['sha256']=hash('sha256',$gzip);
$r['manifest']=RecoveryManifest::assemble(RecoverySet::fromIntent($r['intent']),$r['environment'],$r['database'],$r['media'])->encode();
$r['checkpoint']['size_bytes']=strlen($r['manifest']);$r['checkpoint']['sha256']=hash('sha256',$r['manifest']);
$root=sys_get_temp_dir().'/odb-execute-'.bin2hex(random_bytes(8));mkdir($root,0700);
foreach(['web','uploads']as $n)mkdir($root.'/'.$n,0700);
$parents=[];
$fixture=static function()use(&$parents,$root,$set,$sql,$gzip,$r):array {
    $parent=$root.'/p'.count($parents);mkdir($parent,0700);$parents[]=$parent;
    $w=new RecoveryWorkspace($parent,$set,$root.'/web',$root.'/uploads');$w->acquire();$w->release();
    file_put_contents($w->directory.'/database.sql',$sql);file_put_contents($w->directory.'/database.sql.gz',$gzip);
    file_put_contents($w->directory.'/database.json','retained evidence');
    return [$w,new CleanupStore(json_encode($r)),new CleanupStore(),$parent];
};
$run=static fn($store,$journal,$parent)=>(new RecoveryCleanup($store,$journal))->execute($set->id,$parent,[$root.'/web',$root.'/uploads']);
try {
    [$w,$s,$j,$p]=$fixture();$original=$s->bytes;$intent=file_get_contents($w->directory.'/intent.json');
    $out=$run($s,$j,$p);
    $check($out['state']==='complete' && $out['files']===['database.sql'=>'deleted','database.sql.gz'=>'deleted'],'explicit two-file cleanup');
    $check(!file_exists($w->directory.'/database.sql')&&!file_exists($w->directory.'/database.sql.gz'),'only temporary files removed');
    $check($s->bytes===$original && file_get_contents($w->directory.'/intent.json')===$intent && file_get_contents($w->directory.'/database.json')==='retained evidence' && file_exists($w->directory.'/worker.lock'),'completion and coordination preserved');
    $saved=$j->bytes;$check($run($s,$j,$p)['state']==='complete' && $j->bytes===$saved,'completed replay no journal writes');
    file_put_contents($w->directory.'/database.sql',$sql);$check($run($s,$j,$p)['state']==='incomplete' && file_exists($w->directory.'/database.sql'),'recreated file never deleted');
    [$w,$s,$j,$p]=$fixture();file_put_contents($w->directory.'/database.sql.gz','tamper');
    $check($run($s,$j,$p)['state']==='incomplete' && file_exists($w->directory.'/database.sql') && $j->bytes===null,'verify both before deleting either');
    [$w,$s,$j,$p]=$fixture();$w->acquire();$check($run($s,$j,$p)['state']!=='complete' && $j->bytes===null,'active lock blocks journal and removal');$w->release();
    [$w,$s,$j,$p]=$fixture();$bad=json_decode($s->bytes,true);$bad['phase']='failed';$bad['error']='publication_failed';$bad['checkpoint']=[];$s->bytes=json_encode($bad);
    $check($run($s,$j,$p)['state']!=='complete' && $j->bytes===null,'failed is not removable');
    [$w,$s,$j,$p]=$fixture();file_put_contents($w->directory.'/unknown','');$check($run($s,$j,$p)['state']!=='complete' && $j->bytes===null,'unknown entry blocks');
    [$w,$s,$j,$p]=$fixture();unlink($w->directory.'/database.sql');symlink($root.'/web',$w->directory.'/database.sql');
    $check($run($s,$j,$p)['state']!=='complete' && is_link($w->directory.'/database.sql'),'symlink retained');
    [$w,$s,$j,$p]=$fixture();link($w->directory.'/database.sql',$p.'/outside');
    $check($run($s,$j,$p)['state']!=='complete' && file_exists($p.'/outside'),'hardlink retained');unlink($p.'/outside');
    // Journal failure after first unlink but before acknowledgement: pending intent reconciles absence.
    [$w,$s,$j,$p]=$fixture();$j->before=static function($bytes){$x=json_decode($bytes,true);if($x['files']['database.sql']['state']==='deleted')throw new RuntimeException('SECRET journal unavailable');};
    $out=$run($s,$j,$p);$check($out['state']==='incomplete' && $out['files']['database.sql']==='deleting' && !file_exists($w->directory.'/database.sql') && file_exists($w->directory.'/database.sql.gz'),'partial unlink keeps durable pending intent');
    $j->before=null;$out=$run($s,$j,$p);$check($out['state']==='complete' && $out['files']['database.sql']==='absent_after_intent','resume remaining without asserting who removed missing file');
    [$w,$s,$j,$p]=$fixture();$GLOBALS['cleanup_unlink_fault']='database.sql.gz';$out=$run($s,$j,$p);
    $check($out['state']==='incomplete' && $out['files']===['database.sql'=>'deleted','database.sql.gz'=>'deleting'] && file_exists($w->directory.'/database.sql.gz'),'second unlink failure preserves partial journal');
    $GLOBALS['cleanup_unlink_fault']=null;$check($run($s,$j,$p)['state']==='complete','retry after unlink failure handles remaining file');
    // Lost commit ACK after reservation: replay exactly the saved two-file plan.
    [$w,$s,$j,$p]=$fixture();$j->after=static function(){throw new RuntimeException('SECRET lost ACK');};
    $check($run($s,$j,$p)['state']==='incomplete' && file_exists($w->directory.'/database.sql'),'lost initial journal ACK deletes nothing');
    $j->after=null;$check($run($s,$j,$p)['state']==='complete','reserved plan resumes');
    // Settings/state mutation immediately after delete-intent must be noticed before unlink.
    [$w,$s,$j,$p]=$fixture();$original=$s->bytes;
    $j->after=static function($bytes)use($s){if(json_decode($bytes,true)['files']['database.sql']['state']==='deleting')$s->bytes='{';};
    $check($run($s,$j,$p)['state']==='incomplete' && file_exists($w->directory.'/database.sql'),'collector changed before unlink blocks');
    $s->bytes=$original;$j->after=null;$check($run($s,$j,$p)['state']==='complete','unchanged binding can resume');
    [$w,$s,$j,$p]=$fixture();$j->after=static function($bytes)use($w){if(json_decode($bytes,true)['files']['database.sql']['state']==='deleting')file_put_contents($w->directory.'/database.sql','replacement');};
    $check($run($s,$j,$p)['state']==='incomplete' && file_exists($w->directory.'/database.sql'),'file changed after intent is protected');
    [$w,$s,$j,$p]=$fixture();$j->after=static function($bytes)use($w,$sql){if(json_decode($bytes,true)['files']['database.sql']['state']==='deleting')file_put_contents($w->directory.'/database.sql',str_repeat('x',strlen($sql)));};
    $check($run($s,$j,$p)['state']==='incomplete' && file_exists($w->directory.'/database.sql'),'same-size content changed immediately before unlink protected');
    [$w,$s,$j,$p]=$fixture();$j->bytes='{}';$check($run($s,$j,$p)['state']==='incomplete' && file_exists($w->directory.'/database.sql'),'corrupt journal preserved');
    [$w,$s,$j,$p]=$fixture();unlink($w->directory.'/database.sql');$out=$run($s,$j,$p);
    $check($out['state']==='complete' && $out['files']['database.sql']==='absent_before','already absent not falsely counted as deleted');
    // A candidate disappearing without durable delete intent cannot be classified as our deletion.
    [$w,$s,$j,$p]=$fixture();$j->after=static function()use($w){unlink($w->directory.'/database.sql');throw new RuntimeException('stop');};
    $run($s,$j,$p);$j->after=null;$check($run($s,$j,$p)['state']==='incomplete' && file_exists($w->directory.'/database.sql.gz'),'missing prepared file requires review');
    $check(!str_contains($j->bytes,'SECRET'),'journal excludes raw errors');
    $before=count($GLOBALS['wpdb']->queries);$code=0;
    try{(new \SecureS3StorageForWordpress\Cli\RecoverySetCommand())->cleanup([$set->id],['workspace-parent'=>$p,'format'=>'json']);}catch(StatusHalt $e){$code=$e->getCode();}
    $check($code===2 && count($GLOBALS['wpdb']->queries)===$before,'missing exact confirmation rejected before DB access');
    echo 'PASS cleanup execution checks='.($checks-$initial)."\n";
}finally{
    foreach($parents as $p){$d=$p.'/recovery-'.$set->id;foreach(scandir($d)as $n){if($n==='.'||$n==='..')continue;unlink($d.'/'.$n);}rmdir($d);rmdir($p);}
    rmdir($root.'/web');rmdir($root.'/uploads');rmdir($root);
}
