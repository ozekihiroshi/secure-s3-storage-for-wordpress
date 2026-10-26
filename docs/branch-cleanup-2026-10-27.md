# PR・ブランチ整理（2026-10-27 JST）

## 判断

現在の製品開発は `main` を基準にする。0.4.1の配布コード・タグは変更しない。
利用者の今回の指示により、以前の「PR #1は保留」という判断を更新した。

[PR #1](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/pull/1) は未マージでクローズ。
現在の製品には、この共通DB部品移行を採用する必要はない。

- 既存のPHP DBダンプ処理を、固定版の共通部品と互換aliasへ置き換える実験であり、0.4.1の保持不具合を修正する前提ではない。
- 共通化には重複保守を減らす可能性がある一方、版の取り込み、ビルド例外、名前空間互換性、更新責任の管理が増える。
- 現在のmainとの競合があり、そのまま採用できない。独立製品として現行機能を維持する目的に対して、今この移行を行う必要性は確認できない。
- 将来、両製品の共通修正を継続的に取り込む必要が具体化した場合は、その時点のmainから互換性・保守責任・配布物を再検証する。古いPRを開いたままにして開発予定と誤認させない。

## 整理結果

| 対象 | 処置と理由 |
| --- | --- |
| リモート `codex/shared-backup-core` | 削除。PR #1はクローズし、議論・検証記録を残す |
| ローカル `codex/shared-backup-core` | `codex/archive/shared-db-core-0.3.0` に改名。未コミットの利用者作業があるため保護。活動中の開発ブランチではない |
| `codex/recovery-operator-plan` | リモート・ローカルとも削除。head `e97c273` はmainの祖先で、PR #2のマージ時点とtreeも一致 |
| `codex/backup-product-quality` | 原稿レビューと本記録をmainへ反映後、ローカルを削除。リモートには作成していない |
| `main` | ローカルも最新へ進め、今回の作業コピーをmainに切り替える |

旧shared-coreの未統合headは `b2e78ebaa61abbab9c5c402e7fbbbe2c8dfa5136`。
ローカル保存ブランチに加え、`build/shared-db-core-pr1-archive.bundle` に履歴を保存し、
`git bundle verify` で検証した。bundleは未コミット変更を含まない。

ローカル作業場所は以下のとおり。フォルダ削除・移動は行わない。

- `D:/workspace/secure-s3-storage-for-wordpress`：保存用ブランチ。既存のREADME、shared-core資料の変更、未追跡の `ozeki-markdown-documents/` と `tools/prepare-wordpress-release-030.sh` はそのまま保持。
- `D:/workspace/wp-rescue/backup-operator-plan`：旧検証成果物を保持し、`e97c273` のdetached HEADで保存。
- `D:/workspace/wp-rescue/backup-product-quality`：今回の編集・公開検証資料を保持するmainの作業コピー。

過去の公開記録にある「PR #1は保留」は、その時点の記録として変更しない。
現在の判断は本記録を参照。Resilience側の別リポジトリのPR・ブランチは今回の整理対象外。
