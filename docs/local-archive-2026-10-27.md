# 共通DB実験の未保存作業確認とローカル整理（2026-10-27 JST）

## 未コミット変更の判断

| 対象 | 確認内容 | 現行mainへの扱い |
| --- | --- | --- |
| READMEの4行追記 | 共通DB部品を開発ブランチに実装済み、採用・公開待ちという旧説明 | 採用せずPR #1を閉じた現状と異なるため取り込まない |
| `docs/shared-backup-core.md` の8行追記 | 2026-10-19の採用状況、ハッシュ再確認と回帰試験の区別、将来の採用手順 | 必要事項は現在のREADME・整理記録・検証記録で説明済み。旧予定を現行方針として再掲しない |
| 未追跡 `tools/prepare-wordpress-release-030.sh` | 固定ハッシュの0.3.0 ZIPからSVN公開用コピーを準備する旧スクリプト | 0.4.1には使わない。実行せず履歴資料として保管 |
| 未追跡 `ozeki-markdown-documents/` | 別製品Ozeki Markdown Documents 0.1.0のコード・依存物・配布資産 | Backup for S3の文書ではない。現行製品へ混ぜず保管 |

現行製品に欠けていて取り込む必要のある文書は見つからなかった。
今回の判断と復元先を本記録としてmainへ残す。
過去の試験成功を現在版の再試験と扱わない原則は維持する。

## 保管と復元確認

ローカル保管先：`D:/workspace/wp-rescue/local-archives/backup-for-s3-2026-10-27/`。
試験データを含み得るため、保管ファイル自体はGitHubへpushしない。

- `repository.bundle`：整理前の全Git履歴・タグ・保存ブランチ。`git bundle verify` 成功。
- `shared-core-uncommitted.patch` と `edited-documents/`：未コミット文書の差分と元ファイルのコピー。
- `shared-core-extra/`：旧共通DB作業コピーのbuild/vendor、別製品配布物、旧公開準備スクリプト。
- `operator-extra/`：旧運用改善作業コピーのbuild/vendor。
- `quality-extra/`：0.4.1作業コピーのbuild/vendor。最終ZIP、実S3・導入・公開検証結果も含む。
- `*.inventory.json` と `preservation-results.json`：移動した全ファイルの相対パス・サイズと照合結果。
- `RESTORE.md`：新しいフォルダに復元する手順。
- `ozeki-database-backup-for-s3-0.4.1.zip`：公開済み最終ZIPを単独でも保存。

大きな成果物はZIP化せず、同一ドライブ内でフォルダを移動して保管した。
8対象・34,093ファイルについて、移動前後の全ファイル名とサイズが一致した。
これを全ファイルのSHA-256照合や、実行可能性の再試験とは扱わない。
ZIP化の試行は完了前に停止し、その未完成ZIPは完成品と混同しないよう除去した。

別途、bundleから実験ブランチを新しいフォルダへcloneし、未コミット差分を適用した。
文書2件が元ファイルとSHA-256で一致することを確認し、復元試験用フォルダは撤去した。
追跡ファイルはbundleから、未追跡・無視対象ファイルは上記extraフォルダから復元できる。

単独保存した最終ZIPのSHA-256は
`ccc7d9c8c994161d97b730e9df2964cb2f32eba5ff7d2c58c261d16ed32095d6`。
公開時に検証したZIPと一致する。

## 整理後

活動中のローカルブランチは `main` のみ。
通常のリポジトリ `D:/workspace/secure-s3-storage-for-wordpress` をmainへ切り替えた。
`codex/archive/shared-db-core-0.3.0` は復元確認後に削除。
補助作業フォルダ `D:/workspace/wp-rescue/backup-operator-plan` と
`D:/workspace/wp-rescue/backup-product-quality` は保管後にworktree登録ごと撤去した。
古いチャット内の補助作業フォルダへのリンクは無効になるため、文書は通常リポジトリの `docs/`、
旧ビルド・検証ログは上記保管先を参照する。

主作業コピーの旧build/vendor、別製品配布物、旧公開準備スクリプトは保管場所へ移した。
ソースから実行・ビルドする場合は、従来どおりComposerで依存物を導入する。
GitHubのPR #1は未マージでクローズしたまま。0.4.1タグ・公開ZIP・製品コードは変更しない。
記事原稿は未公開のまま維持する。

[前段階のブランチ整理](branch-cleanup-2026-10-27.md)は当時の保存方針の記録であり、
ローカル保存ブランチ・作業場所の現在状態は本記録を参照。
