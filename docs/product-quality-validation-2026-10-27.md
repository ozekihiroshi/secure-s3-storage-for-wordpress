# 2026-10-27 限定改善の検証記録

対象：`origin/main` / `v0.4.0` (`dafa663`) を基点にした
`codex/backup-product-quality` の保持保護修正。環境はWSL Linux、PHP 8.3.6、
リポジトリのcomposer.lock（AWS SDK 3.392.1）。lockと依存の版は変更していない。

## 今回実行したもの

| 試験 | 結果 |
| --- | --- |
| `test-retention-safety.php` | **26 checks PASS**。正常複数ページ、削除前の全保持枠HEAD、旧形式とrandom suffix、legacy証拠不足、サイズ/hash/metadata欠落・不正・読取り拒否、token欠落/循環、重複key、不完全一覧、対象外キーの保護、空/入れ子prefix |
| `test-database-integrity.php` | **47 checks PASS**。gzip破損・展開上限、checksum/サイズ不一致・欠落、上書き拒否、独立download、旧履歴、実SDKによる元digestメタデータの送信・読取り |
| `test-database-history.php` | PASS。新旧検証状態、不正証拠、出力escape |
| `test-retention-setting.php` | PASS。keep countの従来仕様 |
| `test-media-s3-client.php` / `test-media-s3-stream.php` | PASS（SDK 11 checks / 呼出し元streamの成功・失敗時保持） |
| `test-media-upload.php` / `test-media-cleanup.php` | PASS（60 / 26 checks） |
| `test-backup-job-runner.php` | PASS（49 checks） |
| `test-recovery-contract.php` / collector / workspace / publication | PASS（127 / 34 / 17 / 39 checks） |
| `test-recovery-status.php` / catalog / observed-recovery-run | PASS（105 / 43 / 20 checks） |
| `test-recovery-cron.php` / execution / schedule-status | PASS（25 / 24 / 39 checks） |
| `test-recovery-cleanup-plan.php` / cleanup / admin-model | PASS（29 / 26 / 11 checks） |
| PHP lint | runtime 102 PHP filesに構文エラーなし。変更した試験3ファイルも合格 |
| 差分検査 | `git diff --check` 合格 |

集計時、Recovery試験が内部で呼ぶ他試験の出力を重複加算しない。
Recovery・メディアの既存試験は今回変更しない機能の確認で、実WordPress/S3の再受入ではない。

同じ追加試験を修正前mainの `S3BackupRetentionManager` で実行したところ、
`All guards precede deletion` で失敗（PHP exit 255）。修正後は26項目が成功。
これは修正前に保持枠の照合がなかったことを検出する回帰確認であり、実S3の削除ではない。

## 再現

リポジトリルートで、Linux PHPとlock済み依存を使用する。

```sh
composer install --no-interaction --prefer-dist
php tests/manual/test-retention-safety.php
php -d memory_limit=32M tests/manual/test-database-integrity.php
php tests/manual/test-database-history.php
php tests/manual/test-retention-setting.php
php tests/manual/test-media-s3-client.php
php tests/manual/test-media-s3-stream.php

for test in recovery-contract recovery-collector recovery-workspace recovery-publication \
  recovery-status recovery-catalog observed-recovery-run recovery-cron \
  recovery-cleanup-plan recovery-cleanup recovery-admin-model recovery-execution \
  recovery-schedule-status media-upload media-cleanup backup-job-runner; do
  php "tests/manual/test-$test.php" || exit 1
done
```

追加試験は `.github/workflows/database-integrity-tests.yml` に組み込み済み。
PHP 8.1/8.3のCI実行結果をこのローカル結果と同一視しない。今回pushやCI起動はしていない。

最初のworkspaceでのSDK試験呼出しはComposerの展開未完了によってautoload不足で停止。
Windows側のSDK展開待ちの間、専用Linux一時領域で同一lockの依存と同一srcを使用して確認し、
展開完了後にworkspace自身で最終26/47項目・履歴・keep設定を確認した。
この環境準備中の失敗と製品試験の結果を分離する。

## 公開状態の読取り確認

- Git fetchでmainとタグを更新。`v0.3.0=627e47c`、`v0.4.0=main=dafa663`。
- GitHub PR #1はopen Draft、#2はmerged。operatorブランチとmainのtree差分なし。
- WordPress.orgページは0.4.0表示。配布ZIPを取得し、header/Stable tagが0.4.0と確認。
- 公開ZIP SHA-256：`8988a2635f1e98e0fbdfdd47eb7820e5575a656962422fab4de0b056ec038c02`。
- 公開ZIPのsrcはスコープ処理済みのためソースとの単純テキスト比較は一致しない。
  今回この差分から全ファイルの等価性を認定していない。

## 今回実施していないこと

実S3への書込み・削除、実DBのdump/import、稼働WordPressの操作、最終配布ZIPの作成・
Plugin Check・導入更新試験、SSE-KMS、PHP 8.1/8.4での今回の再試験、長期Cron運転。
今回の修正は通常のS3 PUT metadataと既存HEAD権限を利用するが、公開前には固定した
最終ZIPで新しいメタデータと保持移行の実S3確認が必要。

過去の実S3・復元・Plugin Checkの証拠は [索引](verification-index.md) に保存。
今回のソース修正に対する公開承認や復元保証として流用しない。
