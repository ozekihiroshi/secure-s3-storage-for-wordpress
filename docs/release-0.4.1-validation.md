# 0.4.1 最終配布候補の検証（2026-10-27 JST）

このページは公開前の検証記録。現在の公開状況は [公開記録](release-0.4.1-publication.md) を参照。

検証完了時点の状態：依頼された公開前確認はすべて成功。0.4.1公開候補として準備完了。
公開・GitHub Release・タグ作成は行っていない。

## 固定した配布物

- バージョン：0.4.1（プラグインheader、Stable tag、changelogを整合）。
- ZIP：`build/ozeki-database-backup-for-s3-0.4.1.zip`
- SHA-256：`ccc7d9c8c994161d97b730e9df2964cb2f32eba5ff7d2c58c261d16ed32095d6`
- 配布ファイル数：3,894。開発用tests/tools/docs・秘密ファイルは同梱しない。
- 基準：main / v0.4.0 `dafa663` からの `codex/backup-product-quality` 作業ツリー。
- composer.lockを変更せず、固定PHP-Scoperで依存namespaceを分離。autoload衝突試験、
  runtime構文検査を含む既存ビルダーが成功。
- 更新元：WordPress.org実配布0.4.0、SHA-256
  `8988a2635f1e98e0fbdfdd47eb7820e5575a656962422fab4de0b056ec038c02`。

## 確認結果

| 対象 | 結果 |
| --- | --- |
| 最終ZIPの保持回帰 | 26項目成功 |
| 最終ZIPのDB整合性 | 47項目成功。実SDKのmetadata送受信を含む |
| 新規導入・同版再適用 | 成功。全3,894ファイルがZIPと一致、設定handler 38項目と状態保持も成功 |
| 公開0.4.0からの更新 | 成功。実Plugin_Upgrader更新、全ファイル一致、設定/履歴/観測/未完了実行/cleanup/workspace保持、設定handler 38項目 |
| Plugin Check | 2.1.0、新規・更新の両ケースで `Success: Checks complete. No errors found.`。runtime checksも有効 |
| 実S3保持 | 23項目成功。正常削除、証拠欠落/不一致時の停止、対象外資産の保護、独立GET、上書き412 |

実S3は `ceri-secure-s3-storage-test` の新規専用prefix
`wordpress-test/retention-041/d572a3bfdb5eaa205fcc3462` のみ。
合成gzipを12個作成し、通常保持ケースでこの試験の古い1世代だけを削除した。
残る11個は証拠として保持。過去のバックアップ・本番DB・本番WordPressを変更していない。
取得した元digestとS3 checksum、残す資産の独立GETが一致し、試験前後でZIP由来の
全ファイルが一致した。実行ホストはPHP 8.1.2。記録のUTC日時は2026-10-26 23:26台
（JST 2026-10-27 08:26台）。

導入検証環境はWordPress 7.1.3 / PHP 8.3.33 / MariaDB 11.8。
実ブラウザ操作の再試験ではなく、実WordPressに対するWP-CLI、管理画面render、
設定POSTハンドラーとPlugin_Upgraderの試験である。

## 試験方法と範囲

初回候補 `63bfc294…` でも導入・更新・Plugin Check・S3確認は成功したが、
公開後に不適切になるreadmeの候補状態説明を整理し、上記の最終ZIPを作成した。
展開内容の差分はreadme.txtの1ファイルだけで、runtime/vendorは全一致。
それでも最終ZIPで対象試験を再実行し、初回候補の結果を最終結果として代用しない。
初回候補のJSON・ログとS3試験prefix `wordpress-test/retention-041/5d4da3c3fb352ca3cd7c9867`
（残留11個）も別記録として保存している。

`tools/test-retention-release-local.py` は固定ZIPを専用一時領域へ展開し、
外部fixtureのSDK参照だけを配布namespaceへ合わせて既存試験を実行する。
最初の試行ではfixture側の名前変換が自製 `Aws` namespaceにも適用されて停止した。
外部fixtureの変換を修正して26/47項目が成功した。候補ZIPは再生成・変更していない。

`tools/test-recovery-zip-install.py` は新規のinternal Docker network・DB・volumeで
実ZIPを導入し、実 `Plugin_Upgrader` で更新する。公開0.4.0に設定、履歴、
Recovery Set、観測、cleanup台帳、未完了実行、予定設定を作ってから更新し、
オプションsnapshotとprivate workspaceを比較する。sourceのautoloadへfallbackせず、
インストール全ファイルとZIPを比較する。停止・再有効化、同版再適用、JSON、
管理画面render、POST/nonce/権限/CAS設定試験も含む。
最終試験では通常DB設定の実フィールド `retention_keep_count` と
`BackupHistoryEntry` の正式形式を使用し、keep値と成功/検証状態の保持も直接確認した。

最初の更新ケースは更新直後の状態比較を通過した後、fixtureの二重seedで停止した。
0.4.0側に先に作った観測runを更新後に再作成せず、保持状態を検証するよう
ハーネスだけを修正して再実行し成功した。これは製品コードの変更・ZIPの再作成ではない。

Plugin Checkは公式2.1.0のZIPをfixtureへ導入し、`--require=.../plugin-check/cli.php`
でruntime checksも有効にした `wp plugin check` を実行する。チェック項目の除外は追加しない。

実S3は `tools/test-retention-s3-release.py` / `.php` を使用。
固定ZIPを過去に使用した試験ホストの新規private `/tmp/odbfs3-retention-*` に展開し、
元ZIP hashと全配布ファイルhashを確認する。既存のIAM credential providerを利用し、
認証情報をローカルに持ち出さない。削除候補が事前に記録した試験keyと一致しなければ実行しない。

今回のS3確認はSSE-KMS、version単位保持、Object Lock、大量世代、並行外部削除、
長期運転を網羅しない。合成gzipの保持試験であり、今回実DBをdump/importする試験ではない。
完全サイト復旧・DB/media同時点snapshotの保証は追加しない。

## 証拠と公開境界

作業場所の `build/release-041-final-evidence/` に個別JSONとsource-provenance.json、
`build/release-041-*.log` にビルド・導入・S3ログを保存。
公開判断用の確定結果は [検証JSON](release-0.4.1-validation.json) に保存した。
公開時はこのhashのZIPを使い、配布対象ファイルを変更した場合は新しいZIPで再確認する。
既存0.4.0のタグ・ZIPを置換しない。公開操作は利用者の判断待ち。
