# Recovery Setの実行記録（開発版）

Backup for S3単独の機能。通知、外部監視、Resilience接続、共通部品変更は含まない。既存のDB単体Cron・メディア単体ジョブの履歴を統合する変更でもない。

## 記録される範囲

`WordPressRecoverySchedule::create/register`から呼び出すRecovery Set処理で、sourceごとの非autoload option `odbfs3_execution_<source-id>`に実行サマリーを保存する。Collector・スケジュール・cleanupの台帳とは別で、索引の件数にも混ぜない。直接`RecoverySetCronHandler`を組み立てる開発者は、最後の引数に`RecoveryExecutionRecord`を明示する。既存の直接呼出しは互換性のため自動記録しない。

| JSON項目 | 意味 |
| --- | --- |
| latest_run | 最後に開始を記録した呼出し。run_id、started_at、finished_at、result |
| last_result | 最後に終了を記録した呼出し。並行実行ではlatest_runと別のrun_idになる場合がある |
| last_progress | 確定したCollectorのphase/checkpoint/資産証拠の変化を観測したset_idと時刻at |
| last_success | 記録処理が確認した保存完了セットのset_idと、公開読戻し検証時刻at。復元試験成功ではない |

時刻はUTC ISO 8601、未記録はnull。schema_versionは1。保存するresultはschema_version/set_id/phase/reason_codeだけで、SQL・資格情報・生の例外・ファイルパスは含めない。これは件数固定のサマリーで、全呼出し履歴や全実行中プロセスの一覧ではない。新しい開始でlatest_runは置き換わるため、過去の終了未記録を永久保存するものではない。

起動は外部Cronプロセスの起動時刻ではなく、プラグインのhandleに入って開始記録を保存した時刻。最終進捗は確定した変更を呼出し終了時に観測した時刻で、長い同期ダンプ内の細かな進捗・heartbeatではない。リース取得、busy、失敗、完了済みの再呼出しでは進捗時刻を更新しない。再呼出しで過去の保存成功を現在時刻へ付け替えない。

終了未記録は「処理中・中断・終了記録失敗」の区別がつかない状態。画面は**終了未記録（状態不明）**と表示し、生存や失敗を断定しない。並行呼出しの遅い完了は、新しいlatest_runを終了済みにしない。last_result・last_progress・last_successはCASで更新する。

## 確認方法

WordPressの「設定 → Recovery Sets」で、Source IDを入力して「実行記録を表示」。セット詳細にも、そのセットのSource IDの実行記録が表示される。セットが作成される前でもSource IDを指定できる。表示対象はセット単独の実行履歴ではなく、同じ保存元の最新サマリー。

```sh
wp ozeki-database-backup-for-s3 recovery-set execution --source-id=<32桁のsource-id> --format=json
```

JSONと管理画面は同じ保存記録を読み、表示中に実行・修復・S3通信・台帳書込みを行わない。画面はmanage_options権限が必要。CLI終了コードは0=読取り成功、2=引数不正、3=記録なし、4=読取り不能。0でも結果がfailed/blocked/unknownの場合があるので、resultを確認する。従来のstatus/latest/observationsのJSON契約は変更しない。

## 記録失敗・既存データ

- 開始記録が壊れている、保存できない、時刻が逆行した場合はバックアップ処理を開始せず、unknown/execution_record_unavailableを返す。破損記録を削除して正常に見せない。
- バックアップ処理後に終了記録を保存できない場合、実際の処理結果に`execution_recording=unavailable`を付けてreportへ返す。S3での成功を取り消したり、勝手に再送したりしない。保存成功時は`execution_recording=recorded`。report自身が失敗しても、その前に保存した実行記録は残る。
- プロセス強制終了時に終了時刻を捏造しない。次回呼出しでは既存Collectorのリース・チェックポイントによる再開制御を継続する。実行記録はロックの代わりではない。
- 既存版の過去の起動時刻は復元しない。記録なしと表示する。既存の完了Collectorを後続呼出しで確認できた場合、公開検証の実時刻を最終成功に取り込む。

予定超過判定、全履歴保存・エクスポート、処理内部のライブ進捗、一般向けリストア入口は今回の追加範囲外。配布済みZIPや実サイトには適用していない。

## 専用環境の検証

[検証集計](recovery-execution-validation.json)：PHP 8.1/8.3の追加24項目ずつ、実WordPress管理画面37項目と実WP-CLI、native/PHP各保存50・復元44項目が合格。前提試験・backend間の件数は合算しない。

実行記録の契約試験は`tests/manual/test-recovery-execution.php`。PHP 8.1/8.3で、開始/終了、進捗、busy、失敗、並行終了、再読込み、時計逆行、CAS競合、ACK喪失、終了記録失敗、成功保持、読取りJSONと破損時の情報非表示を確認する。強制終了相当の未終了記録を使う試験であり、この契約試験自体はOSプロセスkill試験ではない。

実WordPress/MariaDBでは`tools/test-recovery-status-local.py --admin-only`で実hookの失敗記録、実WP-CLI JSON、管理画面の表示・権限・DB非変更を確認する。画面renderの試験であり、新しいブラウザ受入試験とは数えない。

`tools/test-wordpress-recovery-local.sh`ではnative/PHP双方の実ダンプ→保存→独立検証→隔離復元を実行し、保存成功・進捗の記録と完了済み再呼出しでの日時保持を確認する。転送先はディスク上のS3代替で、今回のコードを実S3へ投入した試験ではない。
