# Recovery Setを安全に操作・確認するための整備

2026-10-19。基点はmainの`627e47c`。現状調査と次の実装仕様案であり、以下の新しいCLIやcleanup機能を実装済みとは扱わない。

## 目的と開発単位

Ozeki Database Backup for S3は、バックアップとリストアの完全性を高める独立製品として改善を続ける。既存Recovery Setの内部契約を利用し、オペレーターが不足・失敗・未検証を識別して操作できる入口を追加する。Resilienceの独立署名、侵害耐性、watchdogの仕組みをこの変更の必須要件にしない。

この作業は`codex/recovery-operator-plan`で管理する。共通PHP DB部品を導入する`codex/shared-backup-core`／PR #1とは別変更。共通化PRの採用やResilienceの3日間観測を開始条件としない。今回のコミットは文書のみで、配布バージョンや実行コードを変更しない。

## 現状の棚卸し

| 項目 | 現状 | 次に必要なもの |
| --- | --- | --- |
| recovery set status | `RecoveryCollector::current()`で検証済み内部レコードを読める | 読取り専用の公開statusと履歴索引。内部レコード全体を出力しない |
| complete / partial / failed | Collectorはdatabase/media/assemble/ready/publishing/complete/failedを保持。partialという保存状態はない | 実行状態と資産充足状況を分離した表示モデル |
| DB/media対応関係 | `RecoveryManifest`がset_id/source_id、保存先、profile、媒体job_idとprefixを検証 | profileと含まれない資産を画面・CLIで明示。別セット同士を結合しない |
| manifest存在確認 | `RecoveryPublisher`が条件付き公開、HEAD、固定Version IDまたはIf-Match付きGETで同一バイト列を確認 | 保存時の証拠と、現在のリモート存在確認を別表示 |
| verification state | ManifestにDB/media検証、`RecoveryObservation`にavailability/restoreの型がある | 観測記録の永続化・取得・時刻表示への接続と、確認対象の明示 |
| last recovery set | 従来`StatusCommand`はDB履歴の最終成功を表示 | 最新の試行と最新completeの両方を示す索引・選択規則 |
| machine-readable status | media statusにはJSON出力がある。一般statusは翻訳済み表示文字列 | Recovery Set用の版付きJSON契約・安定コード・終了コード |
| Cronからの作成 | 従来`DatabaseBackupCronHandler`はDBバックアップ用。CollectorにはCAS/lease、workspaceにはロックがある | 作成要求の重複防止、永続受付、時間制限付き実行・再開の接続 |
| workspace cleanup | `RecoveryWorkspace`は自動・再帰削除を行わない。未確定DBファイルの限定resetがある。media cleanupは別の失敗job用 | Recovery Set専用の削除可否判定、計画表示、明示実行、記録保存 |

根拠：`src/Backup/Recovery/`、`src/Cli/{StatusCommand,MediaCommand,CommandRegistrar}.php`、`src/Cron/DatabaseBackupCronHandler.php`。当時の実S3/復元結果は[既存の検証記録](aws-recovery-set-verification-2026-10-17.md)を参照。今回はコード読取りによる調査で、実S3や復元試験の再実行ではない。

## 1. 状態の読取りと表示を先に実装する

公開statusは次の軸を分ける。

- `phase`：既存Collectorの進行状態。読取り失敗は`unknown`として理由コードを示す。破損をfailedやmissingへ丸めない。
- `completeness`：要求profileの資産・manifest公開証拠の充足状況。`none / partial / complete / unknown`を案とする。failedはphaseの値であり、partialと同じ軸の排他的な値にしない。
- `verification`：保存時のDB/media検証とmanifest公開検証。`ready`やローカルmanifestの存在だけではcompleteとしない。
- `availability`：現在の取得可否と確認時刻。未確認、見つからない、権限不足、通信結果不明を区別する。
- `restore`：復元試験の未実施・成功・失敗、確認時刻と対象manifestのハッシュ。保存時completeを復元試験成功として扱わない。

DB単独profileのmediaは`not_included`でありpartialではない。database_uploadsでDBだけ検証できている場合はpartial。complete判定には既存CollectorとPublisherの検証契約を用い、表示層が緩い判定を新設しない。

