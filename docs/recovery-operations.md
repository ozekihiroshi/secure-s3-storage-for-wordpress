# Recovery Set 運用手順（開発版）

起動・最終進捗・終了結果・最終保存成功は[実行記録の手順](recovery-execution-status.md)を参照。設定 → Recovery SetsのSource ID検索、または`recovery-set execution --source-id=<id> --format=json`で読取り確認できる。終了未記録は生存確認ではなく状態不明として扱う。

有効・停止・猶予を含む[予定超過の判定](recovery-schedule-status.md)も同じ画面と`recovery-set schedule-status`で確認できる。初回予定の明示設定が必要で、未設定は正常扱いしない。予定判定の停止と実Cronの停止は別操作。

2026-10-19。対象は`codex/recovery-operator-plan`のソース。READMEに記載された公開版0.2.1へ、この手順の機能が追加されたという意味ではない。配布候補の作成・公開は別作業。[公開前の残課題](recovery-operator-release-readiness.md)も確認する。

## 使い始める前に

Recovery SetはDBとuploadsを対応付けた記録で、通常のDBバックアップ履歴とは別。既存の個別バックアップからセットは自動生成しない。セットがない場合、管理画面に一覧がないことは異常ではない。

- 読取りCLI・管理画面・明示cleanupは実装済み。Cron作成は開発者向けの接続口で、設定画面でONにする機能や一般向け`recovery-set create`はまだない。
- 本ブランチのプラグインとComposer依存をそろえ、専用WordPressで先に確認する。Gitのソース取得だけでは依存の配置は保証されない。本体有効化の[試験手順](recovery-admin-browser.md)は開発者向けfixtureであり、本番導入スクリプトではない。
- CLIは対象WordPressを読み込めるサーバー上で実行する。以下はLinux/WSLのシェル例。WordPressディレクトリで、対象workspaceと同じ所有者のユーザーを使う。管理画面の`manage_options`検査と、OSのCLI実行権限は別。
- source ID、set ID、保存先、private workspaceの親、実行担当、障害連絡先を運用台帳に記録する。秘密鍵やAWSアクセスキーはこの台帳やコマンド例へ入れない。
- DBとuploadsの同一時点性は保証しない。`uncoordinated`を整合済みと解釈しない。テーマ・プラグイン・core・wp-config.php・サーバー設定等は対象外で、別途確保する。

## 1. 状態を確認する

管理者でWordPressへログインし、**設定 → Recovery Sets**を開く。セットIDをクリックすると詳細を表示する。IDが分かっている場合は入力して「状態を表示」を押す。読取り専用で、作成・再検証・削除は実行されない。

| 表示 | 判断すること |
| --- | --- |
| 処理状態 / 資産充足 | `failed`と`partial`は別の軸。保存completeは復元成功を意味しない |
| DB/mediaのセットID | 要求profileに含まれる資産が同じセットに結び付いているか。DB単独profileのmediaなしは不足ではない |
| Manifest公開検証 | 保存時に公開・読戻し検証した証拠。現在S3に存在するかの再確認ではない |
| 取得可否・復元試験 | 現在の評価と記録された結果、検証時刻、鮮度を分けて読む。既定の鮮度は24時間 |
| Cleanupの記録 | 台帳上の状態。`needs_reconciliation`は照合が必要で、処理が現在動いているという意味ではない |

一覧は100件まで。破損や上限の警告が出たら完全な一覧と判断しない。購読者は表示できず、未ログインではログインが必要。[画面仕様](recovery-admin-readonly.md)。

CLIは次の例を使う。値は実際の32桁小文字16進IDへ置き換える（source IDとset IDを混同しない）。

```sh
SOURCE_ID='実際のsource IDへ置換'
SET_ID='実際のset IDへ置換'
wp ozeki-database-backup-for-s3 recovery-set latest --source-id="$SOURCE_ID" --format=json
wp ozeki-database-backup-for-s3 recovery-set status "$SET_ID" --format=json
wp ozeki-database-backup-for-s3 recovery-set observations "$SET_ID" --max-age=86400 --format=json
```

`latest_attempt`と`latest_complete`の両方を見る。最新失敗を過去の成功で隠さない。`index_complete=false`なら両者を確定できずnullになる。`status`は保存時の記録、後日の観測は`observations`で読む。これらはS3への問い合わせや復元試験を起動しない。

機械処理では終了コードだけでバックアップ成功を判定しない。読取り系の0は正常読取りであり、対象がfailedでも0になり得る。JSONの`schema_version`、`phase`、`completeness`、`index_complete`/`aggregation_complete`、`state`、`freshness`、`reason_code`を確認する。2は要求不正、3は対象なし、4は読取り失敗や不完全。WP-CLIがdispatch前に必須引数等を拒否した場合は標準エラーとなり、JSONを前提にしない。

詳しくは[status](recovery-set-status.md)と[索引・観測](recovery-catalog-observations.md)。取得日時と元の検証日時を混ぜず、JSONと終了コードを運用記録に残す。

## 2. Cronから作成する（開発者による接続が必要）

**プラグインを有効化しただけではRecovery Set作成は始まらない。**「Ozeki Database Backup for S3」の日次設定は従来のDB単独バックアップ用。新しいCronと自動置換しない。

