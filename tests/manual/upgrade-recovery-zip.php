<?php
if(getenv('ODBFS3_STATUS_FIXTURE')!=='1')throw new RuntimeException('Disposable fixture required');
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
$plugin='ozeki-database-backup-for-s3/ozeki-database-backup-for-s3.php';
$target=$args[0]??'0.3.0';
if(!preg_match('/^\d+\.\d+\.\d+$/D',$target))throw new RuntimeException('Invalid target version');
$current=get_plugin_data(WP_PLUGIN_DIR.'/'.$plugin,false,false)['Version'];
$updates=(object)['last_checked'=>time(),'checked'=>[$plugin=>$current],'response'=>[
 $plugin=>(object)['slug'=>'ozeki-database-backup-for-s3','plugin'=>$plugin,'new_version'=>$target,'package'=>'/candidate.zip','url'=>'https://example.invalid']]];
set_site_transient('update_plugins',$updates);
$upgrader=new Plugin_Upgrader(new Automatic_Upgrader_Skin());
$result=$upgrader->upgrade($plugin,['clear_update_cache'=>false]);
if(is_wp_error($result)||$result!==true)throw new RuntimeException('ZIP upgrade failed');
echo 'PASS WordPress Plugin_Upgrader upgrade';
