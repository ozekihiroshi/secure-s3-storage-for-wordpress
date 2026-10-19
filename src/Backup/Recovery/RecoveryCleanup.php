<?php
declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.unlink_unlink -- Private POSIX cleanup requires the same local descriptor for flock/fstat/hash checks and direct unlink of the revalidated path. WP_Filesystem transports cannot supply these guarantees; wp_delete_file filters can redirect the path and return no unlink result. execute() constrains root, owner, mode, inode, manifest hash and deletion journal before unlink.

namespace SecureS3StorageForWordpress\Backup\Recovery;

use SecureS3StorageForWordpress\Backup\Job\JobStore;
use Throwable;

/** Explicit local DB-temporary cleanup. Journal lives outside the workspace. */
final class RecoveryCleanup
{
    private const FILES = ['database.sql','database.sql.gz'];
    // Bounds full content verification per file. Larger files require a later policy revision.
    private const MAX_BYTES = 1073741824;

    public function __construct(private JobStore $collector, private JobStore $journal) {}

    /** Local journal projection only; does not inspect files or resume cleanup. */
    public function status(string $id): array
    {
        $out=['state'=>'unknown','files'=>[],'scope'=>'local_journal_only'];
        try{
            Contract::id($id);$bytes=$this->collector->read();
            $record=(new RecoveryCollector(new RecoveryRecordSnapshot($bytes)))->current();
            Contract::require($record!==null && $record['intent']['id']===$id);
            $saved=$this->journal->read();
            if($saved===null){$out['state']='not_started';return $out;}
            Contract::require($record['phase']==='complete' && strlen($saved)<=16384);
            $raw=json_decode($saved,true,16,JSON_THROW_ON_ERROR);$binding=$raw['binding'];
            Contract::fields($binding,['set_id','collector_sha256','parent','directory','manifest_sha256']);
            Contract::require(is_string($binding['parent']) && str_starts_with($binding['parent'],'/'));
            Contract::fields($binding['directory'],['dev','ino','mode','uid','gid']);
            foreach($binding['directory'] as $v)Contract::require(is_int($v));
            Contract::require(($binding['directory']['mode']&0177777)===0040700 && $binding['directory']['ino']>0
                && $binding['directory']['dev']>=0 && $binding['directory']['uid']>=0 && $binding['directory']['gid']>=0);
            $expected=$binding;$expected['set_id']=$id;$expected['collector_sha256']=hash('sha256',$bytes);
            $expected['manifest_sha256']=hash('sha256',$record['manifest']);
            $r=$this->decode($saved,$expected,$record);
            $pending=false;$uncertain=false;
            foreach($r['files'] as $name=>$entry){$out['files'][$name]=$entry['state'];
                $pending=$pending||$entry['state']==='prepared';$uncertain=$uncertain||$entry['state']==='deleting';}
            $out['state']=$uncertain?'needs_reconciliation':($pending?'pending':'complete');
        }catch(Throwable $e){$out['files']=[];}
        return $out;
    }

    public function execute(string $id, string $parent, array $excludedRoots): array
    {
        $lock=null;$out=['schema_version'=>1,'set_id'=>$id,'state'=>'blocked','reason_code'=>'revalidation_failed','files'=>[]];
        try {
            Contract::id($id);
            $preflight=RecoveryCleanupPlan::inspect($this->collector,$id,$parent,$excludedRoots);
            if(!$preflight['inspection_complete'] || $preflight['protection_reasons']!==[] || $preflight['phase']!=='complete'){
                $out['protection_reasons']=$preflight['protection_reasons'];return $out;
            }
            $path=$parent.'/recovery-'.$id;
            $lock=$this->open($path.'/worker.lock');
            if(!flock($lock,LOCK_EX|LOCK_NB)){$out['reason_code']='workspace_busy';return $out;}
            $this->checkPlan($id,$parent,$excludedRoots,$lock);
            $recordBytes=$this->collector->read();
            $record=(new RecoveryCollector(new RecoveryRecordSnapshot($recordBytes)))->current();
            Contract::require($record!==null && $record['phase']==='complete');
            $binding=['set_id'=>$id,'collector_sha256'=>hash('sha256',$recordBytes),'parent'=>$parent,
                'directory'=>$this->directoryIdentity($path),'manifest_sha256'=>hash('sha256',$record['manifest'])];
            $saved=$this->journal->read();
            if($saved===null){
                $r=['schema'=>1,'binding'=>$binding,'files'=>[]];
                foreach(self::FILES as $name){
                    $s=$this->stat($path.'/'.$name);
                    $expected=$name==='database.sql'?$record['database']['raw_sql']:$record['database']['artifact'];
                    $r['files'][$name]=['state'=>$s===false?'absent_before':'prepared','identity'=>$s===false?null:$this->verify($path.'/'.$name,$expected),
                        'sha256'=>$expected['sha256'],'size_bytes'=>$expected['size_bytes']];
                }
                $next=$this->encode($r);
                Contract::require($this->journal->compareAndSwap(null,$next));$saved=$next;
            }
            $r=$this->decode($saved,$binding,$record);
            foreach(self::FILES as $name){
                $this->checkPlan($id,$parent,$excludedRoots,$lock);
                Contract::require($this->collector->read()===$recordBytes && $this->directoryIdentity($path)===$binding['directory']);
                $entry=$r['files'][$name];$file=$path.'/'.$name;$present=$this->stat($file)!==false;
                if(in_array($entry['state'],['deleted','absent_after_intent','absent_before'],true)){
                    Contract::require(!$present);continue; // A recreated file is never deleted by replay.
                }
                if(!$present){
                    Contract::require($entry['state']==='deleting');
                    $r['files'][$name]['state']='absent_after_intent';
                    $this->save($saved,$r);continue;
                }
                Contract::require($this->verify($file,$entry)===$entry['identity']);
                if($entry['state']==='prepared'){
                    $r['files'][$name]['state']='deleting';$this->save($saved,$r);
                }
                // Recheck immediately before unlink; never consume a historical dry-run plan.
                $this->checkPlan($id,$parent,$excludedRoots,$lock);
                Contract::require($this->collector->read()===$recordBytes && $this->journal->read()===$saved
                    && $this->directoryIdentity($path)===$binding['directory'] && $this->verify($file,$entry)===$entry['identity']);
                Contract::require($this->stat($file)===$entry['identity']);
                Contract::require(@unlink($file));
                Contract::require($this->stat($file)===false);
                $r['files'][$name]['state']='deleted';$this->save($saved,$r);
            }
            $out['state']='complete';$out['reason_code']='local_temporary_cleanup_complete';
            foreach($r['files'] as $name=>$entry)$out['files'][$name]=$entry['state'];
        }catch(Throwable $e){
            // Unlink or CAS may have succeeded. Keep the journal; same-ID invocation reconciles it.
            $out['state']='incomplete';$out['reason_code']='review_or_resume_required';
            if(isset($binding,$record)){
                try{$latest=$this->journal->read();if($latest!==null){$durable=$this->decode($latest,$binding,$record);
                    foreach($durable['files'] as $name=>$entry)$out['files'][$name]=$entry['state'];}}
                catch(Throwable $ignored){} // Do not substitute an in-memory state for an unreadable journal.
            }
        }finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
        return $out;
    }

