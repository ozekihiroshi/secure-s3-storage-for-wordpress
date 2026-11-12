# 0.4.2 rc.1 — 導入案内 / First-time setup guidance

## 公開状態 / Status

GitHubの公開候補。WordPress.orgには未反映。本番設定、公開済みZIPと既存タグは変更しない。

GitHub prerelease candidate; not published to WordPress.org. Production settings, previously published ZIPs and existing tags remain unchanged.

- Release: [v0.4.2-rc.1](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/releases/tag/v0.4.2-rc.1)
- Source: the same release tag (GitHub's source archives are not installable plugin packages).
- Installable package: `ozeki-database-backup-for-s3-0.4.2.zip`
- Plugin version / readme Stable tag: `0.4.2`
- SHA-256: `30a227b766db693459dfb2a5b2544f95e34d5e56cbd3faeb4c0eda196876f162`

## 今回の変更 / Changes

AWSに詳しくない人向けに、環境の準備、S3・IAM、設定保存、接続確認、初回バックアップと成功確認を順に説明する[日本語ガイド](getting-started-ja.md)と[英語ガイド](getting-started.md)を用意した。初回用IAMポリシー全文、保持削除を後から有効にする場合の権限、トラブル時の次の操作も案内する。

`readme.txt`のDescription / Installationと設定画面から両ガイドへたどれる。設定画面のprefix入力例をガイドに合わせた。設定画面の新しい案内は日英併記であり、既存画面全体の翻訳ではない。バックアップ保存・認証・定期処理の動作は変更していない。

English and Japanese guides cover hosting prerequisites, S3/IAM, saving settings, testing connectivity and checking the first database backup. Both the packaged readme and settings page link to them. The prefix example and initial retention-permission guidance are aligned. The new settings introduction is bilingual; this is not a full translation of the existing UI. Backup, authentication and scheduling behavior are unchanged.

## 確認 / Validation

- 完成ZIPの全3,894ファイルを公開済み0.4.1と比較。相違はエントリーポイントのバージョン、`readme.txt`、`src/Admin/SettingsPage.php`だけ。その他の同梱ファイルはバイト一致。エントリーポイントとreadmeは対応ソースにも一致。
- 配布用ビルドのPHP構文・依存ライブラリの名前空間分離確認を通過。
- WordPress 7.1.3 / PHP 8.3.33の使い捨て隔離環境で完成ZIPの新規導入を確認。インストール後の全ファイルもZIPと一致。
- 公開済み0.4.1からWordPressのPlugin_Upgraderで更新。既存の保存先設定・バックアップ履歴・定期処理設定・未完了の処理記録・作業領域を保持。同一版の再適用と無効化・再有効化でも確認した。
- 両ケースでインストールされた設定画面を英語・日本語のlocale指定で描画し、日英案内・ガイドリンク・prefix入力例・ボタン名を確認。設定処理の既存38項目も各ケースで通過。
- 新規導入と更新後の両方で公式Plugin Check 2.1.0を実行し、いずれも`Success: Checks complete. No errors found.`。
- 旧0.4.1 ZIPのSHA-256は`ccc7d9c8c994161d97b730e9df2964cb2f32eba5ff7d2c58c261d16ed32095d6`のまま。

The exact 3,894-file package passed fresh installation and a real Plugin_Upgrader update from public 0.4.1 on isolated WordPress 7.1.3 / PHP 8.3.33. Settings, history, schedules, unfinished execution records and workspace data survived upgrade, same-version replacement and reactivation. Both installed trees matched the ZIP; bilingual guidance was rendered under English/Japanese locale selection. The existing 38 settings-handler checks passed in each case. Official Plugin Check 2.1.0 reported no errors in both cases.

Release assets include `package-audit.json`, `install-fresh.json`, `install-from-0.4.1.json` and the ZIP checksum. Only the entrypoint version, readme and SettingsPage differ from the public 0.4.1 package; all other packaged files are byte-identical.

## 確認範囲 / Scope

今回の導入・更新確認は本番と接続しない隔離環境で行う。AWSの新規構築、実S3へのバックアップ、初めて使う人による実利用試験は含めない。WordPress.orgの「詳細を表示」への反映は、次の公開時に行われる。GitHubのガイドは先に閲覧できる。

Installation and upgrade checks use disposable isolated WordPress instances. They do not provision AWS resources, run a real S3 backup or substitute for a novice user study. The live WordPress.org details view changes only after a later WordPress.org release; the GitHub guides are available separately.

Earlier visual review: [Onboarding review](onboarding-review-2026-11-12.md).
