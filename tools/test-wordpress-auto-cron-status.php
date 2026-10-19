<?php
// Read-only status. DOING_CRON suppresses opportunistic spawn during this inspection.
define('DOING_CRON',true);
require '/site/wp-load.php';
require_once '/repo/vendor/autoload.php';
$config=json_decode(file_get_contents('/private/cron-config.json'),true,16,JSON_THROW_ON_ERROR);
$schedule=\SecureS3StorageForWordpress\WordPress\WordPressJobStore::forRecoverySchedule($GLOBALS['wpdb'],$config['source_id'])->read();
$collector=null;
if ($schedule!==null) {
    $r=json_decode($schedule,true,16,JSON_THROW_ON_ERROR);
    $collector=\SecureS3StorageForWordpress\WordPress\WordPressJobStore::forRecovery($GLOBALS['wpdb'],$r['intent']['id'])->read();
}
echo json_encode(['schedule'=>$schedule,'collector'=>$collector,'next'=>wp_next_scheduled('odbfs3_recovery_tick_'.$config['source_id']),'cron_lock'=>get_transient('doing_cron')],JSON_THROW_ON_ERROR);
