# Backup for S3単独の状態確認・未達事項

2026-10-19。対象：`codex/recovery-operator-plan`、棚卸し基準commit `0f3e4c0`。コードと既存試験記録の照合であり、今回新たに実環境試験を実行したものではない。配布済み版の機能とは区別する。

後続更新：この棚卸し後に[実行記録・画面/JSON表示](recovery-execution-status.md)を実装し、[専用環境で検証](recovery-execution-validation.json)した。以下は棚卸し時点の評価を残している。最終起動・確定進捗・終了結果・最終保存成功のサマリーは追加済み。予定超過、全呼出し履歴、稼働の生存確認は引き続き未提供。

さらに[予定超過の読取り判定](recovery-schedule-status.md)を追加した。有効・停止・起動/完了の猶予・現在枠の超過・不明を画面/JSONへ表示する。設定は開発者用の明示APIで、全枠の履歴や自動監視ではない。以下の未達表は当初の棚卸し時点を示す。

Backup for S3はバックアップ・リストアを目的とした独立製品である。Resilienceとの補完関係、必須依存、監視の委譲を前提にしない。今回の未達事項に通知サービス、別ホストwatchdog、警報配送管理を含めない。共通部品の変更も対象外。

## 5項目の棚卸し

| 確認したいこと | Recovery Setの現状 | 管理画面・JSONから分かる範囲 | 判定 |
| --- | --- | --- | --- |
| 最終起動 | intentの`created_at`はセット作成時刻。各Cron呼出しの開始・終了時刻は製品側に保存していない。reportはhandle終了後の呼出しで、停止時には届かない | 作成日時は表示されるが、最終起動日時ではない。実験のpulseログは製品機能ではない | 未達 |
| 最終成功 | `complete`と公開読戻し検証の`publication_checked_at`が永続化される。`latest_complete`は作成日時が最新の完了セット | CLI `latest`で完了セット、`status`と画面詳細で公開検証日時を確認可能。画面にはsource別の最終成功サマリーがない。完了時刻順の索引ではない | 一部実装済み |
| 処理中 | Collectorにdatabase/media/assemble/ready/publishingの段階と内部リースがある | `phase`で未完了段階を確認できる。ただし現在のプロセス稼働・最終進捗時刻・停止との区別はできない。60秒リースも生存証明ではない | 段階表示済み、稼働状態は未達 |
| 失敗 | Collectorの`failed`とcollection_failed/publication_failed/recovery_exhaustedを保存。スケジューラーのblocked/unknownは返却値 | 保存済み失敗は画面・JSONで確認可能。設定変更・アダプター初期化失敗などの呼出し結果はreport接続先任せで、永続履歴・画面に集約されない。強制終了もfailedとは限らない | 一部実装済み |
| 予定超過 | UTC日次枠の重複抑止はあるが、予定時刻・猶予・停止中の扱いを持つ状態判定はない | 予定超過を表示する画面・JSONはない。外部crond試験の起動漏れ2枠は独立した試験制御側が検出した | 未達 |

根拠：[`RecoverySetCronHandler`](../src/Cron/RecoverySetCronHandler.php)、[`WordPressRecoverySchedule`](../src/WordPress/WordPressRecoverySchedule.php)、[`RecoveryStatus`](../src/Backup/Recovery/RecoveryStatus.php)、[`RecoveryIndex`](../src/Backup/Recovery/RecoveryIndex.php)、[`RecoverySetsPage`](../src/Admin/RecoverySetsPage.php)、[`RecoverySetCommand`](../src/Cli/RecoverySetCommand.php)。

「最新の完了セット」と「最後に完了した処理」は異なる。現在のlatestは作成日時で並べるので、古いセットが後で完了した場合の最終完了時刻を求める入口ではない。また、statusの終了コード0は読取り成功であり、`phase=failed`でも0になる。

## 従来機能と混同しないこと

- DB単体の設定画面と従来のCLI statusには「最終成功」と「次回予定」の表示がある。成功・失敗履歴は直近20件で、成功日時は結果を記録した時刻。全履歴の最終成功を永久保持する仕組みではなく、20件すべて失敗なら古い成功は表示できない。開始履歴・進捗・予定超過判定はない。ロック中のCron呼出しは結果記録なしで戻る。
- メディア単体にはqueued/running/succeeded/failed、ファイル数・転送量、Cronイベント未登録の案内がある。ただしrunningは保存された状態であり、現在動いている保証ではない。イベント未登録と、イベントがあるが予定を過ぎた状態は別である。
- 従来DB履歴、メディアジョブ、Recovery Set台帳は別の記録。DB単体の成功をDB＋メディアのセット成功として扱わない。

