# DBバックアッププラグインの本番更新

2026-10-17、wp.ceri.linkの旧Secure S3 Storage 0.1.0からOzeki Database Backup for S3 0.2.1へ切り替えた。設定・履歴20件・既存の日次Cron登録を保持し、新版による実DBのS3保存と整合性確認に成功した。会社サイトにはプラグインが未導入であり、変更していない。

## 適用物と検証

`build-release.sh` による依存namespace分離済みZIPを使用した。

- ZIP: `build/ozeki-database-backup-for-s3-0.2.1.zip`
- SHA-256: `458af19e6662b08651d6a13ec6ff76687637c6434aefd37aa3caaf1f574590d5`
- 整合性44項目、履歴表示、private一時ファイル検査、media upload 50項目が合格。
- native/PHP両方式で1,007行と空テーブルの隔離復元が合格。
- ビルド時のPHP構文・依存分離・scoped release検査が合格。
- トップとログインページはHTTP 200。実配置とZIP展開物はディレクトリ比較で一致。

WordPressをロードしない単独実行では旧schedule-managerテストは実行できなかったため、実サイトのCron登録の前後比較で今回の引き継ぎを確認した。旧固定キー型S3手動テストはローカル実行でAccessDeniedとなり、合格には数えない。実S3の専用fixture試験と、下記の承認済み実DB確認は別途成功した。Plugin Check全体と次回の自動Cron実行はこの適用では未検証。

## 設定とバックアップ

対象コンテナは `wp-rescue`。新たな有効プラグインは `ozeki-database-backup-for-s3/ozeki-database-backup-for-s3.php`。旧 `secure-s3-storage/secure-s3-storage.php` は停止状態で残している。

切り替え前後の設定・履歴はシリアライズ値のSHA-256比較で一致した。Cronフック `secure_s3_storage_database_backup_cron` と登録時刻も一致した。active_pluginsの旧basenameのみを新basenameへ置き換え、二つの同名クラスを同じリクエストでロードせず、定期登録を削除するdeactivation処理も呼ばない移行とした。

サーバ内の退避先は `/home/ubuntu/docker/wp-rescue/backups/odbfs3-20261017/`。ディレクトリ権限は0700。

- `before.sql`: 更新前DB、374,658 bytes。SHA-256 `abf7ba746c2cd56d31518dbed00deafdb85a1b182b2b0f01675b11dcfb9d824e`。
- `options.serialized`: 有効プラグイン・設定・履歴・Cronの退避。秘密情報を含み得るため、Gitやチャットへ掲載しない。
- `old-plugin.tar.gz`: 旧プラグインの退避。
- `after-backup.json`: 新版での保存結果メタデータ。

本番DBのS3送信について追加の明示承認を得た。従来設定のバケット `ceri-secure-s3-storage-test`、prefix `wordpress-test/` を変更せず1回実行した。バケット名にはtestが含まれるが、今回のオブジェクトは実データであり合成fixtureとして扱ってはいけない。

保存キーは `wordpress-test/backups/database/2026/10/17/db-wp_rescue-20261017-044539-ff6574f9fd1f7691d04554469fb46368.sql.gz`。58,631 bytes、SHA-256 `72ea83d529fdb062979032130ca54ef1fd66b559fa8f379325401d29617115e1`。元SQLとgzip展開の一致、PutObject、HeadObjectのサイズ・SHA-256照合まで成功した。本番DBへの復元は実行していない。

この確認はDatabaseBackupServiceを直接呼び、保持数による既存バックアップ削除と履歴追加を行わなかった。したがって管理画面の履歴20件は更新前のままで、確認結果は上記JSONに保存している。通常の次回バックアップでは新しい検証情報が履歴に追加される。

## 戻す場合

旧プラグインディレクトリと退避ZIPを残しているため、必要時は現在の状態を別途退避し、新版をロードしない処理で有効プラグインのbasenameだけを旧版へ戻す。両版の同時有効化は禁止。旧版をWordPress管理画面の「削除」でアンインストールすると共有設定・履歴を消す可能性があるため、停止したまま保持する。通常のコード差し戻しでDB全体を復元してはいけない。DB復元は、その間の投稿・設定変更を失うため別途判断する。

WordPress.orgへの公開・SVN更新・GitHub Release作成は今回の本番更新とは別工程。SSE-KMS・最小権限・大容量負荷の残課題は [整合性検証](database-integrity-verification.md) を参照。
