<?php
declare(strict_types=1);

use SecureS3StorageForWordpress\Backup\Job\JobStore;
use SecureS3StorageForWordpress\Backup\Recovery\{RecoverySet, RecoveryManifest, RecoveryStatus};
use SecureS3StorageForWordpress\Cli\RecoverySetCommand;

require __DIR__ . '/test-recovery-contract.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'SecureS3StorageForWordpress\\';
    if (str_starts_with($class, $prefix)) {
        require_once __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
final class StatusReadStore implements JobStore {
    public int $reads = 0;
    public function __construct(public ?string $bytes) {}
    public function read(): ?string { ++$this->reads; return $this->bytes; }
    public function compareAndSwap(?string $expected, string $replacement): bool { throw new RuntimeException('Unexpected write'); }
}
// Isolated WP-CLI/wpdb doubles: exact query inspection, no WordPress or network.
class wpdb {
    public string $options = 'wp_options';
    public string $last_error = '';
    public ?string $bytes = null;
    public array $queries = [];
    public bool $suppressed = false;
    public function suppress_errors($value) { $old=$this->suppressed; $this->suppressed=$value; return $old; }
    public function prepare($query, ...$args) { return [$query, $args]; }
    public function get_var($query) {
        if (!str_starts_with($query[0], 'SELECT option_value FROM wp_options WHERE option_name = %s')) { throw new RuntimeException('Unexpected query'); }
        $this->queries[] = $query; return $this->bytes;
    }
    public function query($query) { throw new RuntimeException('Unexpected write'); }
}
class StatusHalt extends RuntimeException {}
class WP_CLI {
    public static array $lines = [];
    public static array $commands = [];
    public static function line($line) { self::$lines[] = $line; }
    public static function halt($code) { throw new StatusHalt('', $code); }
    public static function add_command($name, $class) { self::$commands[$name] = $class; }
}
$initial = $checks;
$baseRecord = ['schema'=>1, 'intent'=>$set->toIntent(), 'environment'=>$fixture['environment'],
    'phase'=>'database', 'checkpoint'=>[], 'database'=>null, 'media'=>null, 'manifest'=>null,
    'lease'=>'', 'until'=>0, 'attempts'=>0, 'error'=>''];
foreach (['database','database_uploads'] as $profile) {
    $intent = $set->toIntent(); $intent['profile'] = $profile;
    $currentSet = RecoverySet::fromIntent($intent);
    foreach (['database','media','assemble','ready','publishing','complete','failed'] as $phase) {
        if ($profile === 'database' && $phase === 'media') { continue; }
        $r=$baseRecord; $r['intent']=$intent; $r['phase']=$phase;
        if ($phase !== 'database') { $r['database']=$fixture['database']; }
        if ($profile === 'database_uploads' && !in_array($phase,['database','media'])) { $r['media']=$fixture['media']; }
        if (in_array($phase,['ready','publishing','complete'])) {
            $r['manifest']=RecoveryManifest::assemble($currentSet,$r['environment'],$r['database'],$r['media'])->encode();
        }
        if ($phase === 'complete') {
            $r['checkpoint']=['bucket'=>$set->bucket,'key'=>$set->root().'manifest.json',
                'size_bytes'=>strlen($r['manifest']),'sha256'=>hash('sha256',$r['manifest']),
                'version_id'=>null,'verified_at'=>$end,'method'=>'head_sha256_get_exact_bytes'];
        }
        if ($phase === 'failed') { $r['error']='collection_failed'; }
        $store=new StatusReadStore(json_encode($r)); $before=$store->bytes;
        $v=RecoveryStatus::read($store,$set->id);
        $check($store->reads===1 && $store->bytes===$before, 'single nonmutating read');
        $check($v['phase']===$phase, 'phase preserved');
        $check($v['completeness']===($phase==='complete'?'complete':($phase==='database'?'none':'partial')), 'completeness distinct from failure');
        $check($v['manifest']['publication']===($phase==='complete'?'verified':'not_verified'), 'publication proof required');
        $check($v['verification']['media']===($profile==='database'?'not_included':($r['media']===null?'not_verified':'verified')), 'profile-aware verification');
        $check($v['availability']['state']==='unknown' && $v['restore']['state']==='not_tested', 'no current availability or restore claim');
        if ($phase==='complete') { $completeRecord=$r; }
    }
}
$check(RecoveryStatus::read(new StatusReadStore(null),$set->id)===null,'missing set');
$reject(fn()=>RecoveryStatus::read(new StatusReadStore('{}'),$set->id),'malformed state');
$reject(fn()=>RecoveryStatus::read(new StatusReadStore(json_encode($baseRecord)),str_repeat('f',32)),'mismatched identity');
$forged=$completeRecord; $forged['checkpoint']['sha256']=str_repeat('0',64);
$reject(fn()=>RecoveryStatus::read(new StatusReadStore(json_encode($forged)),$set->id),'forged complete');
$GLOBALS['wpdb']=new wpdb();
$invoke=static function(array $args, array $options) use ($check): array {
    WP_CLI::$lines=[]; $code=0;
    try { (new RecoverySetCommand())->status($args,$options); } catch(StatusHalt $e) { $code=$e->getCode(); }
    $check(count(WP_CLI::$lines)===1,'one JSON document');
    return [$code,json_decode(WP_CLI::$lines[0],true,512,JSON_THROW_ON_ERROR)];
};
$GLOBALS['wpdb']->bytes=json_encode($completeRecord);
[$code,$v]=$invoke([$set->id],['format'=>'json']);
$check($code===0 && $v['phase']==='complete','CLI complete');
$check($GLOBALS['wpdb']->queries[0][1]===['odbfs3_recovery_'.$set->id],'exact site-local slot');
$failed=$baseRecord; $failed['phase']='failed'; $failed['error']='collection_failed';
$failed['checkpoint']=['password'=>'SECRET_SENTINEL','sql'=>'SECRET_SENTINEL'];
$GLOBALS['wpdb']->bytes=json_encode($failed);
[$code,$v]=$invoke([$set->id],['format'=>'json']);
$check($code===0 && $v['phase']==='failed','failed job still readable');
$check(!str_contains(json_encode($v),'SECRET_SENTINEL') && !isset($v['checkpoint'],$v['environment'],$v['lease']), 'allowlist excludes private state');
$GLOBALS['wpdb']->bytes=null;
[$code,$v]=$invoke([$set->id],['format'=>'json']); $check($code===3 && $v['reason_code']==='set_not_found','missing exit');
$GLOBALS['wpdb']->bytes='secret malformed bytes';
[$code,$v]=$invoke([$set->id],['format'=>'json']); $check($code===4 && $v['phase']==='unknown','corrupt exit');
$GLOBALS['wpdb']->last_error='SECRET_SENTINEL';
[$code,$v]=$invoke([$set->id],['format'=>'json']); $check($code===4 && !str_contains(json_encode($v),'SECRET_SENTINEL'),'DB errors redacted');
$check(!$GLOBALS['wpdb']->suppressed,'DB error suppression restored after failure');
$before=count($GLOBALS['wpdb']->queries);
foreach ([[],['bad'],[$set->id,'extra']] as $args) {
    [$code,$v]=$invoke($args,['format'=>'json']); $check($code===2,'argument exit');
}
[$code,$v]=$invoke([$set->id],['format'=>'json','cleanup'=>true]); $check($code===2,'unknown option rejected');
$check(count($GLOBALS['wpdb']->queries)===$before,'invalid requests never read DB');
define('WP_CLI',true);
(new SecureS3StorageForWordpress\Cli\CommandRegistrar())->register();
$check(WP_CLI::$commands['ozeki-database-backup-for-s3 recovery-set']===RecoverySetCommand::class,'CLI registered');
echo json_encode(['kind'=>'recovery_status_isolated_contract','passed'=>true,'checks'=>$checks-$initial,'network_requests'=>0]),"\n";