根拠：[`SettingsPage`](../src/Admin/SettingsPage.php)、[`DatabaseBackupCronHandler`](../src/Cron/DatabaseBackupCronHandler.php)、[`BackupHistoryRepository`](../src/Backup/History/BackupHistoryRepository.php)、[`StatusCommand`](../src/Cli/StatusCommand.php)、[`MediaBackupPanel`](../src/Admin/MediaBackupPanel.php)。

## リストアに関して確認できること

保存完了、現在の取得可否、復元試験成功は別の事実。`status`単独はローカル保存記録だけを見るので、availabilityはunknown、restoreはnot_tested。別の`observations`と画面詳細ではmanifestに紐付く取得・復元観測を集約できる。画面の観測鮮度は24時間で、古い成功を現在の成功とは扱わない。

専用環境の直接S3保存→取得→隔離復元はnative/PHPで検証済み。ただしRecovery Setを一般利用者が選んで復元・再検証する製品CLI/UIは未提供。試験用bootstrapや復元スクリプトを利用者向け入口の完成と数えない。

## 単独製品として優先する未達事項

以下は次の実装候補であり、この棚卸しではコードを追加していない。

1. **実行状態の記録と読取りを揃える。** 起動時刻、最終進捗、終了結果、最終成功を区別して永続化し、管理画面とJSONで同じ意味を示す。異常終了は成功にも確定失敗にも推測せず、結果不明・進捗未更新として示す。既存記録から復元できない時刻は不明とする。履歴整理でも最終成功を失わない設計が必要。
2. **予定超過を閲覧時に判断できるようにする。** 有効・停止、予定周期、猶予、起動予定とバックアップ完了期限を区別する。予定が未設定なら判定不能とする。日時逆行、初回未実行、前回未完了を試験する。外部Cronプロセスの実際の起動を断定せず、プラグインが実行を観測していないことを示す。閲覧中に台帳更新やジョブ起動をしない。サイト停止中のリアルタイム検知は対象外。
3. **作成・再検証・リストアの利用者向け入口を完成させる。** 現在は明示bootstrapが必要。保存対象と復元対象を選び、検証結果と上書き範囲を確認できる導線を整える。保存completeと復元試験成功を分離したまま扱う。
4. **失敗後の安全な再試行・設定変更を整える。** 現在failedは翌日も停止する。証拠・既存資産を保持した照合と明示的な再試行が必要。台帳削除・source ID変更を復旧手順にしない。実行中・結果不明・確定失敗を区別する。
5. **継続使用に必要な上限と容量管理を整理する。** セット/観測索引は100件を超えると不完全扱いでlatestを返さない。日次利用で到達するため、ページングまたは安全な索引・履歴管理が必要。cleanupは完了DBの一時SQL/gzipに限定され、メディア作業領域や証拠の保護・整理方針が残る。大容量処理・日次境界越え・長時間停止再開を追加検証する。

まず1・2で「現在何が分かるか」を正確に表示し、3・4でバックアップからリストアまでの操作を完成させる。5は継続運用を推奨する前に解決する。通知・独立監視の追加をこれらの完了条件にしない。

## 検証済み範囲と残る検証

- [状態JSON](recovery-status-validation.json)、[索引](recovery-catalog-validation.json)、[管理画面](recovery-admin-validation.json)：既存記録の表示・不明扱い・読取り専用性。稼働中の生存確認を試験したものではない。
- [直接S3往復・隔離復元](recovery-direct-worker-s3.md)：両backendの資産取得・復元。一般利用者向け復元UIの試験ではない。
- [自動WP-Cron](recovery-auto-cron-s3.md)、[外部crond](recovery-external-cron-s3.md)：専用環境の重複抑止・停止再開・状態保持。後者はPHP方式のみ。起動漏れ検出は試験制御側で、製品の予定超過機能ではない。
- 日次境界の模擬契約試験はあるが、実時間の複数日運転、大容量、状態表示追加後の互換性・異常終了時の記録は別途検証が必要。

環境のDNS・Docker・接続待機問題は試験環境の記録として分ける。Resilienceの72時間試験も本製品の完了条件にはしない。既存公開チェックリストにある「本番監視通知」「report停止監視」は運用者側の選択事項で、本棚卸しでは単独製品の必須実装から除外する。
