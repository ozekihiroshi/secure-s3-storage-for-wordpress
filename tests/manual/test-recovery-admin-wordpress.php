<?php
if(getenv('ODBFS3_STATUS_FIXTURE')!=='1')throw new RuntimeException('Disposable fixture required');
use SecureS3StorageForWordpress\Admin\RecoverySetsPage;
use SecureS3StorageForWordpress\WordPress\{WordPressJobStore,WordPressRecoveryCatalog};
$id=$args[0];$db=$GLOBALS['wpdb'];$checks=0;
$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);++$checks;};
require_once ABSPATH.'wp-admin/includes/plugin.php';
$admin=get_user_by('login','fixture');wp_set_current_user($admin->ID);
$page=new RecoverySetsPage();$page->register();do_action('admin_menu');
$check(isset($GLOBALS['submenu']['options-general.php']) && str_contains(wp_json_encode($GLOBALS['submenu']['options-general.php']),RecoverySetsPage::SLUG),'menu registration');
$fingerprint=static fn()=>hash('sha256',wp_json_encode($db->get_results("SELECT option_name,option_value,autoload FROM {$db->options} ORDER BY option_name",ARRAY_A)));
$render=static function($query)use($page,$fingerprint,$check){$_GET=$query;$before=$fingerprint();ob_start();$page->render();$html=ob_get_clean();$check($fingerprint()===$before,'render never writes options');return $html;};
$html=$render([]);$check(str_contains($html,$id) && str_contains($html,'failed'),'inventory exposes complete and failed');
$html=$render(['set_id'=>$id]);$check(str_contains($html,'available') && str_contains($html,'failed') && str_contains($html,'not_started'),'observations and cleanup separated');
$check(!str_contains($html,'/inspection') && !str_contains($html,'<script'),'no internal paths or scripts');
$check(!str_contains($html,'method="post"') && !str_contains($html,'admin-post.php'),'no mutation forms');
$html=$render(['set_id'=>'<script>alert(1)</script>']);$check(!str_contains($html,'<script>') && str_contains($html,'32桁'),'invalid ID escaped or rejected');
$html=$render(['set_id'=>[$id]]);$check(str_contains($html,'32桁'),'array ID rejected');
$html=$render(['set_id'=>str_repeat('f',32)]);$check(str_contains($html,'セット記録がありません'),'missing explicit');
$html=$render(['set_id'=>str_repeat('d',32)]);$check(str_contains($html,'保存完了前'),'failed set no false observation success');
// Fixture-only pending cleanup journal, deliberately referring to a nonexistent path.
$bytes=WordPressJobStore::forRecovery($db,$id)->read();$r=json_decode($bytes,true);$journal=['schema'=>1,'binding'=>['set_id'=>$id,'collector_sha256'=>hash('sha256',$bytes),'parent'=>'/does-not-exist/SECRET',
    'directory'=>['dev'=>1,'ino'=>2,'mode'=>16832,'uid'=>1,'gid'=>1],'manifest_sha256'=>hash('sha256',$r['manifest'])],'files'=>[]];
foreach(['database.sql','database.sql.gz']as $name){$e=$name==='database.sql'?$r['database']['raw_sql']:$r['database']['artifact'];
    $journal['files'][$name]=['state'=>'deleting','identity'=>['dev'=>1,'ino'=>3,'mode'=>33152,'nlink'=>1,'uid'=>1,'gid'=>1,'size'=>$e['size_bytes'],'mtime'=>1,'ctime'=>1],'sha256'=>$e['sha256'],'size_bytes'=>$e['size_bytes']];}