    private function checkPlan(string $id,string $parent,array $roots,$lock):void {
        $plan=RecoveryCleanupPlan::inspect($this->collector,$id,$parent,$roots,$lock);
        Contract::require($plan['inspection_complete'] && $plan['protection_reasons']===[] && $plan['phase']==='complete');
    }
    private function save(?string &$saved,array $r):void {
        $next=$this->encode($r);Contract::require($this->journal->compareAndSwap($saved,$next));$saved=$next;
    }
    private function encode(array $r):string{return json_encode($r,JSON_THROW_ON_ERROR,16);}
    private function decode(string $bytes,array $binding,array $record):array {
        Contract::require(strlen($bytes)<=16384);$r=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);
        Contract::fields($r,['schema','binding','files']);Contract::require($r['schema']===1 && $r['binding']===$binding);
        Contract::fields($r['files'],self::FILES);
        foreach(self::FILES as $name){
            $e=$r['files'][$name];Contract::fields($e,['state','identity','sha256','size_bytes']);
            Contract::require(in_array($e['state'],['prepared','deleting','deleted','absent_after_intent','absent_before'],true));
            $expected=$name==='database.sql'?$record['database']['raw_sql']:$record['database']['artifact'];
            Contract::require($e['sha256']===$expected['sha256'] && $e['size_bytes']===$expected['size_bytes']);
            if($e['state']==='absent_before'){Contract::require($e['identity']===null);}
            else {Contract::require(is_array($e['identity']));Contract::fields($e['identity'],['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']);
                foreach($e['identity'] as $v)Contract::require(is_int($v));
                $i=$e['identity'];Contract::require(($i['mode']&0177777)===0100600 && $i['nlink']===1 && $i['ino']>0
                    && $i['dev']>=0 && $i['uid']>=0 && $i['gid']>=0 && $i['size']===$expected['size_bytes'] && $i['mtime']>=0 && $i['ctime']>=0);}
        }return $r;
    }
    private function stat(string $path):array|false {
        clearstatcache(true,$path);$s=@lstat($path);
        return $s===false?false:array_intersect_key($s,array_flip(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']));
    }
    private function directoryIdentity(string $path):array {
        Contract::require(realpath($path)===$path);$s=$this->stat($path);
        Contract::require($s!==false && ($s['mode']&0170000)===0040000 && ($s['mode']&0777)===0700 && $s['uid']===posix_geteuid());
        return array_intersect_key($s,array_flip(['dev','ino','mode','uid','gid']));
    }
    private function open(string $path) {
        $s=$this->stat($path);Contract::require($s!==false && ($s['mode']&0170000)===0100000 && ($s['mode']&0777)===0600 && $s['nlink']===1 && $s['uid']===posix_geteuid());
        $f=@fopen($path,'rb');Contract::require(is_resource($f));
        try{$actual=array_intersect_key(fstat($f),$s);Contract::require($actual===$s && $this->stat($path)===$s);}
        catch(Throwable $e){fclose($f);throw $e;}return $f;
    }
    private function verify(string $path,array $expected):array {
        Contract::require($expected['size_bytes']<=self::MAX_BYTES);
        $f=$this->open($path);
        try{
            $before=$this->stat($path);Contract::require($before['size']===$expected['size_bytes']);
            $hash=hash_init('sha256');$read=hash_update_stream($hash,$f,self::MAX_BYTES+1);
            Contract::require($read===$expected['size_bytes'] && hash_final($hash)===$expected['sha256'] && $this->stat($path)===$before);
            return $before;
        }finally{fclose($f);}
    }
}
