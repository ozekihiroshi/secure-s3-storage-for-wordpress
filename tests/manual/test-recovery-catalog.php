<?php
declare(strict_types=1);
require __DIR__.'/test-recovery-status.php';
use SecureS3StorageForWordpress\Backup\Recovery\{RecoveryIndex,RecoveryObservationSummary,RecoveryManifest};
use SecureS3StorageForWordpress\WordPress\WordPressRecoveryCatalog;

$initial=$checks;
$row=static fn(array $r):array=>['option_name'=>'odbfs3_recovery_'.$r['intent']['id'],'option_value'=>json_encode($r)];
$old=$completeRecord;
$new=$baseRecord; $new['intent']['id']=str_repeat('d',32); $new['intent']['createdAt']='2026-10-18T00:00:00Z';
$new['phase']='failed'; $new['error']='collection_failed';
$index=RecoveryIndex::latest([$row($old),$row($new)],$set->sourceId,null,false);
$check($index['latest_attempt']['phase']==='failed' && $index['latest_complete']['set_id']===$set->id,'latest failed does not hide last complete');
$check(RecoveryIndex::latest([$row($old)],str_repeat('f',32),null,false)['latest_attempt']===null,'source isolation');
$check(RecoveryIndex::latest([$row($old)],$set->sourceId,'database',false)['latest_attempt']===null,'profile isolation');
$tie=$new; $tie['intent']['id']=str_repeat('f',32);
foreach ([[$row($new),$row($tie)],[$row($tie),$row($new)]] as $rows) {
    $check(RecoveryIndex::latest($rows,$set->sourceId,null,false)['latest_attempt']['set_id']===str_repeat('f',32),'stable same-time tie');
}
foreach ([['option_name'=>'odbfs3_recovery_'.str_repeat('e',32),'option_value'=>'private broken'],
          ['option_name'=>'odbfs3_recovery_'.str_repeat('e',32),'option_value'=>json_encode($old)],
          ['option_name'=>'odbfs3_recovery_invalid','option_value'=>null]] as $bad) {
    $v=RecoveryIndex::latest([$row($old),$bad],$set->sourceId,null,false);
    $check(!$v['index_complete'] && $v['latest_complete']===null && $v['invalid_records']===1,'invalid row blocks authoritative latest');
    $check(!str_contains(json_encode($v),'private broken'),'index error redaction');
}
$check(!RecoveryIndex::latest([$row($old)],$set->sourceId,null,true)['index_complete'],'truncated scan');
$m=RecoveryManifest::decode($old['manifest']);
$ob=['set_id'=>$set->id,'manifest_sha256'=>hash('sha256',$m->encode()),'kind'=>'availability',
    'state'=>'available','checked_at'=>$end,'environment_id'=>null,'checks'=>['database_object','inventory_object','marker_object','all_media_objects']];
