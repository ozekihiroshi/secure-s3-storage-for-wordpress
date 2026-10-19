<?php
declare(strict_types=1);
namespace SecureS3StorageForWordpress\Admin;

use SecureS3StorageForWordpress\WordPress\{WordPressRecoveryCatalog,WordPressJobStore};
use SecureS3StorageForWordpress\Backup\Recovery\{RecoveryStatus,RecoveryCleanup};
use Throwable;

/** No mutation endpoints, scripts, polling, or remote/workspace access. */
final class RecoverySetsPage
{
    public const SLUG='odbfs3-recovery-sets';
    public function register():void {add_action('admin_menu',[$this,'menu']);}
    public function menu():void {add_options_page('Recovery Sets','Recovery Sets','manage_options',self::SLUG,[$this,'render']);}
    private function row(string $label,mixed $value):void {
        echo '<tr><th scope="row">'.esc_html($label).'</th><td>'.esc_html(is_bool($value)?($value?'はい':'いいえ'):(is_scalar($value)?(string)$value:($value===null?'—':wp_json_encode($value)))).'</td></tr>';
    }
    private function execution(string $source):void
    {
        echo '<h2>実行記録（Source ID: '.esc_html($source).'）</h2><p>最新の呼出しと確定した進捗の記録です。終了未記録は処理中・中断・記録失敗のいずれかで、生存確認ではありません。時刻はUTCです。</p>';
        try {
            $r=(new \SecureS3StorageForWordpress\Cron\RecoveryExecutionRecord(
                WordPressJobStore::forRecoveryExecution($GLOBALS['wpdb'],$source),$source))->read();
            echo '<table class="widefat striped"><tbody>';
            $this->row('最終起動',$r['latest_run']['started_at']??null);
            $this->row('最新呼出し',$r['latest_run']===null?'記録なし':($r['latest_run']['finished_at']===null?'終了未記録（状態不明）':'終了記録あり'));
            $this->row('最新呼出しの終了日時',$r['latest_run']['finished_at']??null);
            $this->row('最新呼出しの結果',$r['latest_run']['result']??null);
            $this->row('最後に終了を記録した呼出し',$r['last_result']);
            $this->row('最終進捗（確定した変更の観測時刻）',$r['last_progress']);
            $this->row('最終保存成功（公開検証時刻）',$r['last_success']);
            echo '</tbody></table>';
        } catch (Throwable $e) { echo '<p role="alert">実行記録を安全に読めません。状態は不明です。</p>'; }
    }
    public function render():void
    {
        if(!current_user_can('manage_options')){wp_die(esc_html__('この画面を表示する権限がありません。','ozeki-database-backup-for-s3'));return;}
        echo '<div class="wrap"><h1>Recovery Sets</h1><p>読取り専用です。表示してもバックアップ・検証・cleanupは実行されません。</p>';
        echo '<p>保存完了、取得可否の観測、復元試験は別の結果です。観測の鮮度は24時間。時刻はUTCです。</p>';
        echo '<form method="get" action="'.esc_url(admin_url('options-general.php')).'"><input type="hidden" name="page" value="'.esc_attr(self::SLUG).'"><label for="odb-source">Source ID</label> <input id="odb-source" name="source_id" maxlength="32" size="36"> <button class="button" type="submit">実行記録を表示</button></form>';
        echo '<form method="get" action="'.esc_url(admin_url('options-general.php')).'"><input type="hidden" name="page" value="'.esc_attr(self::SLUG).'"><label for="odb-set">セットID</label> <input id="odb-set" name="set_id" type="text" size="36" maxlength="32"> <button class="button" type="submit">状態を表示</button></form>';
        $id=$_GET['set_id']??'';
        if(!is_string($id) || ($id!=='' && preg_match('/^[a-f0-9]{32}$/D',$id)!==1)){
            echo '<p role="alert">セットIDは32桁の小文字16進数で指定してください。</p></div>';return;
        }
        $db=$GLOBALS['wpdb'];$old=$db->suppress_errors(true);
        $bufferLevel=ob_get_level();ob_start();
        try{
            $source=$_GET['source_id']??'';
            if (!is_string($source) || ($source!=='' && preg_match('/^[a-f0-9]{32}$/D',$source)!==1)) {
                echo '<p role="alert">Source IDは32桁の小文字16進数で指定してください。</p>';
            } elseif ($source!=='') { $this->execution($source); }
            $catalog=new WordPressRecoveryCatalog($db);
            if($id===''){
                $list=$catalog->inventory();
                if(!$list['complete'])echo '<p role="alert">一覧は不完全です。上限100件の超過または読めない記録があります。最新・成功だけの一覧とは判断しないでください。</p>';
                echo '<h2>保存されたセット</h2><table class="widefat striped"><thead><tr><th>セットID</th><th>作成日時（UTC）</th><th>Profile</th><th>処理状態</th><th>資産充足</th></tr></thead><tbody>';
                foreach($list['sets'] as $s){
                    $url=add_query_arg(['page'=>self::SLUG,'set_id'=>$s['set_id']],admin_url('options-general.php'));
                    echo '<tr><td><a href="'.esc_url($url).'">'.esc_html($s['set_id']).'</a></td>';
                    foreach(['created_at','profile','phase','completeness'] as $key)echo '<td>'.esc_html($s[$key]).'</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';if($list['sets']===[])echo '<p>表示できるセットがありません。</p>';
            }else{
                $store=WordPressJobStore::forRecovery($db,$id);$snapshot=$store->read();
                $status=RecoveryStatus::read(new \SecureS3StorageForWordpress\Backup\Recovery\RecoveryRecordSnapshot($snapshot),$id);
                if($status===null){echo '<p>セット記録がありません。</p>';}
                else{
                    if ($source!==$status['source_id']) $this->execution($status['source_id']);
                    echo '<h2>セットの状態</h2><table class="widefat striped"><tbody>';
                    foreach(['set_id'=>'セットID','source_id'=>'Source ID','profile'=>'Profile','created_at'=>'作成日時（UTC）','phase'=>'処理状態','completeness'=>'資産充足','reason_code'=>'状態理由','capture_consistency'=>'取得整合性'] as $key=>$label)$this->row($label,$status[$key]);
                    foreach(['database','media'] as $kind){$this->row($kind.'のセットID',$status['assets'][$kind.'_set_id']);$this->row($kind.' 保存時検証',$status['verification'][$kind]);$this->row($kind.' 検証日時（UTC）',$status['verification'][$kind.'_checked_at']);}
                    foreach(['local_record'=>'Manifest記録','publication'=>'Manifest公開検証','publication_checked_at'=>'公開検証日時（UTC）','sha256'=>'Manifest SHA-256'] as $key=>$label)$this->row($label,$status['manifest'][$key]);
                    echo '</tbody></table><h2>取得可否・復元試験の観測</h2>';
                    if($status['phase']==='complete'){
                        try{$obs=$catalog->observations($id,gmdate('Y-m-d\TH:i:s\Z'));
                            echo '<table class="widefat striped"><tbody>';
                            foreach(['availability'=>'取得可否','restore'=>'復元試験'] as $kind=>$label){
                                foreach(['state'=>'現在の評価','recorded_state'=>'記録された結果','checked_at'=>'検証日時（UTC）','freshness'=>'鮮度','reason_code'=>'理由'] as $key=>$field)$this->row($label.'：'.$field,$obs[$kind][$key]);
                            }
                            $this->row('集約完全性',$obs['aggregation_complete']);$this->row('集約理由',$obs['reason_code']);
                            echo '</tbody></table>';
                        }catch(Throwable $e){echo '<p role="alert">観測記録を安全に読めません。検証成功として扱わないでください。</p>';}
                    }else{echo '<p>保存完了前のため、完了manifestに結び付く観測は表示しません。</p>';}
                    echo '<h2>Cleanupの記録</h2><p>台帳上の進捗です。ファイルの現在の有無は調べません。needs_reconciliationは実行中と断定せず、照合が必要な状態です。</p><table class="widefat striped"><tbody>';
                    $cleanup=(new RecoveryCleanup($store,WordPressJobStore::forRecoveryCleanup($db,$id)))->status($id);
                    $this->row('進捗',$cleanup['state']);foreach($cleanup['files'] as $name=>$state)$this->row($name,$state);
                    echo '</tbody></table>';
                }
                if($store->read()!==$snapshot)throw new \RuntimeException('Record changed during display.');
            }
            echo ob_get_clean();
        }catch(Throwable $e){while(ob_get_level()>$bufferLevel)ob_end_clean();echo '<p role="alert">記録を安全に読めません。状態は不明です。再読込みして確認してください。</p>';}
        finally{$db->suppress_errors($old);}
        echo '</div>';
    }
}
