<?php
if(getenv('ODBFS3_STATUS_FIXTURE')!=='1')throw new RuntimeException('Disposable fixture required');
use SecureS3StorageForWordpress\Admin\RecoveryScheduleSettings;
use SecureS3StorageForWordpress\WordPress\{WordPressRecoveryScheduleStatus,WordPressJobStore};
use SecureS3StorageForWordpress\Cron\RecoveryExecutionRecord;
$db=$GLOBALS['wpdb'];$mode=$args[0];$id=$args[1];$now=time();
wp_set_current_user(get_user_by('login','fixture')->ID);
foreach(['a'=>'enabled','b'=>'stopped'] as $letter=>$state){
    $source=str_repeat($letter,32);$policy=WordPressRecoveryScheduleStatus::policy($db,$source);
    if($mode==='seed'){
        $settings=new RecoveryScheduleSettings();ob_start();$settings->render($source);$html=ob_get_clean();
        preg_match_all('/<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"/',$html,$matches,PREG_SET_ORDER);$post=[];
        foreach($matches as $match)$post[$match[1]]=html_entity_decode($match[2],ENT_QUOTES);
        $post=array_merge($post,['mode'=>$state,'anchor'=>gmdate('Y-m-d\TH:i:s',$now+86400),'interval'=>'172800','start_grace'=>'120','completion_grace'=>'3600','acknowledge'=>'1']);
        if($settings->save($post,'POST')!=='saved')throw new RuntimeException('Installed ZIP settings save failed');
        $record=new RecoveryExecutionRecord(WordPressJobStore::forRecoveryExecution($db,$source),$source);
        $run=$record->start($now-30);
        $record->finish($run,['schema_version'=>1,'set_id'=>$id,'phase'=>'complete','reason_code'=>'publication_verified'],$now-25,$id,['set_id'=>$id,'at'=>gmdate('Y-m-d\TH:i:s\Z',$now-25)]);
        $run=$record->start($now-20);
        $record->finish($run,['schema_version'=>1,'set_id'=>$id,'phase'=>'failed','reason_code'=>'collector_result'],$now-15,$id,null);
        $record->start($now-10);
    }elseif($mode!=='verify'){throw new RuntimeException('Unknown mode');}
    $p=$policy->read()['settings'];
    if($p['mode']!==$state||$p['interval_seconds']!==172800||$p['start_grace_seconds']!==120||$p['completion_grace_seconds']!==3600)throw new RuntimeException('Settings not preserved');
    $r=(new RecoveryExecutionRecord(WordPressJobStore::forRecoveryExecution($db,$source),$source))->read();
    if($r['latest_run']['finished_at']!==null||$r['last_result']['result']['phase']!=='failed'||$r['last_success']['set_id']!==$id||$r['last_progress']['set_id']!==$id)throw new RuntimeException('Execution history not preserved');
    ob_start();(new RecoveryScheduleSettings())->render($source);$html=ob_get_clean();
    if(!str_contains($html,'予定判定の設定を保存')||!str_contains($html,'172800'))throw new RuntimeException('Installed settings form missing');
}
echo 'PASS installed ZIP schedule settings and execution records '.$mode;