DBとmediaが対応することは、同一時点のスナップショットを保証しない。既存manifestの`capture.consistency`と対象範囲をそのまま示し、uncoordinatedを暗黙に整合済みへ昇格させない。

statusは保存・再試行・ロックファイル作成・cleanupを起動しない。workspaceのコンストラクターはディレクトリを作るため、読取り用途でそのまま呼ばない。S3再確認は明示的なverify操作に分ける。

### CLI契約案（未実装）

`wp ozeki-database-backup-for-s3 recovery-set status <set-id> --format=json`を最初の読取り入口とする。既存statusの出力を黙って変更しない。集合索引を用意した後にlist/latestを追加する。

JSONはschema_version、set_id、source_id、profile、phase、completeness、資産の対応、manifest証拠、各検証状態・確認時刻、安定したreason_codeを許可リストで出す。翻訳文字列を機械判定に使わず、鍵・接続情報・生の例外・SQL・内部checkpointは出さない。

statusの終了コード案：0=状態を正常取得（対象セットがfailedでも取得成功）、2=要求不正、3=対象なし、4=状態を安全に読めない。機械監視はJSON内の状態を判定する。将来のcreate/verifyコマンドの終了コードと混同しない。stdoutはJSON文書一つ、診断はstderr、時刻はUTCのISO 8601。

latestはsource/profileで絞り、最新試行と最新completeを別に返す。created_atとset_idによる決定的順序を使い、ファイルmtimeや従来DBの最終成功日時を代用しない。読めない記録を隠して古い成功だけを返す場合は、一覧が不完全であることを必ず示す。

## 2. 作成・再開とCronを接続する

手動CLIとCronは同じオーケストレーション層を使用する。設定を固定したintentを永続化してから進める。要求IDと日次枠等の重複防止キーを保持し、同じ要求が複数セットを作らない。既存のWordPress Cronを自動で切り替えず、明示設定で導入する。

同時実行、lease切れ、強制終了、送信後の応答喪失は既存CAS/ロックと証拠照合で再開する。単なる時間切れを失敗・新規作成・削除の指示にしない。1回の実行には時間・件数の上限を設け、受付成功と保存完了を分ける。Cron未実行・継続停止も最終起動時刻と進捗時刻から識別できるようにする。

## 3. workspace cleanup policy

初期値は自動削除なし。最初は削除対象・容量・保護理由を返すdry-runを用意し、次にexact set IDを指定した明示実行を追加する。S3オブジェクトの保持・削除とは独立した操作にする。

- active/busy/結果不明/破損/再開可能な作業を自動削除しない。failedだけを理由に削除しない。
- 完了証拠、intent、manifest参照、照合記録を保持する領域を先に確定し、一時データの整理で最終セット履歴を失わせない。
- private workspaceの所有者・mode・実パス・symlink・hardlink・ロックを確認し、既知のファイルだけを対象とする。未知ファイルや実行中の競合は拒否する。
- dry-runと実行の間でも再検証する。途中失敗を記録し、再実行で残る対象だけを扱えるようにする。
- 保存日数・容量上限・空き容量不足時の振る舞いは設定として明示する。容量不足でも未完了証拠を黙って消さない。

## 実装の分割と受入条件

1. **状態モデル＋読取りCLI**：全内部phase、DB単独、片方だけ取得、publish応答喪失、破損・権限不足・不明IDを検査。statusがファイル・DB・S3を書き換えず秘密を出さないこと、JSON/終了コードを契約試験で確認する。
2. **索引と観測結果**：latest試行とlatest completeの分離、同時刻の順序、欠けた履歴、古い観測、manifestハッシュ不一致を検査する。
3. **作成・Cron入口**：同一要求の重複、二重起動、途中終了・再開、期限超過、設定変更の影響を専用環境で検査する。
4. **cleanup**：まず計画表示、次に明示実行。稼働中・未知ファイル・リンク・競合・部分削除失敗を検査し、証拠・リモート資産を保持する。
5. **オペレーター向け案内**：CLIで契約を固めた後、管理画面・README・復元手順へ同じ状態定義を反映する。保存complete、現在の可用性、復元成功を別々に説明する。

各段階は別コミット／PRでレビューできる大きさにし、実装・検証・導入案内が揃ったものだけを完了とする。現時点では上記1〜5は計画であり、この文書追加を機能完成とは数えない。
