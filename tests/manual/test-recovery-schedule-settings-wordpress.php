<?php
if(getenv('ODBFS3_STATUS_FIXTURE')!=='1')throw new RuntimeException('Disposable fixture required');
use SecureS3StorageForWordpress\Admin\{RecoveryScheduleSettings,RecoverySetsPage};
use SecureS3StorageForWordpress\WordPress\WordPressRecoveryScheduleStatus;
$db=$GLOBALS['wpdb'];$checks=0;$source=str_repeat('a',32);
$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);++$checks;};
$admin=get_user_by('login','fixture');wp_set_current_user($admin->ID);
$settings=new RecoveryScheduleSettings();(new RecoverySetsPage())->register();
$check(has_action('admin_post_'.RecoveryScheduleSettings::ACTION)!==false,'actual POST hook registered');
$fingerprint=static fn()=>hash('sha256',wp_json_encode($db->get_results("SELECT option_name,option_value,autoload FROM {$db->options} ORDER BY option_name",ARRAY_A)));
$unrelated=static fn()=>hash('sha256',wp_json_encode($db->get_results($db->prepare("SELECT option_name,option_value,autoload FROM {$db->options} WHERE option_name != %s ORDER BY option_name",'odbfs3_expectation_'.$source),ARRAY_A)));
$policy=WordPressRecoveryScheduleStatus::policy($db,$source);
$form=static function()use($settings,$source,$fingerprint,$check){
    $before=$fingerprint();ob_start();$settings->render($source);$html=ob_get_clean();
    $check($before===$fingerprint(),'form render read only');
    $post=[];preg_match_all('/<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"/',$html,$m,PREG_SET_ORDER);
    foreach($m as $match)$post[$match[1]]=html_entity_decode($match[2],ENT_QUOTES);
    $post['mode']='enabled';$post['anchor']=gmdate('Y-m-d\TH:i',time()+86400);$post['acknowledge']='1';return [$html,$post];
};
[$html,$post]=$form();
$check(str_contains($html,'実Cronの起動・停止・周期') && str_contains($html,'予定判定の設定を保存'),'explicit scope shown');
$check(isset($post['_wpnonce'],$post['expected'],$post['source_id']),'rendered nonce and concurrency token');
$reject=static function($p,$method='POST')use($settings,$fingerprint,$check){$before=$fingerprint();$r=$settings->save($p,$method);$check($r!=='saved' && $before===$fingerprint(),'rejected request no writes');};
$reject($post,'GET');
$bad=$post;$bad['_wpnonce']='invalid';$reject($bad);
$bad=$post;$bad['source_id']=str_repeat('b',32);$reject($bad);
$bad=$post;$bad['expected']=str_repeat('0',64);$reject($bad);
foreach(['interval'=>'-1','start_grace'=>'1.2','completion_grace'=>'86400','anchor'=>'2026-02-30T00:00','mode'=>'other','acknowledge'=>'0'] as $key=>$value){$bad=$post;$bad[$key]=$value;$reject($bad);}
$bad=$post;$bad['interval']=['86400'];$reject($bad);
$beforeOther=$unrelated();$check($settings->save($post,'POST')==='saved','save rendered form');
$p=$policy->read();$check($p['settings']['mode']==='enabled' && $p['settings']['interval_seconds']===86400,'settings persisted');
$check($beforeOther===$unrelated(),'only expectation row changed; cron and evidence unchanged');
$reject($post); // Replayed stale form cannot replace changed state.
[$html,$stop]=$form();$stop['mode']='stopped';$check($settings->save($stop,'POST')==='saved','stop only assessment');
$check(WordPressRecoveryScheduleStatus::read($db,$source)['state']==='stopped','stopped visible to status');
[$html,$resume]=$form();$resume['anchor']=gmdate('Y-m-d\TH:i:s',time()-100);$reject($resume);
$resume['anchor']=gmdate('Y-m-d\TH:i:s',time()+86400);$resume['interval']='172800';$resume['start_grace']='120';$resume['completion_grace']='3600';
$check($settings->save($resume,'POST')==='saved','resume future anchor and update periods');
$p=$policy->read();$check($p['settings']['interval_seconds']===172800 && $p['settings']['start_grace_seconds']===120 && $p['settings']['completion_grace_seconds']===3600,'all numeric settings updated');
$check($beforeOther===$unrelated(),'all operations preserve unrelated options');
[$html,$routePost]=$form();$routePost['mode']='stopped';$_POST=$routePost;$_SERVER['REQUEST_METHOD']='POST';
$location=null;$redirect=static function($url)use(&$location){$location=$url;throw new RuntimeException('fixture_redirect');};
add_filter('wp_redirect',$redirect);$redirected=false;
try{do_action('admin_post_'.RecoveryScheduleSettings::ACTION);}catch(RuntimeException $e){$redirected=$e->getMessage()==='fixture_redirect';}
remove_filter('wp_redirect',$redirect);
$check($redirected && str_contains($location,'schedule_saved=1') && $policy->read()['settings']['mode']==='stopped','registered handler saves and redirects locally');
$p=$policy->read();
[$html,$fresh]=$form();$external=$p['settings'];$external['start_grace_seconds']=121;$policy->replace($p,$external,time());$reject($fresh);
[$html,$fresh]=$form();$subscriber=wp_insert_user(['user_login'=>'settingsviewer','user_pass'=>'fixture-only','role'=>'subscriber']);
foreach([$subscriber,0] as $uid){wp_set_current_user($uid);$q=$db->num_queries;$reject($fresh);$check($db->num_queries===$q+2,'permission before policy query (only test snapshots)');}
wp_set_current_user($admin->ID);update_option('odbfs3_expectation_'.$source,'SECRET<script>',false);
$before=$fingerprint();ob_start();$settings->render($source);$html=ob_get_clean();
$check(!str_contains($html,'<form') && !str_contains($html,'SECRET') && $before===$fingerprint(),'corrupt policy cannot be overwritten from form');
$reject($fresh);
echo wp_json_encode(['kind'=>'schedule_settings_wordpress','passed'=>true,'checks'=>$checks,'browser'=>false]);
