# 0.4.0 最終レビューとPRの範囲

2026-10-20 JST。基点は公開0.3.0の`627e47c`、独立ブランチ`codex/recovery-operator-plan`。共通部品PR #1 (`codex/shared-backup-core`) を取り込まない。composer.json/lock、DB取得実装、共通packagesへの差分はない。

## レビュー結果

状態表示・JSON・観測の保存/再配送・Cronの固定intent/CAS・cleanup・設定POST・配布時の名前空間分離をレビューした。設定の権限/nonce、入力検証、古い設定のCAS拒否、読取りの破損時unknown、cleanupのロック・manifest結合・hash再検証・削除前台帳・再作成拒否を確認。この範囲では新たな重大な実装不具合は確認していない。全攻撃・障害位置を網羅した保証ではない。

今回修正：実行記録/予定判定の契約試験をCIへ追加し、アンインストール後もRecovery Set記録が残ることを同梱READMEへ明記。過去のWP-CLI名前空間誤変換修正と版番号整理も累積差分に含む。

ローカルPHP契約試験13スクリプトが合格（内部で他試験を呼ぶため件数を合算しない）。差分の秘密鍵・AWSアクセスキー・GitHubトークン形式のパターン検査に一致なし。WSLからWindows worktreeのgit metadataを読めない問題は、検査処理をWindows Git側へ分けて解消。製品不具合ではない。

## 証拠と対象物

| 証拠 | 対象・範囲 |
| --- | --- |
| `release-0.4.0-validation.json` | 0.4.0 ZIP `e2a5f027…`、公開0.3.0からの更新・再適用・記録保持。src/vendorは直前実S3検証ZIPと一致 |
| `recovery-schedule-direct-s3-validation.json` | 旧番号候補 `4a92b0e4…`、native/PHP実ワーカー保存・独立GET・隔離復元各48/44項目 |
| `recovery-schedule-settings-browser-validation.json` | 専用ブラウザの保存・停止再開・古い画面/権限拒否、Cronと記録の不変性 |
| `recovery-schedule-zip-validation.json` | 旧番号候補の新規導入と旧版更新。0.4.0新規導入の再試験とは扱わない |

今回の同梱README修正後、既存の0.4.0 ZIPはその文書修正を含まない。既存ZIPと検証結果は上書きしない。PRのPlugin CheckはHEADから再ビルドするが、その成功をインストール・実S3受入の代わりにしない。正式配布前にはマージ後ソースから最終ZIPを固定し、差分と導入確認を行う。

## 既知の制限と公開条件

- Recovery Set作成は開発者の接続が必要。一般向けcreate/restore UI・CLI、ワンクリック復元はない。
- 予定判定は閲覧時のローカル記録評価で、実Cronの設定、通知、外部監視、生存確認ではない。
- 索引・観測読取りは100件上限。cleanupは完了DB一時ファイル2種、各1GiBまで。media/証拠の自動削除はない。
- アンインストールはRecovery Set記録を保持する。最終エクスポート・消去の自動入口は未提供。外部Cronは別途停止する。
- 同一時点スナップショット、侵害耐性、Object Lock、独立署名・役割分離、悪意ある同一UIDの競合、全WP/PHP・大規模環境は保証しない。
- 管理画面の日本語固定文言、最低WordPress版を含む全互換性・国際化は残課題。一般利用者向け運用完成とは説明しない。

PRは0.4.0のソースレビュー用。自動マージ・WordPress.org配布・0.4.0 Release作成を行わない。CI結果と制限の説明を確認してからマージ/配布を判断する。公開済みv0.3.0タグと配布ZIPは維持する。
