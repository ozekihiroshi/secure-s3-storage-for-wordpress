<?php
declare(strict_types=1);
namespace SecureS3StorageForWordpress\Backup\Recovery;

use Closure;
use RuntimeException;
use Throwable;
use SecureS3StorageForWordpress\Backup\Job\JobStore;

/** One operation per durable slot. Delivery retries never execute the operation again. */
final class ObservedRecoveryRun
{
    private array $checks=[];
    private bool $active=false;
    private bool $failed=false;
    private ?string $claimed=null;
    private Closure $clock;

    public function __construct(private JobStore $journal, private RecoveryManifest $manifest,
        private string $kind, private ?string $environmentId, private Closure $sink, ?Closure $clock=null)
    {
        Contract::require(in_array($kind,['availability','restore'],true));
        if($kind==='restore')Contract::id($environmentId);
        else Contract::require($environmentId===null);
        $this->clock=$clock??static fn():string=>gmdate('Y-m-d\TH:i:s\Z');
    }

    private function identity(): array
    {
        return ['set_id'=>$this->manifest->toArray()['set_id'],'manifest_sha256'=>hash('sha256',$this->manifest->encode()),
            'kind'=>$this->kind,'environment_id'=>$this->environmentId];
    }

    /** A successful check is added only after its actual verifier returns true. */
    public function check(string $name, Closure $verify): void
    {
        Contract::require($this->active && !$this->failed && !in_array($name,$this->checks,true));
        $allowed=$this->kind==='restore' ? ['sql_import','wordpress_content','media_hashes','attachment_references']
            : ['database_object','inventory_object','marker_object','all_media_objects'];
        Contract::require(in_array($name,$allowed,true));
        try { if($verify()!==true)throw new RuntimeException('Verification did not pass.'); }
        catch(Throwable $e){$this->failed=true;throw $e;}
        $this->checks[]=$name;
        $record=json_decode($this->claimed,true,16,JSON_THROW_ON_ERROR);
        $record['checks']=$this->checks;
        $this->replace($record);
    }

    private function replace(array $record): void
    {
        $bytes=json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(!$this->journal->compareAndSwap($this->claimed,$bytes))throw new RuntimeException('Observation journal contention.');
        $this->claimed=$bytes;
    }

    public function execute(Closure $operation): array
    {
        Contract::require(!$this->active && $this->journal->read()===null);
        $this->replace(['schema'=>1,'identity'=>$this->identity(),'phase'=>'running','checks'=>[],'observation'=>null,'operation_passed'=>null]);
        $this->active=true;$passed=false;
        try {
            $operation($this);
            Contract::require(!$this->failed);
            // Existing observation contract enforces every profile-required check.
            $this->observation(true);
            $passed=true;
        } catch(Throwable $e) {
            // No raw exception or operator-supplied success label is persisted.
        } finally {$this->active=false;}
        $observation=$this->observation($passed);
        $this->replace(['schema'=>1,'identity'=>$this->identity(),'phase'=>'delivery_pending',
            'checks'=>$this->checks,'observation'=>$observation,'operation_passed'=>$passed]);
        return $this->deliver();
    }

    private function observation(bool $passed): array
    {
        return RecoveryObservation::forManifest($this->manifest,$this->identity()+[
            'state'=>$passed ? ($this->kind==='restore'?'passed':'available') : ($this->kind==='restore'?'failed':'unknown'),
            'checked_at'=>($this->clock)(),'checks'=>$this->checks])->toArray();
    }

    /** Retry only this method after registration failure, using the same journal. */
    public function deliver(): array
    {
        $bytes=$this->journal->read();Contract::require(is_string($bytes)&&strlen($bytes)<=32768);
        $r=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);
        Contract::fields($r,['schema','identity','phase','checks','observation','operation_passed']);
        Contract::require($r['schema']===1 && $r['identity']===$this->identity()
            && in_array($r['phase'],['delivery_pending','registered'],true) && is_bool($r['operation_passed']));
        $ob=RecoveryObservation::forManifest($this->manifest,$r['observation'])->toArray();
        Contract::require($ob['checks']===$r['checks'] && $ob['kind']===$this->kind && $ob['environment_id']===$this->environmentId);
        Contract::require($ob['state']===($r['operation_passed'] ? ($this->kind==='restore'?'passed':'available') : ($this->kind==='restore'?'failed':'unknown')));
        if($r['phase']==='registered')return ['operation_passed'=>$r['operation_passed'],'registration'=>'registered'];
        try {
            $digest=($this->sink)($ob);
            Contract::hash($digest);
        } catch(Throwable $e) {
            return ['operation_passed'=>$r['operation_passed'],'registration'=>'pending'];
        }
        $this->claimed=$bytes;$r['phase']='registered';$this->replace($r);
        return ['operation_passed'=>$r['operation_passed'],'registration'=>'registered'];
    }
}
