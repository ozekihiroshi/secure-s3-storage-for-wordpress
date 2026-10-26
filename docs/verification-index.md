# 検証記録・設計資料の索引

現在の製品方針・公開版・保証範囲は [2026-10-27製品レビュー](product-quality-review-2026-10-27.md)
と [README](../README.md) を基準とする。以下の過去文書の「未公開」「開発中」や
計画は記録時点の表現であり、現在の公開状況・採用済み機能の一覧ではない。
元の検証JSON、ZIPの識別ハッシュ、日付付き記録は変更・削除しない。

| 分類 | 記録 |
| --- | --- |
| 0.4.1公開準備 | [リリースノート](release-notes-0.4.1.md)、[最終ZIP検証](release-0.4.1-validation.md) |
| 今回の限定修正 | [試験結果と再現方法](product-quality-validation-2026-10-27.md) |
| DB圧縮・S3整合性 | [実装](database-integrity-verification.md)、[2026-10-17実S3・復元](aws-database-integrity-test-2026-10-17.md) |
| メディア | [基盤試験](media-backup-foundation-tests.md)、[実S3 fixture](aws-media-fixture-test.md)、[転送](aws-media-transfer-test-2026-08-31.md)、[Cron](aws-media-cron-single-event-test-2026-09-01.md) |
| Recovery Set基盤 | [実S3・復元](aws-recovery-set-verification-2026-10-17.md)、[pipeline](recovery-pipeline-verification.md)、[WordPress復元](wordpress-recovery-verification.md) |
| 運用表示 | [status](recovery-set-status.md)、[catalog/観測](recovery-catalog-observations.md)、[実行記録](recovery-execution-status.md)、[予定判定](recovery-schedule-status.md)、[設定](recovery-schedule-settings.md) |
| Cronと停止再開 | [実worker S3](recovery-direct-worker-s3.md)、[HTTP WP-Cron](recovery-auto-cron-s3.md)、[無アクセス外部Cron](recovery-external-cron-s3.md) |
| cleanup | [計画](recovery-cleanup-plan.md)、[実行・中断](recovery-cleanup-execution.md) |
| 配布物 | [版番号整理の旧記録](release-version-reconciliation.md)、[0.4.0 Plugin Checkと更新試験](release-0.4.0-plugin-check.md)、[最終候補実S3](release-0.4.0-final-s3.md) |
| 過去の設計・計画 | [当初定義](project-definition.md)、[Recovery設計](recovery-set-design.md)、[運用機能棚卸し](recovery-operator-roadmap.md) |
| READMEにあった長い経緯 | [整理前READMEの保存版](readme-before-product-review-2026-10-27.md) |

各試験ページにリンクされた `*-validation.json` 等は、そのページが示す候補と環境の証拠。
現在の任意のバックアップに対する検証結果ではない。記事では「生成できた」
「保存バイトを照合した」「後日取得できた」「隔離環境で復元できた」を区別する。
