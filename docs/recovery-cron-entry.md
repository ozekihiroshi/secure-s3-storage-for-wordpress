# Recovery SetのCron入口（開発版）

`codex/recovery-operator-plan`の独立変更。共通DB部品採用PRとは分離している。
既存のDBバックアップCron・保持削除処理を変更せず、明示的な組込み用サービスとして追加した。

## 実行契約

`RecoverySetCronHandler::handle()`は1回につきCollectorの1処理だけを進める。受付と完了は別で、manifestの独立読戻し検証を通った場合だけ`complete`になる。DB/mediaを同一時点のスナップショットにする機能ではなく、既存の`uncoordinated`表記を維持する。

- UTC日付で1日次枠。同じsource ID・同じ日次枠は同じセットID。設定・環境・backendを先に永続化し、途中で現行設定へ差し替えない。
- sourceごとにWordPress optionsの非autoloadスロット`odbfs3_schedule_<source-id>`を使用する。受付はINSERT/CAS、Collectorも専用スロットとCASを使用する。DB台帳とworkspaceは全ワーカーで共有する必要がある。
- `initializing`中の中断は同じintentの受付を照合して再開する。`active`後にCollectorがなくなった場合は`unknown`で停止する。証拠を再作成して隠さない。
- DB/mediaの処理は必ずRecoveryLockを実装するアダプターを使う。進行中リースとworkspaceロックが重複実行を抑止する。期限切れでは既存チェックポイントから進め、送信の応答喪失は既存Pipeline/Publisherの照合処理へ戻す。
- 日付が変わっても未完了セットを先に進める。完了後の次回呼出しで現在の日次枠を予約し、さらに次回から実行する。過去日を埋めるバックアップは作らない。
- `failed`は翌日も停止する。設定変更は`blocked/configuration_changed`、時刻逆行は`blocked/clock_moved_backwards`。台帳破損・ストレージ障害・アダプター初期化失敗は`unknown`。例外の原文や認証情報は結果へ出さない。
- 自動削除・自動リセット・失敗後の新規セット作成はない。failedの承認付き再開・設定移行は今後の別機能。この版では元設定へ戻すか、証拠を保ったうえで開発者が状態をレビューする。台帳削除やsource ID変更による迂回はしない。

1回の処理には既存Collectorの60秒リースを使う。これはOSレベルの強制終了タイマーではない。同期DBダンプが60秒以内で終わる保証はなく、超過時は成功を確定せず`lease_lost`になる。大規模サイトの時間制限・強制停止・複数ホストのworkspace共有は別途検証が必要。

## WordPressへの明示的な接続

現時点は開発者向け入口。管理画面で有効化する設定や配布済みプラグインの自動切替は含まない。専用環境の実例は`tests/manual/test-wordpress-recovery.php`にある。

1. サイト固有の32桁source ID、保存先、profile、backend、環境情報を固定する。秘密情報を設定配列へ入れない。
2. 公開ディレクトリ外のprivate workspace、DB接続、DB dumper、S3 client、uploads sourceから、渡された**固定set**に対応する`RecoveryPipeline`と`RecoveryPublisher`を返すfactoryを用意する。factoryで現行の保存先を再読込しない。資格情報は別管理にする。
3. MUプラグイン等の信頼したbootstrapで、毎回`WordPressRecoverySchedule::register($wpdb, $configuration, $adapters, $report)`を呼ぶ。戻り値は`odbfs3_recovery_tick_<source-id>`。1プロセス内の二重登録は拒否する。外から渡されたCron引数は受け取らない。
4. `$report`で返却結果と実行時刻を運用ログへ記録し、failed/blocked/unknownを見落とさない。これは通知サービスではなく、報告先の接続も利用側が明示する。
5. テスト時は`wp cron event schedule <hook> now`で単発登録し、`wp cron event run <hook>`で1処理を実行する。セット完了には複数回の呼出しが必要。実運用の反復間隔はデータ量に合わせて設定する。1日1回だけの起動では日次セット作成が完了しない。
6. 停止はそのhookだけをunscheduleする。実行中処理や台帳は消さない。同じ設定・同じsourceで再登録すれば同じセットから続く。既存のDBバックアップCronは別管理なので、二重の運用を避ける判断も明示的に行う。

設定配列（このキー順を使用）：

```php
$configuration = [
    'source_id' => $siteSourceId,
    'profile' => 'database_uploads', // or database
    'bucket' => $bucket,
    'region' => $region,
    'prefix' => $prefix,
    'backend' => 'native', // or php
    'environment' => [
        'wordpress' => $wordpressVersion, 'php' => $phpVersion,
        'plugin' => $pluginVersion, 'database_server' => $databaseVersion,
        'table_prefix' => $tablePrefix,
    ],
];
```

日次枠はUTCで、日本時間では09:00に切り替わる。タイムゾーン・任意周期・過去枠指定はこの版に含めない。時刻は全ワーカーで同期する。version等の環境情報を更新した場合も設定変更として停止するため、無断で台帳を書き換えない。

## 結果の読取りと障害対応

戻り値は`schema_version/set_id/phase/reason_code`のみのJSON化可能な配列。`accepted`は受付、`database/media/assemble/ready/publishing`は途中、`complete`は公開検証済み。`busy/contended/lease_lost`は未完了であり、新規作成の指示ではない。

後続の[実行記録](recovery-execution-status.md)を使うWordPress接続では、開始・終了・確定進捗・最終保存成功をsource別に保存する。戻り値には`execution_recording=recorded/unavailable`を追加する。開始記録ができない場合はunknown/execution_record_unavailableで処理を開始しない。既存のreport接続先は引き続き利用できるが、実行サマリーの確認をreport先だけに依存しない。

`handle()`を直接呼ぶ運用CLIでも同じサービスを使用できるが、一般利用者向け`recovery-set create` CLIはまだない。状況確認には既存の`recovery-set status <id> --format=json`と`latest --source-id=<id> --format=json`を使用する。保存completeは復元試験成功を意味しない。

Cronの起動漏れ・report接続先の停止監視は外部の運用側で実施する。今回は自動監視や通知先を追加しない。結果不明時は同じサービスを呼び、既存証拠の照合結果を確認する。失敗・破損を削除して再送しない。

## 専用環境での検証

[検証集計](recovery-cron-validation.json)：PHP 8.1/8.3の追加25項目、WordPress 7.0.2のnative/PHPそれぞれ保存44・復元44チェックが合格。前提となる契約試験や各backendの件数は合算しない。

`tests/manual/test-recovery-cron.php`は重複・再入・ロック競合・リース切れ・設定変更・時刻逆行・受付ACK喪失・失敗・日次切替・証拠欠落を検証する。実WordPress fixtureでは登録したhookからnative/PHP双方のDB・メディア取得とmanifest公開を実行し、隔離した空DBへ復元する。AWSは使わず、転送先はディスク上のS3テスト実装。WP-Cronデーモンを長期間走らせた検証や実S3の再検証として数えない。
