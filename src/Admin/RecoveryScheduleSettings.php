<?php
declare(strict_types=1);
namespace SecureS3StorageForWordpress\Admin;

use Throwable;
use SecureS3StorageForWordpress\Cron\RecoverySchedulePolicy;
use SecureS3StorageForWordpress\WordPress\WordPressRecoveryScheduleStatus;

/** The only mutation here is the source-local expectation policy. */
final class RecoveryScheduleSettings
{
    public const ACTION='odbfs3_save_schedule_expectation';
    public static function token(?array $policy):string { return hash('sha256',json_encode($policy,JSON_THROW_ON_ERROR)); }
    public static function nonceAction(string $source,string $token):string { return self::ACTION.':'.$source.':'.$token; }

    public function register():void { add_action('admin_post_'.self::ACTION,[$this,'handle']); }
    public function handle():void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save() validates capability, every field's type/allowlist and the source/token-bound nonce before any mutation.
        $post=wp_unslash($_POST);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Request-method metadata only; save() verifies the nonce before writing.
        $method=isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        $result=$this->save($post,$method);
        if ($result!=='saved') {
            wp_die(esc_html('保存を確認できませんでした。保存済みの可能性もあるため、再送せず画面を再読込みしてください。権限・入力・確認欄と最新設定を確認してください。停止から再開する場合は未来の初回予定が必要です。'),'', ['response'=>$result==='denied'?403:400]);return;
        }
        wp_safe_redirect(add_query_arg(['page'=>RecoverySetsPage::SLUG,'source_id'=>$post['source_id'],'schedule_saved'=>'1'],admin_url('options-general.php')));
        exit;
    }

    /** Shared handler boundary, also exercised against real WordPress in isolated tests. */
    public function save(array $post,string $method):string
    {
        if ($method!=='POST' || !current_user_can('manage_options')) return 'denied';
        foreach (['source_id','expected','_wpnonce','mode','anchor','interval','start_grace','completion_grace','acknowledge'] as $field)
            if (!isset($post[$field]) || !is_string($post[$field])) return 'invalid';
        $source=$post['source_id'];$token=$post['expected'];
        if (preg_match('/^[a-f0-9]{32}$/D',$source)!==1 || preg_match('/^[a-f0-9]{64}$/D',$token)!==1
            || !wp_verify_nonce($post['_wpnonce'],self::nonceAction($source,$token))) return 'denied';
        if ($post['acknowledge']!=='1') return 'invalid';
        foreach (['interval','start_grace','completion_grace'] as $field)
            if (preg_match('/^(0|[1-9][0-9]{0,7})$/D',$post[$field])!==1) return 'invalid';
        $anchor=$post['anchor'];if (strlen($anchor)===16) $anchor.=':00';
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$anchor)!==1) return 'invalid';
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s',$anchor,new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\TH:i:s')!==$anchor) return 'invalid';
        $settings=['mode'=>$post['mode'],'anchor_at'=>$date->getTimestamp(),'interval_seconds'=>(int)$post['interval'],
            'start_grace_seconds'=>(int)$post['start_grace'],'completion_grace_seconds'=>(int)$post['completion_grace']];
        try { RecoverySchedulePolicy::settings($settings); } catch (Throwable $e) { return 'invalid'; }
        $db=$GLOBALS['wpdb'];$old=$db->suppress_errors(true);
        try {
            $policy=WordPressRecoveryScheduleStatus::policy($db,$source);$current=$policy->read();
            if (!hash_equals(self::token($current),$token)) return 'conflict';
            $policy->replace($current,$settings,time());
            return 'saved';
        } catch (Throwable $e) { return 'unavailable'; }
        finally { $db->suppress_errors($old); }
    }

    public function render(string $source):void
    {
        if (!current_user_can('manage_options')) return;
        try { $p=WordPressRecoveryScheduleStatus::policy($GLOBALS['wpdb'],$source)->read(); }
        catch (Throwable $e) { echo '<p>予定設定を安全に読み取れないため、編集できません。</p>';return; }
        $s=$p['settings']??['mode'=>'stopped','anchor_at'=>strtotime('tomorrow UTC'),'interval_seconds'=>86400,'start_grace_seconds'=>600,'completion_grace_seconds'=>7200];
        echo '<h2>予定判定の設定</h2><p>対象Source ID: '.esc_html($source).'</p><p>ここで変更するのは予定判定だけです。実Cronの起動・停止・周期や、実行中のバックアップは変更しません。現在の取得処理はUTC日次枠です。通常は周期86400秒、初回予定UTC 00:00（日本時間09:00）に合わせてください。</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        foreach (['action'=>self::ACTION,'source_id'=>$source,'expected'=>self::token($p)] as $name=>$value)
            echo '<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr($value).'">';
        wp_nonce_field(self::nonceAction($source,self::token($p)));
        echo '<p><label>予定判定 <select name="mode"><option value="stopped"'.selected($s['mode'],'stopped',false).'>停止</option><option value="enabled"'.selected($s['mode'],'enabled',false).'>有効</option></select></label></p>';
        echo '<p><label>初回予定（UTC） <input type="datetime-local" name="anchor" step="1" required value="'.esc_attr(gmdate('Y-m-d\TH:i:s',$s['anchor_at'])).'"></label></p><p>停止から再開するときは未来の初回予定を指定してください。既存の未完了処理は消えません。</p>';
        foreach (['interval'=>['予定周期（秒）','interval_seconds',60], 'start_grace'=>['起動の猶予（秒）','start_grace_seconds',0], 'completion_grace'=>['完了の猶予（秒）','completion_grace_seconds',0]] as $name=>[$label,$key,$min])
            echo '<p><label>'.esc_html($label).' <input type="number" required name="'.esc_attr($name).'" min="'.esc_attr((string)$min).'" max="31536000" step="1" value="'.esc_attr((string)$s[$key]).'"></label></p>';
        echo '<p>完了の猶予は起動の猶予以上、予定周期未満にしてください。</p><p><label><input type="checkbox" name="acknowledge" value="1" required>予定判定のみを変更し、実Cronは変更しないことを確認しました。</label></p><button type="submit" class="button button-primary">予定判定の設定を保存</button></form>';
    }
}
