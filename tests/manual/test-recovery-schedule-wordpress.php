<?php
if(getenv('ODBFS3_STATUS_FIXTURE')!=='1') throw new RuntimeException('Disposable fixture required');
use SecureS3StorageForWordpress\WordPress\{WordPressRecoveryScheduleStatus,WordPressJobStore};
use SecureS3StorageForWordpress\Cron\RecoveryExecutionRecord;
use SecureS3StorageForWordpress\Admin\RecoverySetsPage;

$db=$GLOBALS['wpdb'];$checks=0;$source=str_repeat('7',32);$now=time();
$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);++$checks;};
$fingerprint=static fn()=>hash('sha256',wp_json_encode($db->get_results("SELECT option_name,option_value,autoload FROM {$db->options} ORDER BY option_name",ARRAY_A)));
$admin=get_user_by('login','fixture');wp_set_current_user($admin->ID);
$page=new RecoverySetsPage();
$render=static function($source)use($page,$fingerprint,$check){
    $_GET=['source_id'=>$source];$before=$fingerprint();ob_start();$page->render();$html=ob_get_clean();
    $check($before===$fingerprint(),'render no DB changes');return $html;
};
$html=$render($source);$check(str_contains($html,'予定未設定'),'unconfigured explicitly shown');
$cronBefore=get_option('cron');
$policy=WordPressRecoveryScheduleStatus::policy($db,$source);
$settings=['mode'=>'enabled','anchor_at'=>$now-120,'interval_seconds'=>86400,'start_grace_seconds'=>10,'completion_grace_seconds'=>30];
$p=$policy->replace(null,$settings,$now);
$r=WordPressRecoveryScheduleStatus::read($db,$source,$now);
$check($r['state']==='overdue' && $r['start']['overdue_seconds']===110 && $r['completion']['overdue_seconds']===90,'actual DB overdue calculation');
$before=$fingerprint();WordPressRecoveryScheduleStatus::read($db,$source,$now);$check($before===$fingerprint(),'status read preserves DB');
$html=$render($source);$check(str_contains($html,'予定超過') && str_contains($html,'起動の猶予（秒）') && str_contains($html,'保存完了：期限'),'overdue and distinct deadlines displayed');
$exec=new RecoveryExecutionRecord(WordPressJobStore::forRecoveryExecution($db,$source),$source);
$pending=$exec->start($now);
$r=WordPressRecoveryScheduleStatus::read($db,$source,$now);
$check($r['start']['state']==='observed' && $r['completion']['state']==='overdue','actual start alone does not satisfy completion');
$html=$render($source);$check(str_contains($html,'起動記録あり') && str_contains($html,'終了未記録（状態不明）'),'start observed not live worker');
$stop=$settings;$stop['mode']='stopped';$p=$policy->replace($p,$stop,$now);
$html=$render($source);$check(str_contains($html,'予定判定を停止中') && !str_contains($html,'予定超過'),'stopped not overdue');
$check(get_option('cron')===$cronBefore,'expectation changes do not alter WordPress cron');
$check($db->get_var($db->prepare("SELECT autoload FROM {$db->options} WHERE option_name=%s",'odbfs3_expectation_'.$source))==='no','policy non-autoload');
$resume=$settings;$resume['anchor_at']=$now+86400;$p=$policy->replace($p,$resume,$now);
$html=$render($source);$check(str_contains($html,'初回予定前'),'explicit resume anchor shown');
$check(get_option('cron')===$cronBefore,'resume changes no jobs');
$stop=$resume;$stop['mode']='stopped';$policy->replace($p,$stop,$now);
update_option('odbfs3_expectation_'.str_repeat('6',32),'SECRET<script>',false);
WordPressRecoveryScheduleStatus::policy($db,str_repeat('5',32))->replace(null,$settings,$now);
$html=$render(str_repeat('6',32));$check(str_contains($html,'判定不能') && !str_contains($html,'SECRET'),'corrupt settings do not leak or appear disabled');
$check(!str_contains($html,'method="post"'),'no mutation form');
add_filter('wp_die_handler',static fn()=>static function(){throw new RuntimeException('denied');});
$subscriber=wp_insert_user(['user_login'=>'scheduleviewer','user_pass'=>'fixture-only','role'=>'subscriber']);
foreach ([$subscriber,0] as $uid) {
    wp_set_current_user($uid);$_GET=['source_id'=>$source];$queries=$db->num_queries;$denied=false;
    try{$page->render();}catch(RuntimeException $e){$denied=true;}
    $check($denied && $db->num_queries===$queries,'permission denied before schedule reads');
}
echo wp_json_encode(['kind'=>'recovery_schedule_wordpress','passed'=>true,'checks'=>$checks,'read_only'=>true,'browser'=>false]);
