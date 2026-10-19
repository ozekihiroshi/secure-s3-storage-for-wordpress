<?php
declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Read-only POSIX inspection needs local descriptors for flock/fstat and bounded reads with inode revalidation. WP_Filesystem transports do not expose these guarantees. open() rejects unsafe owner, mode, links and changed identity; no files are created here.

namespace SecureS3StorageForWordpress\Backup\Recovery;

use SecureS3StorageForWordpress\Backup\Job\JobStore;
use RuntimeException;
use Throwable;

/** POSIX, read-only advisory plan. Never constructs RecoveryWorkspace or authorizes deletion. */
final class RecoveryCleanupPlan
{
    public static function inspect(JobStore $store, string $id, string $parent, array $excludedRoots, $heldLock = null): array
    {
        Contract::id($id);
        $out=['schema_version'=>1,'set_id'=>$id,'dry_run'=>true,'deletion_authorized'=>false,'inspection_scope'=>'top_level_only','phase'=>'unknown','inspection_complete'=>false,
            'candidate_bytes'=>0,'measured_file_bytes'=>0,'recursive_bytes'=>null,'protection_reasons'=>[], 'entries'=>[]];
        $lock=null;
        try {
            $before=$store->read();
            $record=(new RecoveryCollector(new RecoveryRecordSnapshot($before)))->current();
            if($record===null){$out['protection_reasons'][]='set_not_found';return $out;}
            Contract::require($record['intent']['id']===$id);
            $out['phase']=$record['phase'];
            Contract::require(DIRECTORY_SEPARATOR==='/' && realpath($parent)===$parent && self::directory(@lstat($parent)));
            Contract::require(count($excludedRoots)>=2);
            foreach($excludedRoots as $root){
                $real=realpath($root);Contract::require($real!==false);
                Contract::require($parent!==$real && !str_starts_with($parent,rtrim($real,'/').'/'));
            }
            $path=$parent.'/recovery-'.$id;
            $dir=self::stat($path);Contract::require(self::directory($dir) && realpath($path)===$path);
            $lock=$heldLock??self::open($path.'/worker.lock');
            Contract::require(is_resource($lock) && self::identity(fstat($lock))===self::stat($path.'/worker.lock') && self::file(self::stat($path.'/worker.lock')));
            if($heldLock===null && !flock($lock,LOCK_SH|LOCK_NB)){$out['protection_reasons'][]='workspace_busy';return $out;}
            $intent=self::open($path.'/intent.json');
            try{$intentIdentity=self::identity(fstat($intent));$bytes=stream_get_contents($intent,8193);Contract::require(strlen($bytes)<=8192 && feof($intent));}
            finally{fclose($intent);}
            Contract::require(json_decode($bytes,true,16,JSON_THROW_ON_ERROR)===$record['intent']);
            if($record['phase']!=='complete')$out['protection_reasons'][]=$record['phase']==='failed'?'failed_requires_review':'incomplete_or_resumable';
            if($record['until']>0)$out['protection_reasons'][]='lease_present';
            $names=self::names($path);$stats=[];
            foreach($names as $name){
                $s=self::stat($path.'/'.$name);$stats[$name]=$s;
                $reason='unknown_entry';$candidate=false;$size=null;
                if(self::file($s)){
                    $size=$s['size'];$out['measured_file_bytes']+=$size;
                    if(in_array($name,['database.sql','database.sql.gz'],true)){$reason='completed_database_temporary';$candidate=true;}
                    elseif(in_array($name,['worker.lock','intent.json','database.json','media.json','child.json','transfer.json'],true))$reason='retain_coordination_or_evidence';
                }elseif(self::directory($s) && preg_match('/^odbfs3-preparation-[a-f0-9]{32}$/D',$name)===1){
                    $reason='media_subtree_not_inspected';
                }else{$reason='unsafe_type_owner_mode_or_links';}
                if(in_array($reason,['unknown_entry','unsafe_type_owner_mode_or_links'],true))$out['protection_reasons'][]=$reason;
                $out['entries'][]=['name'=>preg_match('//u',$name)===1?$name:'hex:'.bin2hex($name),'bytes'=>$size,'disposition'=>$candidate?'candidate':'protected','reason_code'=>$reason];
            }
            // Advisory snapshot only; cooperative writer lock + metadata/state reread detect ordinary races.
            Contract::require(self::stat($path)===$dir && $store->read()===$before);
            Contract::require(self::stat($path.'/worker.lock')===self::identity(fstat($lock)) && self::stat($path.'/intent.json')===$intentIdentity);
            foreach($stats as $name=>$s)Contract::require(self::stat($path.'/'.$name)===$s);
            Contract::require(self::names($path)===$names);
            $out['inspection_complete']=true;
        }catch(Throwable $e){$out['protection_reasons'][]='inspection_unavailable_or_changed';}
        finally{if($heldLock===null && is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
        $out['protection_reasons']=array_values(array_unique($out['protection_reasons']));
        foreach($out['entries'] as &$entry){
            if($entry['disposition']==='candidate'){
                if($out['protection_reasons']!==[]){$entry['disposition']='protected';$entry['reason_code']='workspace_protected';}
                else{$out['candidate_bytes']+=$entry['bytes'];}
            }
        }
        return $out;
    }

    private static function names(string $path): array {
        $names=[];$h=@opendir($path);Contract::require(is_resource($h));
        try{while(false!==($n=readdir($h))){
            if($n==='.'||$n==='..')continue;
            if(count($names)>=256)throw new RuntimeException('Inspection limit.');
            $names[]=$n;
        }}finally{closedir($h);}
        sort($names,SORT_STRING);return $names;
    }

    private static function identity(array|false $s): array|false {
        return $s===false?false:array_intersect_key($s,array_flip(['dev','ino','mode','uid','gid','nlink','size','mtime','ctime']));
    }
    private static function stat(string $path): array|false {clearstatcache(true,$path);return self::identity(@lstat($path));}
    private static function directory(array|false $s): bool {
        return $s!==false && function_exists('posix_geteuid') && ($s['mode']&0170000)===0040000 && ($s['mode']&0777)===0700 && $s['uid']===posix_geteuid();
    }
    private static function file(array|false $s): bool {
        return $s!==false && function_exists('posix_geteuid') && ($s['mode']&0170000)===0100000 && ($s['mode']&0777)===0600 && $s['uid']===posix_geteuid() && $s['nlink']===1;
    }
    private static function open(string $path) {
        $s=self::stat($path);Contract::require(self::file($s));
        $f=@fopen($path,'rb');Contract::require(is_resource($f));
        try{Contract::require(self::identity(fstat($f))===$s && self::stat($path)===$s);}catch(Throwable $e){fclose($f);throw $e;}
        return $f;
    }
}