update_option('odbfs3_cleanup_'.$id,wp_json_encode($journal),false);
$html=$render(['set_id'=>$id]);$check(str_contains($html,'needs_reconciliation') && !str_contains($html,'SECRET'),'pending cleanup displayed without filesystem');
update_option('odbfs3_cleanup_'.$id,'SECRET broken',false);$html=$render(['set_id'=>$id]);$check(str_contains($html,'unknown') && !str_contains($html,'SECRET'),'corrupt journal not success');
// Replace fixture observations with an old one: do not elevate the old available state.
$names=$db->get_col($db->prepare("SELECT option_name FROM {$db->options} WHERE option_name LIKE %s",$db->esc_like('odbfs3_observation_'.$id.'_').'%'));
foreach($names as $name)delete_option($name);
(new WordPressRecoveryCatalog($db))->appendObservation($id,['set_id'=>$id,'manifest_sha256'=>hash('sha256',$r['manifest']),'kind'=>'availability','state'=>'available',
    'checked_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-172800),'environment_id'=>null,'checks'=>['database_object','inventory_object','marker_object','all_media_objects']],gmdate('Y-m-d\TH:i:s\Z'));
$html=$render(['set_id'=>$id]);$check(str_contains($html,'stale') && str_contains($html,'unknown') && str_contains($html,'available'),'stale retains recorded result but current unknown');
update_option('odbfs3_recovery_'.str_repeat('e',32),'SECRET<script>',false);$html=$render([]);
$check(str_contains($html,'一覧は不完全') && !str_contains($html,'SECRET'),'invalid inventory warning');
// Permission check must precede all catalog access.
// Source execution records: real WordPress storage/hook and read-only rendering.
$executionSource=str_repeat('9',32);
$cfg=['source_id'=>$executionSource,'profile'=>$r['intent']['profile'],'bucket'=>$r['intent']['bucket'],
    'region'=>$r['intent']['region'],'prefix'=>$r['intent']['prefix'],'backend'=>'php','environment'=>$r['environment']];
$reported=null;
$hook=\SecureS3StorageForWordpress\WordPress\WordPressRecoverySchedule::register($db,$cfg,
    static function(){throw new RuntimeException('SECRET fixture adapter unavailable');},
    static function($result)use(&$reported){$reported=$result;});
do_action($hook);
$execStore=WordPressJobStore::forRecoveryExecution($db,$executionSource);
$exec=new \SecureS3StorageForWordpress\Cron\RecoveryExecutionRecord($execStore,$executionSource);
$check($reported['execution_recording']==='recorded' && $exec->read()['last_result']['result']['phase']==='unknown','actual hook records initialization failure');
$html=$render(['source_id'=>$executionSource]);
$check(str_contains($html,'最終起動') && str_contains($html,'state_or_adapter_unavailable') && !str_contains($html,'SECRET'),'execution source view redacts exceptions');
$pending=$exec->start(time());
$html=$render(['source_id'=>$executionSource]);
$check(str_contains($html,'終了未記録（状態不明）'),'unfinished invocation not claimed alive');
$check((new \SecureS3StorageForWordpress\Cron\RecoveryExecutionRecord($execStore,$executionSource))->read()['latest_run']===$pending,'new reader preserves unfinished record');
$check($db->get_var($db->prepare("SELECT autoload FROM {$db->options} WHERE option_name=%s",'odbfs3_execution_'.$executionSource))==='no','execution record non-autoload');
$html=$render(['source_id'=>['bad']]);$check(str_contains($html,'Source IDは32桁'),'source array rejected');
$executionSaved=$execStore->read();
update_option('odbfs3_execution_'.$executionSource,'SECRET<script>',false);
$html=$render(['source_id'=>$executionSource]);$check(str_contains($html,'実行記録を安全に読めません') && !str_contains($html,'SECRET'),'corrupt execution unknown and redacted');
$check($execStore->compareAndSwap('SECRET<script>',$executionSaved),'restore only synthetic execution fixture');
add_filter('wp_die_handler',static fn()=>static function(){throw new RuntimeException('denied');});
$subscriber=wp_insert_user(['user_login'=>'fixtureviewer','user_pass'=>'fixture-only','role'=>'subscriber']);
wp_set_current_user($subscriber);$_GET=['set_id'=>$id];$before=$db->num_queries;$denied=false;
try{$page->render();}catch(RuntimeException $e){$denied=true;}
$check($denied && $db->num_queries===$before,'subscriber denied before DB');
wp_set_current_user(0);$_GET=['set_id'=>$id];$before=$db->num_queries;$denied=false;
try{$page->render();}catch(RuntimeException $e){$denied=true;}
$check($denied && $db->num_queries===$before,'unauthorized denied before DB');
echo wp_json_encode(['kind'=>'recovery_admin_wordpress','passed'=>true,'checks'=>$checks,'read_only'=>true,'bootstrap'=>'actual WordPress render callback, test-only registrar; no browser session']);
