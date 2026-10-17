# DBバックアップの整合性検証

## 実装範囲

2026-10-17の変更。0.2.1としてwp.ceri.linkへ適用済み。WordPress.orgの公開版更新とは別工程。適用・退避先は [本番更新記録](production-update-2026-10-17.md) を参照。
DBバックアップは、圧縮前後の一致とS3上の整合性が確認できた場合にのみ成功を返す。
独立した取得・展開・隔離DBへのimportはテストとして実装しており、通常のバックアップごとに本番DBへ復元する機能ではない。

### 通常のバックアップ

1. SQL dumpのサイズとSHA-256を計算する。
2. gzipを生成し、終了処理の成功を確認する。
3. gzipを逐次展開して元SQLのサイズ・SHA-256と比較する。CRC不一致、途中切断、後続データ、複数gzip member、空の展開結果を拒否する。
4. 圧縮済みファイルのSHA-256を計算し、`PutObject` に `ChecksumSHA256` と `IfNoneMatch: *` を指定する。
5. `HeadObject(ChecksumMode=ENABLED)` のサイズ・SHA-256を照合する。欠落や不一致は失敗扱い。
6. 成功結果と履歴に圧縮済みファイルのSHA-256を保持する。履歴の検証段階は `remote_integrity`。

DBキーにランダムな128bit識別子を追加する。従来の `db-...sql.gz` 形式の外形は保ち、新旧のキーをretentionが認識する。
検証失敗はサービスから例外として返り、CLI/Cronの成功後retentionへ進まない。アップロード後のHead失敗ではS3上にファイルが残る場合があるが、成功とは報告しない。自動削除や自動再送による上書きはしない。

### 履歴と表示

UI/CLI/Cronの成功履歴に `sha256` と `verification` を追加した。既存履歴の移行は不要で、検証情報のない履歴は未検証として扱う。
管理画面とCLIには、S3整合性確認と復元試験を区別した説明を表示する。
履歴は従来どおり最新20件であり、永続的なRecovery Manifestの代わりではない。DBとmediaの関連付けも今回の対象外。

## 独立取得と展開

`S3Storage::downloadVerified(bucket, key, destination, expectedSize, expectedSha256)` は、信頼できるバックアップ記録のサイズ・SHA-256を指定して、新しい0600ファイルへ取得する。
レスポンスの申告だけでなく、実際に受け取った全バイトを数え、ハッシュを計算する。不一致や途中失敗では、この呼出しで作成した不完全ファイルだけを除去する。既存ファイルには上書きしない。
SDK自身のchecksum照合も維持するため、取得先にはseek可能な専用ファイルを渡す。書込み時点で期待サイズを上限とし、応答を無制限にメモリへ保持しない。

`GzipVerifier::verify(path, maximumBytes, destination)` は新しいSQLファイルへ展開できる。展開上限を必須とし、既存ファイルを上書きせず、検証失敗時は新規の不完全出力を除去する。
通常バックアップの検証では元SQLサイズを上限にする。復元試験ではfixture用の64MiB上限を使う。大きな実バックアップの試験では、確認済みの資材サイズに応じて上限を別途決める。

これらは内部APIであり、利用者向けの本番復元ボタンや汎用verify CLIはまだ追加していない。SQLの取り込み先は必ず隔離環境とする。

## 再現テスト

リポジトリルートで実行する。

```bash
php -d memory_limit=32M tests/manual/test-database-integrity.php
php tests/manual/test-database-history.php
bash tools/test-database-recovery-local.sh
```

最初のテストは実AWS SDKとローカルHTTPハンドラーを使う。AWSへ通信せず、固定のダミー認証情報だけを使う。
gzipの不正データ・展開上限、S3サイズ/checksumの不一致・欠落、上書き拒否、取得破損・切断、旧履歴、新旧retentionキーを確認する。

復元スクリプトには、PHP mysqli/zlib、MariaDBクライアント、Dockerとローカルの `mariadb:11.8` イメージが必要。
既存DBコンテナは使わず、ランダム名・tmpfsデータ領域の一時コンテナを起動する。接続ポートはlocalhostだけに動的割当てし、終了時に作成したコンテナだけを削除する。
停止不能な強制終了では残留する可能性があるため、`odbfs3.test=database-recovery` ラベルと作成IDで確認する。広い名前パターンで既存コンテナを削除しない。

テストは以下を両backendで実行する。

- 新規の専用source/restore DBを作成する。
- Unicode・NULL・バイナリ・シリアライズ値を含む1,007行と空テーブルを作る。
- 製品の `DatabaseBackupService` を使ってdump・圧縮・S3送信/Head照合を行う（S3のみ模擬通信）。
- S3 checksum不一致と「正しいgzipだが内容が異なるSQL」を注入し、成功しないことを確認する。後者はS3への送信前に拒否され、SQL/gzip一時ファイルも片付くことを確認する。
- 独立取得・checksum照合・展開・MariaDB importを実施する。
- 元DBと復元DBのテーブル一覧・CREATE TABLE・全行のfingerprintを比較する。

`test-database-restore.php` の従来のローカルSQLモードは残し、`RESTORE_TEST_TRANSPORT=offline-s3` で新経路を選ぶ。
DB名の制限は安全策であり、既存環境に向けて実行する根拠にはしない。原則として上記の一時コンテナ用スクリプトを使う。

## 実行結果と残る検証

2026-10-17、WSL PHP 8.3.6で整合性44項目合格。ローカルPHP8.1コンテナでも同じ44項目が合格した。
MariaDB11.8の一時コンテナではnative/PHP両方式とも、1,007行・空テーブル・schemaの一致を確認した。試験用コンテナは終了時に削除した。
CIにPHP8.1/8.3での整合性・履歴表示・隔離復元テストを追加したが、リモートCIはまだ実行していない。

実AWSの専用prefixでnative/PHP両方式の保存・Head照合・取得・展開・隔離importが成功した。詳細は [実S3復元試験](aws-database-integrity-test-2026-10-17.md)。合成データとSSE-S3での試験であり、SSE-KMSと最小権限ポリシーでは未検証。
新たにHeadでchecksumを取得するため、従来の書込みだけの権限では成功しなくなる。読取り・checksum取得に必要な権限、KMS利用時の権限を公開前に確認する。

追加の全ファイル読取り・展開・Headリクエストがあるため、大きな実DBでの所要時間と一時領域使用量も確認が必要。
DBバックアップは引き続き単一PutObjectであり、DBのmultipart化・非同期化は今回追加していない。
完全なWordPress復旧、DB/mediaの時点一致、Recovery Manifest、復元試験の永続証跡は後続工程。
