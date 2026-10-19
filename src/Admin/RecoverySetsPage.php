<?php
declare(strict_types=1);
namespace SecureS3StorageForWordpress\Admin;

use SecureS3StorageForWordpress\WordPress\{WordPressRecoveryCatalog,WordPressJobStore};
use SecureS3StorageForWordpress\Backup\Recovery\{RecoveryStatus,RecoveryCleanup};
use Throwable;

/** Read-only status views plus an explicit expectation-only settings form. */
final class RecoverySetsPage
{
    public const SLUG='odbfs3-recovery-sets';
    /** Read-only filters: reject malformed input rather than normalize it into another ID. */
    private function query(string $name, string $pattern): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only filter; unslashed below and accepted only by the complete allowlist expression.
        $value=wp_unslash($_GET[$name]??'');
        if (!is_string($value)) return null;
        return preg_match($pattern,$value)===1?$value:null;
    }
    public function register():void {add_action('admin_menu',[$this,'menu']);(new RecoveryScheduleSettings())->register();}
    public function menu():void {add_options_page('Recovery Sets','Recovery Sets','manage_options',self::SLUG,[$this,'render']);}
    private function row(string $label,mixed $value):void {
        echo '<tr><th scope="row">'.esc_html($label).'</th><td>'.esc_html(is_bool($value)?($value?'はい':'いいえ'):(is_scalar($value)?(string)$value:($value===null?'—':wp_json_encode($value)))).'</td></tr>';
    }
    private function execution(string $source):void
    {
        $this->schedule($source);
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
    private function schedule(string $source):void
    {
        $r=\SecureS3StorageForWordpress\WordPress\WordPressRecoveryScheduleStatus::read($GLOBALS['wpdb'],$source);
        $labels=['unknown'=>'判定不能','not_configured'=>'予定未設定','stopped'=>'予定判定を停止中',
            'not_due'=>'初回予定前','within_grace'=>'猶予時間内','overdue'=>'予定超過',
            'observed'=>'起動記録あり','complete'=>'保存完了','complete_late'=>'期限後に保存完了'];
        echo '<h2>予定と猶予時間の判定</h2><p>閲覧時点の現在の予定枠を評価します。停止は予定判定の設定であり、Cron自体を停止する操作ではありません。起動記録はプラグインの呼出しを示し、生存や定刻起動の証明ではありません。</p><table class="widefat striped"><tbody>';
        $this->row('判定',$labels[$r['state']]??$r['state']);$this->row('判定日時（UTC）',$r['evaluated_at']);
        $this->row('理由',$r['reason_code']);$this->row('対象予定枠（UTC）',$r['slot_at']);$this->row('次回予定枠（UTC）',$r['next_slot_at']);
        if ($r['policy']!==null) {
            $s=$r['policy']['settings'];
            $this->row('初回予定（UTC）',gmdate('Y-m-d\TH:i:s\Z',$s['anchor_at']));
            $this->row('判定の有効・停止',$s['mode']);$this->row('予定間隔（秒）',$s['interval_seconds']);
            $this->row('起動の猶予（秒）',$s['start_grace_seconds']);$this->row('完了の猶予（秒）',$s['completion_grace_seconds']);
        }
        foreach (['start'=>'起動','completion'=>'保存完了'] as $key=>$label) {
            if ($r[$key]===null) continue;
            $this->row($label.'：判定',$labels[$r[$key]['state']]??$r[$key]['state']);
            $this->row($label.'：期限（UTC）',$r[$key]['deadline_at']);
            $this->row($label.'：観測日時（UTC）',$r[$key]['observed_at']);
            $this->row($label.'：未達の超過秒数',$r[$key]['overdue_seconds']);
            if ($key==='completion') $this->row($label.'：完了時の遅延秒数',$r[$key]['completion_delay_seconds']);
        }
        echo '</tbody></table>';
        (new RecoveryScheduleSettings())->render($source);
    }
    public function render():void
    {
        if(!current_user_can('manage_options')){wp_die(esc_html__('この画面を表示する権限がありません。','ozeki-database-backup-for-s3'));return;}
        echo '<div class="wrap"><h1>Recovery Sets</h1><p>状態の表示は読取り専用です。表示してもバックアップ・検証・cleanupは実行されません。予定判定の設定は専用フォームから明示的に保存します。</p>';
        if ($this->query('schedule_saved','/^(|1)$/D')==='1') echo '<p>予定判定の設定を保存しました。実Cronは変更していません。</p>';
        echo '<p>保存完了、取得可否の観測、復元試験は別の結果です。観測の鮮度は24時間。時刻はUTCです。</p>';
        echo '<form method="get" action="'.esc_url(admin_url('options-general.php')).'"><input type="hidden" name="page" value="'.esc_attr(self::SLUG).'"><label for="odb-source">Source ID</label> <input id="odb-source" name="source_id" maxlength="32" size="36"> <button class="button" type="submit">実行記録を表示</button></form>';
        echo '<form method="get" action="'.esc_url(admin_url('options-general.php')).'"><input type="hidden" name="page" value="'.esc_attr(self::SLUG).'"><label for="odb-set">セットID</label> <input id="odb-set" name="set_id" type="text" size="36" maxlength="32"> <button class="button" type="submit">状態を表示</button></form>';
        $id=$this->query('set_id','/^(|[a-f0-9]{32})$/D');
        if(!is_string($id) || ($id!=='' && preg_match('/^[a-f0-9]{32}$/D',$id)!==1)){
            echo '<p role="alert">セットIDは32桁の小文字16進数で指定してください。</p></div>';return;
        }
        $db=$GLOBALS['wpdb'];$old=$db->suppress_errors(true);
        $bufferLevel=ob_get_level();ob_start();
        try{
            $source=$this->query('source_id','/^(|[a-f0-9]{32})$/D');
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
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Buffer contains only this renderer's static markup and individually esc_html/esc_attr/esc_url escaped values. Discard it if the record changes; escaping the whole buffer would break the settings form.
            echo ob_get_clean();
        }catch(Throwable $e){while(ob_get_level()>$bufferLevel)ob_end_clean();echo '<p role="alert">記録を安全に読めません。状態は不明です。再読込みして確認してください。</p>';}
        finally{$db->suppress_errors($old);}
        echo '</div>';
    }
}
