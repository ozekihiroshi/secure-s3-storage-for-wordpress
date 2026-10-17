# 実S3によるDBバックアップ復元試験

2026-10-17、開発中コードを用い、合成SQLのS3保存から隔離MariaDBへの復元までnative/PHP両方式で成功した。本番サイト・既存バックアップ・公開版プラグイン・IAM設定は変更していない。

## 試験範囲

- EC2 `ssh community` のホストPHP 8.1.2と既存IAMロールを使用。WordPressは起動・読取りとも不要。
- バケット `ceri-secure-s3-storage-test`、リージョン `ap-northeast-1`。
- 専用prefix `wordpress-test/database-integrity-test/9f1bcb05a424dd3311466e1c/`。
- 転送物はソース・Composer依存・合成SQLのみ。ユーザーの明示許可を得て転送した。
- 復元先はローカルMariaDB 11.8の新規コンテナ。localhost限定ポート、512MiB、1CPU、tmpfsを使用。本番DB接続は行わない。

## 結果

| 項目 | native | PHP |
| --- | --- | --- |
| 元SQLサイズ | 75,470 bytes | 79,312 bytes |
| gzipサイズ | 11,942 bytes | 11,752 bytes |
| PutObjectとHeadのサイズ・SHA-256照合 | 合格 | 合格 |
| GetObjectと独立SHA-256照合 | 合格 | 合格 |
| gzip展開と元SQL照合 | 合格 | 合格 |
| 同じキーへの再送 | HTTP 412で拒否 | HTTP 412で拒否 |
| 空DBへのimportとschema・全行比較 | 合格 | 合格 |

各方式でUnicode・NULL・バイナリ・シリアライズ値を含む1,007行と空テーブルを確認した。元データは取得SQLからではなく、同じ決定的fixture生成関数で独立に再作成した。S3オブジェクトの暗号化応答は `AES256`。

HeadBucket・PutObject・HeadObject・GetObjectが既存ロールで通ったことを確認した。最小権限ポリシーの十分性やSSE-KMSは検証していない。GetBucketEncryptionは権限不足だったが、権限は変更していない。

## 実通信で判明した修正

最初の取得は、`stream=true` のseek不能なHTTP応答をAWS SDKのchecksum処理が巻き戻そうとして失敗した。保存済みオブジェクトのサイズ・SHA-256は正しく、復元成功とは扱わなかった。

`downloadVerified()` を、新規0600ファイルのseek可能なsinkへ取得する形に修正した。書込み時に期待サイズを超えるデータを拒否し、SDKのchecksum検証を無効化せず、取得後にも信頼できる元記録とサイズ・SHA-256を照合する。不完全ファイルの除去と既存ファイルの保護を維持した。

模擬HTTPもseek不能な応答を再現するよう補強し、過大な本文の拒否を追加した。修正後の整合性検査はPHP 8.1と8.3の両方で44項目合格。

## 証跡と残留資材

ローカル `build/db-s3-20261017-a1/` に `s3-result.json`、`restore-result.json`、取得した合成SQLを保持する。このbuild領域はGit管理外。実S3照合完了時刻は `2026-10-17T04:34:58+00:00`。

EC2の `/tmp/odbfs3-db-s3.felJCpyz/` は今回専用の検証資材。失敗時のgzipは `failed-attempt-1/native.sql.gz` に退避した。S3には初回失敗時の保存1個と成功試験の2個、合計35,636 bytesの合成gzipを保持する。既存オブジェクトは削除していない。将来の片付けでは、この専用パスとprefixの中身を確認してから限定して削除する。

復元用コンテナ `20664c1fae5e9b853530cd83293350892007d50d40471fdc3a50738905fb7481` は試験後に削除済み。tmpfsの合成DBも削除されたが、SQLと結果JSONから再試験できる。

EC2へ転送した修正ファイルはローカルとSHA-256一致を確認した。

- `src/Aws/S3Storage.php`: `c5cac35638ba88aa98305ec69bc68419d6284bb3119107b30eddb3bb95822b5a`
- `src/Backup/SecureTemporaryFile.php`: `f9262ece189605dbe70bbe93fe9fc8dafd099874dc6f6c212f4341da1be4ca2a`

## 再試験と残る確認

`tools/test-database-s3-restore.php prepare` で新しい専用fixtureを作り、許可されたEC2専用ディレクトリへ転送する。`tools/test-database-s3-roundtrip.php` で保存・取得・展開後、結果JSONとSQLをローカルへ戻し、`verify` で空の専用DBへimportする。各実行には新しいfixtureディレクトリと専用DBを使う。元の一時DBを破棄済みの場合だけ、空の使い捨てDB環境で `rehydrate` により参照fixtureを再作成できる。

今回の成功は小規模な合成DBの復元に限る。大容量・低メモリ環境の負荷、SSE-KMS、最小権限、WordPress全体の復旧、DB/mediaの時点一致は後続検証。公開ZIP作成・リモートCI・commit・push・本番適用はこの試験では実施していない。
