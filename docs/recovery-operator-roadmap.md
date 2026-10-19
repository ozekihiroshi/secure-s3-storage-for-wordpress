# Recovery Set 改善の現状と残課題

2026-10-19時点。`main`の`627e47c`を基点にした`codex/recovery-operator-plan`の棚卸し。着手時の計画を現在の実装に合わせて整理した。過去の計画はGit履歴に残す。共通DB部品採用`codex/shared-backup-core`とは別管理。

目的は、バックアップ/リストアの内部基盤を安全にオペレーターへ見せ、状態確認・作成の進行・限定的な一時ファイル整理を可能にすること。保存complete、現在の取得可否、復元試験成功を別々に扱う。

## 実装・検証状況

| 機能 | 実装した範囲 | 確認済みの証拠 | 残る境界 |
| --- | --- | --- | --- |
| [status / JSON](recovery-set-status.md) | phase/資産充足/DB-media対応/manifest/保存時検証、終了コード | [PHP8.1/8.3・実WP-CLI](recovery-status-validation.json) | S3再確認や作成はしない |
| [最新索引・観測](recovery-catalog-observations.md) | 最新試行と最新complete、manifestに結び付く観測と鮮度 | [契約・実WP-CLI](recovery-catalog-validation.json) | site-local・100件上限、ページング未提供 |
| [観測の自動登録](automatic-recovery-observations.md) | 専用検証/復元処理から登録、配送待ち永続化・配送のみ再試行 | [native/PHP取得・隔離復元](automatic-recovery-observations-validation.json) | 一般復元CLI・定期verify・遠隔配送は未提供 |
| [Cron作成入口](recovery-cron-entry.md) | UTC日次intent、重複防止、1回1処理、失敗/設定変更時停止 | [契約・専用WordPressのhook](recovery-cron-validation.json) | 開発者の明示接続が必要。長期運転・有効化UI・失敗reset未提供 |
| [cleanup dry-run](recovery-cleanup-plan.md) | 候補と保護理由を読取り表示 | [契約・WP-CLI非変更性](recovery-cleanup-plan-validation.json) | トップ階層だけ。削除許可・内容hash検証ではない |
| [cleanup明示実行](recovery-cleanup-execution.md) | 完了DB一時ファイル2種、直前照合・ロック・台帳・途中再開 | [契約・実取得後cleanupと復元](recovery-cleanup-execution-validation.json) | 各1GiB上限、media/自動/再帰削除なし |
| [読取り管理画面](recovery-admin-readonly.md) | 一覧・詳細・観測・cleanup記録、manage_options | [本体有効化・実ブラウザ](recovery-admin-browser.md)、[集計](recovery-admin-validation.json) | 作成/復元/cleanup操作画面ではない |
| [運用案内](recovery-operations.md) | 状態確認→Cron接続→停止再開→cleanup→障害対応 | READMEと実装/個別仕様を照合 | 本番導入・配布ZIP受入は別 |

実WordPressの取得・復元を伴う今回の統合試験は生成データとディスク上のS3模擬実装を使用した。以前の[実S3内部基盤検証](aws-recovery-set-verification-2026-10-17.md)は別記録で、新しい運用機能全体の実S3受入に読み替えない。件数は重複するため合算しない。

## 次の優先順

1. 独立ブランチの累積レビューと配布範囲の確定。
2. 最終ソースからZIPを作成し、新規導入・既存版更新・状態保持を専用環境で確認。
3. 運用適用に必要な最終版の実S3往復・反復Cron検証を、費用と生成データの範囲を決めて実施。
4. 一般向け作成入口、失敗レビュー/設定移行、索引上限と容量運用を次版の改善として設計。

詳細な公開条件、製品の未提供機能、環境メモ、共通化との関係は[公開前確認](recovery-operator-release-readiness.md)。この文書更新は公開・マージ・本番適用そのものではない。
