# 予定超過の読取り判定（開発版）

Backup for S3のRecovery Setを対象に、設定された予定とローカルの実行・保存完了記録を照合する。通知、外部監視、Resilience接続、共通部品変更は含まない。画面表示・JSON取得によって実行・修復・S3通信・設定保存は行わない。

## 何を判定するか

保存元ごとに「初回予定」「周期」「起動の猶予」「完了の猶予」「有効・停止」を明示する。予定を自動推測して既存サイトへ適用しない。初期状態は予定未設定で、正常とは表示しない。

判定は**閲覧時点の現在の予定枠**だけを対象とする。初回予定以降、指定周期で区切った最新の枠を算出する。過去の全枠の取得漏れ一覧・警報履歴ではなく、翌枠に移るとその枠を判定する。停止中の期間を後から成功で埋めることはしない。

| 評価 | 意味 |
| --- | --- |
| not_configured／予定未設定 | 判定に必要な設定がない |
| stopped／予定判定を停止中 | 予定判定を無効にした状態。バックアップの健全性は評価しない |
| not_due／初回予定前 | 初回予定がまだ来ていない |
| within_grace／猶予時間内 | 予定枠が来たが、未達項目の期限をまだ過ぎていない |
| overdue／予定超過 | 起動または保存完了の未達項目が期限を過ぎた |
| observed／起動記録あり | 当該枠内のプラグイン呼出し開始が記録されている。起動側の個別判定 |
| complete／保存完了 | 当該枠で作成したセットの公開検証が期限内に完了している |
| complete_late／期限後に保存完了 | 当該枠のセットは保存完了したが、公開検証が完了期限より遅れた |
| unknown／判定不能 | 設定・実行・成功証拠が不正、読取り中に変更、未来時刻などで安全に判定できない |

期限ちょうどは猶予内。期限の1秒後から超過とする。未達の超過秒数を表示し、遅れて保存完了した場合は完了時の遅延秒数を残す。完了の猶予は起動の猶予以上、周期未満に限定し、期限が次の枠と重なる設定は拒否する。

起動は外部Cronプロセスではなく、前回追加した実行記録のlatest_run.started_atを使う。同じ枠内の最初の起動履歴は保存していないため、最後の起動から「定刻に起動した／遅れて起動した」を断定しない。起動記録があっても保存成功・現在稼働中の証明ではない。

保存完了はlast_successだけを信用せず、参照先Collectorを読み、同じsource、complete、公開検証時刻一致を確認する。さらにセット作成日時が現在枠に属することを要求する。前日のセットが今日完了しても今日の取得成功とは扱わない。現在のS3可用性や復元成功は別の観測である。

失敗・終了未記録は従来の実行記録欄に表示する。予定超過の猶予内だから処理成功という意味ではない。停止中は実行記録の破損も予定超過として評価しないが、実行記録欄では引き続き不明表示となる。

## 管理画面とJSON

「設定 → Recovery Sets」でSource ID検索、またはセット詳細を開くと「予定と猶予時間の判定」を表示する。権限はmanage_options。初回予定・対象枠・次回枠・起動/完了それぞれの期限・猶予・超過秒数をUTCで確認できる。

```sh
wp ozeki-database-backup-for-s3 recovery-set schedule-status --source-id=<32桁source-id> --format=json
```

schema_version=1。stateは全体、start/completionは個別判定。policy.settingsのanchor_atとpolicy.updated_atはUnix秒、それ以外の表示日時はUTC ISO 8601。scopeはcurrent_slot_local_records_only。起動のcompletion_delay_secondsはnullであり、起動遅延を推測しない。

終了コードは0=設定を読んで判定できた、2=引数不正、3=未設定、4=判定不能。予定超過でも0なのでstateを確認する。既存execution/status/latestのJSONは変更しない。

時刻逆行について、設定更新時刻・実行記録などが評価時刻より未来なら判定不能とする。閲覧時には観測時刻を書き込まないため、証拠より後の範囲内で時計だけが少し戻ったことまでは検知しない。ホスト時計の同期は必要。

## 設定の入口（開発者向け）

Cron接続自体が明示bootstrapを必要とする現段階に合わせ、設定は信頼したPHPから行う。一般利用者向け設定フォーム・設定変更CLIは今回追加しない。自動有効化・暗黙のデフォルト予定もない。

```php
use SecureS3StorageForWordpress\WordPress\WordPressRecoveryScheduleStatus;

$policy = WordPressRecoveryScheduleStatus::policy($wpdb, $sourceId);
$expected = $policy->read();
$settings = [
    'mode' => 'enabled',
    'anchor_at' => $firstExpectedSlotUtcEpoch,
    'interval_seconds' => 86400,
    'start_grace_seconds' => 600,
    'completion_grace_seconds' => 7200,
];
$saved = $policy->replace($expected, $settings, time());
```

これは初回設定・意図した変更時だけ実行する例。毎回time()から新しいanchorを作ると予定を先送りしてしまうので、固定した初回予定を使う。上記600秒・7200秒は例示で、サイトの処理時間に合わせて選ぶ。配列キーは例の順序と整数型を守る。

現在のRecovery Set生成処理はUTC日次枠なので、1日周期・UTC 00:00（日本時間09:00）のanchorに合わせる。任意周期を判定できることは、作成処理の周期を変更する機能ではない。反復Cronの各tickの周期ではなく、日次バックアップの予定枠を設定する。

設定は`odbfs3_expectation_<source-id>`の非autoload optionへ保存する。既存スケジュール・Collector・実行記録とは分離。replaceは期待した旧値とのCASで競合を拒否し、同じ設定なら書込みをしない。失敗時は再読取りして実際の保存内容を確認し、無条件に上書きしない。

停止する場合は既存設定のmodeをstoppedにしてreplaceする。これは**予定判定の停止**であり、実Cronを止めたり実行中処理を中断したりしない。実処理の停止・再開は[既存のCron手順](recovery-cron-entry.md)に従って別途行う。

stoppedからenabledへ戻す際は、現在以降の新しいanchorを明示する。停止期間を延滞扱いにしないため、過去anchorのままの再開は拒否する。元のCollectorを削除・作り直すことはなく、未完了セットを再開する既存動作も変えない。その古いセットの完了と、新しい予定枠の取得成功は別に判定する。

## 検証と残る範囲

2026-10-20 JST、PHP 8.1/8.3で追加39項目ずつ合格。実WordPressの保存・render・権限21項目と、実WP-CLIのJSON/表/超過/未設定/破損/不正入力、DB・workspaceの読取り前後一致が合格。既存の前提試験やバージョン間の件数は合算しない。

[検証集計](recovery-schedule-validation.json)に専用環境の結果を記録する。PHP契約試験は`tests/manual/test-recovery-schedule-status.php`、実WordPress/MariaDB・実WP-CLI・管理画面renderは`tools/test-recovery-status-local.py --schedule-only`で再実行できる。

全枠の履歴保存、設定変更の監査履歴、予定設定UI、ライブ稼働確認、通知、サイト停止中の検知は今回の範囲外。実S3への再保存・復元、本番適用・ZIP更新は行っていない。ブラウザ操作試験ではなく実WordPressのrenderとCLIの検証として区別する。
