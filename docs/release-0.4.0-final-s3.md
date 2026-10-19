# 0.4.0固定ZIPの実WordPressワーカーS3往復

2026-10-20 JST、run `e2507a245a9c`。公開前の固定ZIP SHA-256 `4876c3971ebb5d7d4adf829a755c574216f22a20b5d6be9a74552ca00721fd62`を変更せず使用した。native/PHP双方で実S3保存・独立取得・隔離復元が成功。

| 方式 | 保存チェック | 復元チェック | 取得資産 | bytes |
| --- | ---: | ---: | ---: | ---: |
| native | 48 | 44 | 8 | 15,839 |
| PHP | 48 | 44 | 8 | 15,311 |

チェック件数は方式間で合算しない。WordPress 7.0 / PHP 8.3 / MariaDB 11.8の専用EC2 Docker環境で生成データだけを使用。DBは192MiB/0.35CPU、ワーカーは256MiB/0.4CPUに制限。実験バケット `ceri-secure-s3-storage-test` の `wordpress-test/direct-worker/e2507a245a9c/{native,php}/` に保存した。

WordPressに登録したhookから製品のRecoverySetCronHandler→Collector→Pipeline/Publisher→MediaS3Clientを実行。代替ストレージからの再公開やmanifest参照の書換えはない。保存元DBを破棄した後、別プロセス・空の取得先へ全資産を実S3から取得し、size/SHA-256/manifest一致を検証。外部通信できない内部ネットワークの別DB・サイトへ復元し、投稿・画像4件のHTTP表示、シリアライズ設定、欠損/改ざん拒否、復元観測passedを確認した。

src/vendor全3,889ファイルはZIPと一致し実行前後も不変。ZIP自体も変更していない。公開済み0.3.0、共通部品、既存サイト、FTP/MySQL、IAMを変更していない。

[結果JSON](release-0.4.0-final-s3-validation.json)。原本は `release-readiness/040-final-direct-s3/` のrun.log、evidence.tar.gz、validation.json、cleanup.json。S3生成資産と取得証拠は保持し、今回の専用コンテナ・ネットワーク・再生成可能な一時コード/サイトを片付ける。

## 検証範囲の限界

明示hook起動のため、自動WP-Cron、長期日次運転、一般向け復元UIの受入とは別。取得/復元に試験補助コードを使用するが、保存は製品の実ワーカー。保存と取得は同じEC2認証チェーンを使う。Version IDはnull、AES256応答あり。Object Lock・独立署名・役割分離の試験ではない。WordPress本体・テーマは別途用意した。今回の結果をResilienceの継続試験に合算しない。

これは固定ZIPの公開前検証であり、PRのマージ・Release・WordPress.org公開は行っていない。
