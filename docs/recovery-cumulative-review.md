# 累積レビューと最終ZIP受入（2026-10-19）

対象は`codex/recovery-operator-plan`の`627e47c`以降。共通部品採用ブランチには変更していない。レビューは状態表示・観測・Cron・cleanup・WordPress登録/CAS・配布手順を対象に実施した。全ての障害や攻撃を排除する保証ではない。

## レビューで修正した点

1. **配布時のWP-CLI関数の誤変換**：PHP-Scoperが`WP_CLI\Utils\format_items`へvendor prefixを付け、Recovery Setの表形式CLIが配布物で動かなくなる。既存ビルドの検査がこれを検出してZIP完成前に停止した。`scoper.inc.php`でWP_CLI名前空間を除外し、最終ZIPを再生成。実ZIPの表/JSONを受入試験に追加した。修正は`356bf81`。
2. **配布説明の不一致**：同梱readme.txtにadmin/CLI/Cron入口が未実装という古い記述が残っていた。現在の入口、開発者接続、読取り専用性、cleanup制限、未提供の一般作成/復元を記載した。

読取り側の権限・エスケープ・上限/破損判定、Cronの固定intent/CAS/失敗時停止、cleanupの排他・両ファイルhash確認・削除予定先行記録・再作成ファイル拒否、観測の配送だけの再試行を確認した。この範囲で、上記修正後に新たな公開阻害となるコード上の問題は確認していない。既知制限は下記に残す。

## ZIPと試験の追跡

候補ZIPのSHA-256：`34315c718ac34a970f64dcc5353c1208ec3b40c272be803bdfae127217200d43`。
ソースの配布関連ファイルは`356bf81`。試験スクリプトとこの記録の追加はZIPに含まない。バージョンは0.3.0、**ローカル検証候補であり新しい公開リリースではない**。

`build-release.sh`のComposer lockインストール・PHP-Scoper・構文・AWS SDK衝突検査を通した。PHP8.1でも同梱依存の衝突検査が合格。ZIPは単一plugin root、3889ファイル。インストール後に全ファイルのSHA-256と一覧をZIPと照合し、ソース用vendorを混在させていないことを確認する。公開に使う場合は、このhashのZIPと検証集計を一緒に扱う。

## 確定結果

新規導入、手元の旧0.2.1からの更新、既存0.3.0からの更新の3ケースすべて合格。各ケースで同版置換後の未処理台帳保持、読取り表示、停止/再有効化も合格した。最終ZIPのnative/PHP往復はそれぞれ保存47・復元44チェックが合格。PHP8.1/8.3の契約スクリプト11本ずつも合格した。確認件数は重複を含むため合算しない。

今回の試験コンテナ/ボリューム/ネットワークは終了時に削除済み。ZIP・原本ログ・集計は残す。

## 再現方法

Linux/WSLでDocker、ローカルのWordPress php8.3-apache / cli-php8.3とMariaDB11.8を用意し、リポジトリで実行する。

```sh
bash build-release.sh
python3 tools/test-recovery-zip-install.py \
  build/ozeki-database-backup-for-s3-0.3.0.zip \
  /path/to/previous-0.2.1.zip /path/to/previous-0.3.0.zip
```

ZIP導入はWP-CLIのinstaller、更新はWordPress `Plugin_Upgrader::upgrade()`へローカルZIPを渡して行う。更新APIはプラグインをsilent deactivateするため、別プロセスで再有効化して確認する。画面のZIPアップロードボタンや自動更新通知の操作試験ではない。旧版は今回手元にあった0.2.1と0.3.0のZIPで、現在オンライン配布されている内容との一致を主張しない。

生成fixtureだけを内部ネットワークの使い捨てDB/volumeへ作る。HTTP外部通信・WP-Cron自動実行を止め、実S3・実メール・本番データを使わない。テストは終了時に自分のコンテナ/volume/networkを削除する。既存WordPressにfixture PHPを実行しない。

確認内容は、旧設定/履歴とCron、既存0.3.0の完了Collector/workspace、候補版で作った失敗セット・観測・配送待ち・未完了schedule・cleanup照合待ち。同版置換後に内容が保持され、CLIと管理画面renderが読めることを調べる。ソースfallbackを隠さないため、主要クラスのReflectionから読み込み元がインストール済みplugin内であることも確認する。

停止/再有効化では従来DB/media Cronを取り除く既存の仕様がある。データの不変性と、日次DBイベントが1件再登録されることを確認し、実行予定時刻の完全一致を要求しない。Recovery Setの外部スケジュールは別管理。

同じZIPでnative/PHP両方式の取得→公開（disk S3 double）→DB一時cleanup→保存元DB破棄→隔離復元も検証する。関連試験コードを一時展開先へ加えるが、製品src/vendorはZIPのものを使う。これは実S3往復試験ではない。

## 環境・試験側の記録

新規導入が合格した後、並行して終了した復元試験のDockerネットワークが一覧取得とinspectの間に消え、次の更新試験の準備が止まった。元ログを保持し、ネットワーク一覧を再取得する限定リトライを試験側に追加した。製品不具合・更新時データ喪失とは分類しない。生成環境の破棄後に、未実施の旧版更新だけを再開した。

## 残る公開・運用判断

最終結果は`recovery-zip-validation.json`を参照。一般向けcreate/restore、失敗reset/設定移行、100件超の索引、長期Cron監視、最終版の実S3検証は今回追加しない。WordPress最低対応版・PHP全組合せや他プラグインとの全互換性をこの試験で保証しない。

新規導入/更新/再有効化とアンインストールは別。現行アンインストールはRecovery SetのCollector・観測・schedule・cleanup記録を自動消去しない。証拠の保存と最終削除/エクスポート方針は公開説明・次段階の運用設計で扱う。未完了証拠を黙って削除する修正は今回行わない。

公開時の版番号・更新告知も別途確定する。今回の0.3.0候補は、同じ版番号の既存サイトへ自動更新通知が出ることを保証しない。GitHubへのpush、共通化PRのマージ、WordPress.org更新、実サイト適用は別工程。継続観測中のResilienceプロジェクトの合否と混同しない。
