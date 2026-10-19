<?php

namespace SecureS3StorageForWordpress\Cli;

use SecureS3StorageForWordpress\Backup\Recovery\RecoveryStatus;
use SecureS3StorageForWordpress\WordPress\WordPressJobStore;
use SecureS3StorageForWordpress\WordPress\WordPressRecoveryCatalog;
use Throwable;
use WP_CLI;

final class RecoverySetCommand
{
    /**
     * Explicitly remove only verified completed database temporary files.
     *
     * ## OPTIONS
     *
     * <set-id>
     * : Exact completed Recovery Set ID.
     * --workspace-parent=<path>
     * : Existing private POSIX workspace parent.
     * --confirm-set=<id>
     * : Repeat the same set ID to authorize local temporary cleanup.
     * [--format=<format>]
     * : json or table. Default: table.
     *
     * @subcommand cleanup
     * @when after_wp_load
     */
    public function cleanup(array $args,array $assocArgs):void
    {
        $format=$assocArgs['format']??'table';$parent=$assocArgs['workspace-parent']??null;
        if(count($args)!==1 || !is_string($args[0]) || preg_match('/^[a-f0-9]{32}$/D',$args[0])!==1
            || ($assocArgs['confirm-set']??null)!==$args[0] || !is_string($parent) || $parent===''
            || !in_array($format,['json','table'],true) || array_diff(array_keys($assocArgs),['workspace-parent','confirm-set','format'])){
            $this->output(['schema_version'=>1,'reason_code'=>'invalid_request'],$format==='json',2);return;
        }
        try{
            $db=$GLOBALS['wpdb'];$old=$db->suppress_errors(true);
            try{
                $uploads=wp_upload_dir(null,false,false);if(!empty($uploads['error']))throw new \RuntimeException('Uploads unavailable');
                $run=new \SecureS3StorageForWordpress\Backup\Recovery\RecoveryCleanup(
                    WordPressJobStore::forRecovery($db,$args[0]),WordPressJobStore::forRecoveryCleanup($db,$args[0]));
                $result=$run->execute($args[0],$parent,[ABSPATH,$uploads['basedir']]);
            }finally{$db->suppress_errors($old);}
        }catch(Throwable $e){$result=['schema_version'=>1,'set_id'=>$args[0],'state'=>'incomplete','reason_code'=>'state_unavailable'];}
        $this->output($result,$format==='json',$result['state']==='complete'?0:4);
    }

    /**
     * Inspect workspace cleanup candidates without deleting or changing state.
     *
     * ## OPTIONS
     *
     * <set-id>
     * : Exact Recovery Set ID.
     * --workspace-parent=<path>
     * : Existing canonical private POSIX workspace parent.
     * [--format=<format>]
     * : json or table. Default: table.
     *
     * @subcommand cleanup-plan
     * @when after_wp_load
     */
    public function cleanup_plan(array $args, array $assocArgs): void
    {
        $format=$assocArgs['format']??'table';$parent=$assocArgs['workspace-parent']??null;
        if(count($args)!==1 || !is_string($args[0]) || preg_match('/^[a-f0-9]{32}$/D',$args[0])!==1
            || !is_string($parent) || $parent==='' || !in_array($format,['json','table'],true)
            || array_diff(array_keys($assocArgs),['format','workspace-parent'])){
            $this->output(['schema_version'=>1,'reason_code'=>'invalid_request'],$format==='json',2);return;
        }
        try{
            $db=$GLOBALS['wpdb'];$old=$db->suppress_errors(true);
            try{
                // wp_upload_dir with create_dir=false: inspection must not create uploads.
                $uploads=wp_upload_dir(null,false,false);
                if(!empty($uploads['error']))throw new \RuntimeException('Uploads unavailable.');
                $record=\SecureS3StorageForWordpress\Backup\Recovery\RecoveryCleanupPlan::inspect(
                    WordPressJobStore::forRecovery($db,$args[0]),$args[0],$parent,[ABSPATH,$uploads['basedir']]);
            }finally{$db->suppress_errors($old);}
        }catch(Throwable $e){
            $this->output(['schema_version'=>1,'dry_run'=>true,'reason_code'=>'inspection_unavailable'],$format==='json',4);return;
        }
        $code=in_array('set_not_found',$record['protection_reasons'],true)?3:($record['inspection_complete']?0:4);
        $this->output($record,$format==='json',$code);
    }

