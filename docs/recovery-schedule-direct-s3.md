# 予定判定設定を含む最終ZIPの実ワーカーS3往復

2026-10-20 JST、run `f5cd9c1e2fa2`。最終ZIP SHA-256 `4a92b0e4ff85f16b34254f41ba54ea626ccaf050f82fd77930e0d6d8b4b80797`を変更せず使用。native/PHP双方で保存48項目・隔離復元44項目が合格した。件数は方式間で合算しない。

| 方式 | 独立取得資産 | 合計bytes | 復元観測 |
| --- | --- | --- | --- |
| native | 8 | 15,852 | passed |
| PHP | 8 | 15,306 | passed |

WordPress 7.0 / PHP 8.3 / MariaDB 11.8の専用EC2 Docker環境、生成データを使用。既存の試験バケット`ceri-secure-s3-storage-test`の`wordpress-test/direct-worker/f5cd9c1e2fa2/{native,php}/`へ保存した。既存サイト・DB・FTP・IAM・共通部品は変更していない。

## 確認した経路

WordPress上で登録したhookから製品のRecoverySetCronHandler、Collector、Pipeline/Publisher、MediaS3Clientを実行し、実S3へ直接保存した。ディスク代替への保存後の再公開やmanifest参照の書換えは行っていない。

保存元DBコンテナを破棄した後、空の取得先を持つ別プロセスでS3から全資産を取得。サイズ・SHA-256・manifestの一致を確認した。外部通信できない内部Dockerネットワーク上の別DB・別サイトへ復元し、投稿・画像のHTTP表示、シリアライズ設定、改ざん・欠損拒否、復元観測passedを確認した。

製品src/vendorの3,889ファイルはZIPと一致し、実行前後でも不変。DBは192MiB/0.35CPU、ワーカーは256MiB/0.4CPUに制限した。[機械可読な結果](recovery-schedule-direct-s3-validation.json)と、`release-readiness/schedule-settings-direct-s3/`のrun.log、evidence.tar.gz、validation.json、cleanup.jsonに原本を保存する。S3生成資産と取得証拠を保持し、専用コンテナ・ネットワークと再生成可能な一時コード・サイトを片付ける。

## 再実行・限界

[前回の実ワーカー試験](recovery-direct-worker-s3.md)と同じ経路。runnerは第2引数で期待するZIP SHA-256を指定できるようにした。指定がない従来の呼出しは前回ZIPの固定値を維持し、いずれも不一致なら実行前に停止する。

今回のhook起動は明示的な試験起動。自動wp-cron.php、予定判定の複数日運転、一般向け復元UI/CLIの合格とは扱わない。独立取得は別プロセスだが保存・取得の認証チェーンは同じ。S3 Version IDはnull、AES256応答を確認。Object Lock・独立署名・ロール分離の試験ではない。

**公開済み0.3.0とこのZIPは別物。** 同じヘッダー番号を使っていた問題は[版番号の整理](release-version-reconciliation.md)に記録した。公開版を上書きせず、GitHubの公開済み版のタグ・Release補完と、改善版の次版採番を分ける。今回はGitHub/WordPress.orgへの公開操作を行っていない。