$orow=static function(array $r):array {
    $bytes=json_encode($r);return ['option_name'=>'odbfs3_observation_'.$r['set_id'].'_'.hash('sha256',$bytes),'option_value'=>$bytes];
};
$now='2026-10-17T01:00:00Z';
$v=RecoveryObservationSummary::aggregate($m,[$orow($ob)],$now,86400,false);
$check($v['availability']['state']==='available' && $v['restore']['reason_code']==='no_observation','available not restore');
$v=RecoveryObservationSummary::aggregate($m,[$orow($ob)],$now,1,false);
$check($v['availability']['state']==='unknown' && $v['availability']['recorded_state']==='available' && $v['availability']['freshness']==='stale','stale is not fresh success');
$later=$ob; $later['checked_at']='2026-10-17T00:02:00Z';$later['state']='inaccessible';$later['checks']=[];
$v=RecoveryObservationSummary::aggregate($m,[$orow($ob),$orow($later)],$now,86400,false);
$check($v['availability']['state']==='inaccessible','latest failure wins');
$conflict=$ob; $conflict['state']='missing';$conflict['checks']=[];
$v=RecoveryObservationSummary::aggregate($m,[$orow($ob),$orow($conflict)],$now,86400,false);
$check(!$v['aggregation_complete'] && $v['availability']['state']==='unknown','same time conflict');
$reordered=$ob; $reordered['checks']=array_reverse($reordered['checks']);
$check(RecoveryObservationSummary::aggregate($m,[$orow($ob),$orow($reordered)],$now,86400,false)['aggregation_complete'],'check order not conflict');
foreach (['hash','future','set','checksum','oversize'] as $case) {
    $bad=$ob;
    if($case==='hash')$bad['manifest_sha256']=str_repeat('0',64);
    if($case==='future')$bad['checked_at']='2026-10-18T00:00:00Z';
    if($case==='set')$bad['set_id']=str_repeat('f',32);
    $br=$orow($bad);
    if($case==='checksum')$br['option_name'].='0';
    if($case==='oversize')$br['option_value']=str_repeat('x',16385);
    $v=RecoveryObservationSummary::aggregate($m,[$orow($ob),$br],$now,86400,false);
    $check(!$v['aggregation_complete'] && $v['availability']['state']==='unknown','reject '.$case.' without old success fallback');
}
$check(!RecoveryObservationSummary::aggregate($m,[$orow($ob)],$now,86400,true)['aggregation_complete'],'observation limit');
$rest=$ob;$rest['kind']='restore';$rest['state']='passed';$rest['environment_id']=str_repeat('b',32);
$rest['checks']=['sql_import','wordpress_content','media_hashes','attachment_references'];
$v=RecoveryObservationSummary::aggregate($m,[$orow($rest)],$now,86400,false);
$check($v['restore']['state']==='passed' && $v['restore']['environment_id']===str_repeat('b',32),'restore environment retained');
if(!defined('ARRAY_A'))define('ARRAY_A','ARRAY_A');
class CatalogDb extends wpdb {
    public array $data=[]; public int $writes=0;
    public function esc_like($s){return addcslashes($s,'_%\\');}
    public function get_var($q){return $this->data[$q[1][0]]??null;}
    public function get_results($q,$format){
        [$size,$pattern,$limit]=$q[1]; $prefix=stripslashes(substr($pattern,0,-1)); $rows=[];
        $ordered=$this->data;ksort($ordered);
        foreach($ordered as $name=>$value)if(str_starts_with($name,$prefix))$rows[]=['option_name'=>$name,'option_value'=>strlen($value)<=$size?$value:null];
        return array_slice($rows,0,$limit);
    }
    public function query($q){
        if(!str_starts_with($q[0],'INSERT IGNORE'))throw new RuntimeException('unexpected mutation');
        ++$this->writes;[$name,$bytes]=$q[1];if(isset($this->data[$name]))return 0;$this->data[$name]=$bytes;return 1;
    }
}
$database=new CatalogDb();$database->data[$row($old)['option_name']]=$row($old)['option_value'];
$catalog=new WordPressRecoveryCatalog($database);
$digest=$catalog->appendObservation($set->id,$ob,$now);
$check($digest===$catalog->appendObservation($set->id,$reordered,$now) && count($database->data)===2,'idempotent append');
$before=$database->data;$writes=$database->writes;
$catalog->latest($set->sourceId,null);$catalog->observations($set->id,$now);
$check($before===$database->data && $writes===$database->writes,'catalog reads never write');
$bad=$ob;$bad['manifest_sha256']=str_repeat('0',64);
$reject(fn()=>$catalog->appendObservation($set->id,$bad,$now),'reject foreign observation before write');
$check($writes===$database->writes,'rejected observation no write');
$GLOBALS['wpdb']=$database;
$cli=static function(string $method,array $args,array $options) use($check):array {
    WP_CLI::$lines=[];$code=0;
    try{(new SecureS3StorageForWordpress\Cli\RecoverySetCommand())->$method($args,$options);}catch(StatusHalt $e){$code=$e->getCode();}
    $check(count(WP_CLI::$lines)===1,'catalog CLI one JSON document');
    return [$code,json_decode(WP_CLI::$lines[0],true,512,JSON_THROW_ON_ERROR)];
};
[$code,$v]=$cli('latest',[],['source-id'=>$set->sourceId,'format'=>'json']);
$check($code===0 && $v['latest_complete']['set_id']===$set->id,'latest CLI');
[$code,$v]=$cli('latest',[],['source-id'=>str_repeat('f',32),'format'=>'json']);$check($code===3,'latest no matching source');
[$code,$v]=$cli('latest',[],['source-id'=>'bad','format'=>'json']);$check($code===2,'latest invalid input');
[$code,$v]=$cli('observations',[$set->id],['format'=>'json']);$check($code===0,'observations CLI');
[$code,$v]=$cli('observations',[$set->id],['format'=>'json','max-age'=>'0']);$check($code===2,'invalid age');
[$code,$v]=$cli('observations',[str_repeat('f',32)],['format'=>'json']);$check($code===3,'observations no set');
$database->last_error='SECRET_SENTINEL';
[$code,$v]=$cli('latest',[],['source-id'=>$set->sourceId,'format'=>'json']);
$check($code===4 && !str_contains(json_encode($v),'SECRET_SENTINEL') && !$database->suppressed,'catalog DB error redacted and suppression restored');
$database->last_error='';
for($i=0;$i<=WordPressRecoveryCatalog::LIMIT;$i++)$database->data['odbfs3_recovery_'.str_pad(dechex($i),32,'0',STR_PAD_LEFT)]='broken';
$check($catalog->latest($set->sourceId,null)['truncated'],'repository bounds scan');
echo json_encode(['kind'=>'recovery_catalog_contract','passed'=>true,'checks'=>$checks-$initial]),"\n";