    /**
     * Read the latest attempt and latest complete set from the site-local index.
     *
     * ## OPTIONS
     *
     * --source-id=<id>
     * : Exact source ID.
     * [--profile=<profile>]
     * : database or database_uploads. Omit to include both.
     * [--format=<format>]
     * : json or table. Default: table.
     *
     * @when after_wp_load
     */
    public function latest(array $args, array $assocArgs): void
    {
        $format=$assocArgs['format']??'table'; $source=$assocArgs['source-id']??''; $profile=$assocArgs['profile']??null;
        if ($args!==[] || !is_string($source) || preg_match('/^[a-f0-9]{32}$/D',$source)!==1
            || !in_array($profile,[null,'database','database_uploads'],true)
            || !in_array($format,['json','table'],true) || array_diff(array_keys($assocArgs),['source-id','profile','format'])) {
            $this->output(['schema_version'=>1,'reason_code'=>'invalid_request'],$format==='json',2); return;
        }
        try {
            $record=(new WordPressRecoveryCatalog($GLOBALS['wpdb']))->latest($source,$profile);
        } catch (Throwable $e) {
            $this->output(['schema_version'=>1,'index_complete'=>false,'reason_code'=>'index_unreadable'],$format==='json',4); return;
        }
        $this->output($record,$format==='json',!$record['index_complete'] ? 4 : ($record['latest_attempt']===null ? 3 : 0));
    }

    /**
     * Aggregate locally recorded observations for the exact completed manifest.
     *
     * ## OPTIONS
     *
     * <set-id>
     * : Exact Recovery Set ID.
     * [--max-age=<seconds>]
     * : Freshness window from 1 to 31536000 seconds. Default: 86400.
     * [--format=<format>]
     * : json or table. Default: table.
     *
     * @when after_wp_load
     */
    public function observations(array $args, array $assocArgs): void
    {
        $format=$assocArgs['format']??'table'; $age=(string)($assocArgs['max-age']??'86400');
        if (count($args)!==1 || !is_string($args[0]) || preg_match('/^[a-f0-9]{32}$/D',$args[0])!==1
            || preg_match('/^[1-9][0-9]{0,7}$/D',$age)!==1 || (int)$age>31536000
            || !in_array($format,['json','table'],true) || array_diff(array_keys($assocArgs),['format','max-age'])) {
            $this->output(['schema_version'=>1,'reason_code'=>'invalid_request'],$format==='json',2); return;
        }
        try {
            $record=(new WordPressRecoveryCatalog($GLOBALS['wpdb']))->observations($args[0],gmdate('Y-m-d\TH:i:s\Z'),(int)$age);
        } catch (Throwable $e) {
            $this->output(['schema_version'=>1,'set_id'=>$args[0],'aggregation_complete'=>false,'reason_code'=>'observations_unreadable'],$format==='json',4); return;
        }
        if ($record===null) {
            $this->output(['schema_version'=>1,'set_id'=>$args[0],'reason_code'=>'set_not_found'],$format==='json',3); return;
        }
        $this->output($record,$format==='json',$record['aggregation_complete'] ? 0 : 4);
    }

    /**
     * Read local Recovery Set evidence without advancing the job or contacting S3.
     *
     * ## OPTIONS
     *
     * <set-id>
     * : Exact 32-character lowercase hexadecimal Recovery Set ID.
     *
     * [--format=<format>]
     * : Output format: json or table. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp ozeki-database-backup-for-s3 recovery-set status <set-id> --format=json
     *
     * @when after_wp_load
     */
    public function status(array $args, array $assocArgs): void
    {
        $format = $assocArgs['format'] ?? 'table';
        if (count($args) !== 1 || ! is_string($args[0])
            || preg_match('/^[a-f0-9]{32}$/D', $args[0]) !== 1
            || ! in_array($format, ['json', 'table'], true)
            || array_diff(array_keys($assocArgs), ['format'])) {
            $this->output(['schema_version' => 1, 'phase' => 'unknown', 'reason_code' => 'invalid_request'], $format === 'json', 2);
            return;
        }
        try {
            $database = $GLOBALS['wpdb'];
            $previousSuppression = $database->suppress_errors(true);
            try {
                $record = RecoveryStatus::read(WordPressJobStore::forRecovery($database, $args[0]), $args[0]);
            } finally {
                $database->suppress_errors($previousSuppression);
            }
        } catch (Throwable $e) {
            $this->output(['schema_version' => 1, 'set_id' => $args[0], 'phase' => 'unknown', 'reason_code' => 'state_unreadable'], $format === 'json', 4);
            return;
        }
        if ($record === null) {
            $this->output(['schema_version' => 1, 'set_id' => $args[0], 'phase' => 'unknown', 'reason_code' => 'set_not_found'], $format === 'json', 3);
            return;
        }
        $this->output($record, $format === 'json', 0);
    }

    private function output(array $record, bool $json, int $code): void
    {
        if ($json) {
            WP_CLI::line(json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $rows = [];
            foreach ($record as $field => $value) {
                $rows[] = ['field' => $field, 'value' => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : ($value ?? '-')];
            }
            \WP_CLI\Utils\format_items('table', $rows, ['field', 'value']);
        }
        if ($code !== 0) { WP_CLI::halt($code); }
    }
}