1. [Cron接続仕様](recovery-cron-entry.md)に沿ってsource/profile/保存先/backend/環境情報を固定し、private workspaceとロック付きアダプターfactoryを用意する。書込みの整合性確保、AWS権限・容量は接続側で設計する。
2. 信頼したbootstrap（MUプラグイン等）で、各WordPress起動時に`WordPressRecoverySchedule::register($wpdb, $configuration, $adapters, $report)`を登録する。reportには実行時刻・set ID・phase・reason_codeを記録する処理を接続する。秘密情報をログへ出さない。
3. 戻されたhookを、まず専用環境で単発実行する。以下の`HOOK`は開発者が登録済みの正確な名前へ置き換える。未接続のhookを予約してもバックアップにはならない。

```sh
HOOK='odbfs3_recovery_tick_実際のsource IDへ置換'
wp cron event schedule "$HOOK" now
wp cron event run "$HOOK"
```

4. reportとstatusを確認し、同じhookの呼出しを繰り返してmanifest公開検証まで進める。1回はCollectorの1処理であり、**1日1回の起動だけでは日次セットを完成できない**。運用の反復間隔と外部スケジューラーはデータ量に合わせて設計・確認する。単発を上の2コマンドで試す手順と、定期運用の設定は別。
5. 日次枠はUTC（日本時間09:00切替）。未完了なら翌日も同じセットを先に進める。過去日を埋めるセットは作らない。受付やbusyは完了ではない。
6. 停止時は、まず当該hookを予約する外部スケジューラーを停止し、そのhookだけの予定イベントを削除する。WordPressのイベント削除は`wp cron event delete "$HOOK"`。実行中処理の強制終了、台帳やworkspaceの削除はしない。再開は同じ設定・同じsourceで予約を戻し、既存セットの続きから確認する。

failedは翌日も止まり、設定変更はblocked、記録欠落はunknownとなる。source ID変更や台帳削除で迂回しない。承認付きリセット/設定移行UIは未実装なので、証拠を保持して開発者が確認する。60秒リースはダンプ処理の強制終了タイマーではない。大規模・長時間処理の受入は別途必要。

Cron自体の起動漏れ、report停止、容量増加は外部運用側で監視する。Resilienceのwatchdogがこの製品へ自動導入されるわけではない。

## 3. ローカルworkspaceを整理する

必要な独立取得・復元確認を済ませ、ローカルSQL/gzipを手放してよいセットだけを対象にする。保存completeだけでは現在の取得可否を保証しない。

```sh
PARENT='/実際のprivate-workspace親ディレクトリ'
wp ozeki-database-backup-for-s3 recovery-set cleanup-plan "$SET_ID" --workspace-parent="$PARENT" --format=json
```

`inspection_complete`、`protection_reasons`、各entryの`candidate/protected`と理由、`candidate_bytes`を確認する。調査成功0でも保護対象があり得る。dry-runは内容hashや現在のS3取得可否を確認せず、削除許可ではない。子ディレクトリ容量は未調査で、nullを0と扱わない。

実行する場合は同じIDを2回指定する。

```sh
wp ozeki-database-backup-for-s3 recovery-set cleanup "$SET_ID" \
  --workspace-parent="$PARENT" --confirm-set="$SET_ID" --format=json
```

対象は完了セットの`database.sql`と`database.sql.gz`だけ。S3・メディア子ディレクトリ・manifest関連の証拠・台帳・ロックは残る。各ファイル1GiBまでの照合上限があり、自動/再帰削除やforceはない。実行直前にロック、同一性、サイズ/hashを再検証する。

結果`complete`/終了0を確認する。`blocked`/`incomplete`は終了4、確認ID等の不正は2（WP-CLIの事前拒否は1）。途中失敗は原因を解消し、**同じID・同じ親・同じコマンド**で照合再開する。台帳を消さず、戻った同名ファイルを手作業で「処理済み」にしない。管理画面のcleanup状態と、再度dry-runの候補数も確認する。

[計画表示](recovery-cleanup-plan.md)・[実行/途中再開](recovery-cleanup-execution.md)に保護条件と台帳の詳細がある。これはS3の保存期間・削除規則とは別の操作。

## 4. 異常時に残す記録

| 状況 | 対応 |
| --- | --- |
| 最新試行failed、過去completeあり | 失敗のset ID/reasonを調査し、過去completeの現在の取得可否と復元可否を別途確認 |
| unknown・読めない記録・索引上限 | 成功へ置換せず、CLI出力・ログ・元の台帳を保持。対象IDでの確認と原因調査 |
| 観測stale/なし | 現在の成功を主張しない。対応する検証・復元処理を実施する。表示の再読込みだけでは更新されない |
| 観測登録delivery_pending | 同じ実行記録から配送のみ再開。検証・復元自体をもう一度executeしない |
| 観測runningのまま停止 | 復元先と進捗を調べて再開判断。成功観測を補完しない |
| cleanup照合待ち/容量不足 | 証拠を保持して原因調査。未完了workspaceを自動削除しない |

[観測自動登録](automatic-recovery-observations.md)は専用検証・復元処理への接続基盤であり、一般利用者用の復元CLI・定期verify・遠隔配送は未提供。サイト内DBにある索引・観測は独立署名された証拠ではない。侵害後の独立復旧や削除耐性を目的とするOzeki WordPress Resilienceとは役割を分ける。
