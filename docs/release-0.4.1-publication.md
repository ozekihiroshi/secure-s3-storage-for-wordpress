# 0.4.1 公開記録（2026-10-27 JST）

## GitHub

- main反映済み：`906b3bda232b3f9eda52020fc54648b9a3a20b20`。
- タグ：`v0.4.1`（同じコミット）。
- [GitHub Release](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/releases/tag/v0.4.1)：公開済み。
- 検証済みZIPを再ビルドせず添付。公開URLから認証なしで取得したZIPもSHA-256が一致。
- ZIP：8,268,241 bytes、3,894ファイル。
- SHA-256：`ccc7d9c8c994161d97b730e9df2964cb2f32eba5ff7d2c58c261d16ed32095d6`。

## WordPress.org

- [WordPress.org](https://wordpress.org/plugins/ozeki-database-backup-for-s3/) の公開版：**0.4.1**。
- SVNリビジョン：**3767500**。変更はtrunkと新規tags/0.4.1のみ。旧0.4.0タグは変更なし。
- 非対話認証が失敗したため管理者が端末でSVN認証・コミットを実施。その後、公開APIと配布ZIPを独立に取得して確認。
- 公開ZIPの全3,894ファイルが検証済みZIPとバイト単位で一致。
- WordPress.org側でZIPが再生成されるため、アーカイブのハッシュは異なる。
  配布ZIP SHA-256：`4f055a770aa7a757e14c9a2c986583275e43dc5c82f044c4b10fff079553338a`。

## 公開コミットの追加CI

GitHub Actionsの7ワークフローがすべて成功：Plugin Check、Database Integrity Tests、
Recovery Contract Tests、Media Inventory Tests、Media Upload Tests、Backup Job Tests、
Isolated WordPress Recovery。最終ZIPを直接検証した公開前記録とは区別する。

機械可読な公開結果は [publication JSON](release-0.4.1-publication.json) を参照。

## 保留事項・記録の扱い

共通DB部品の [PR #1](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/pull/1) は
open / Draftのまま。head `b2e78ebaa61abbab9c5c402e7fbbbe2c8dfa5136` を変更せず、マージしていない。
既存ブランチ・旧タグの削除や履歴書換えは行っていない。

[公開前検証](release-0.4.1-validation.md) とそのJSONは検証完了時点の記録として保持する。
その `publication_performed: false` は当時の状態であり、現在の公開状況は本記録を参照。
コード・配布ZIPはその後変更せず、公開結果に関するREADME/docsのみを追記する。

更新直後は、旧バックアップにdigestメタデータがないため保持処理が延期される場合がある。
設定した保持数の新バックアップが揃うまで、S3容量とretention警告を確認する。

## 不具合受付・通知の運用記録（2026-10-28）

受付先、管理者の通知設定、確認方法は[サポート運用記録](support-operations.md)を参照してください。公開済み成果物は変更していません。
