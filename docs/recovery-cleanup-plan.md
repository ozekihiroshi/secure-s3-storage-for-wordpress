# Recovery Set workspace cleanupのdry-run

共通DB部品の採用変更とは別の`codex/recovery-operator-plan`に追加した、読取り専用の計画表示。このコマンドは削除しない。別の[明示実行入口](recovery-cleanup-execution.md)を後続変更で追加した。保存期間による自動整理、S3削除は実装していない。

```sh
wp ozeki-database-backup-for-s3 recovery-set cleanup-plan <set-id> \
  --workspace-parent=/private/backup-workspaces --format=json
```

`<set-id>`は既存の32桁ID、parentは実際のprivate workspaceの親ディレクトリを指定する。対象はその直下の`recovery-<set-id>`だけ。`--format=table`も利用できる。`--delete`等の指定は拒否する。

## 表示の意味

| フィールド | 意味 |
| --- | --- |
| `phase` | 検証済みCollectorの状態。読めなければunknown |
| `inspection_scope` | `top_level_only`。子ディレクトリ内部は調査しない |
| `inspection_complete` | この範囲の調査が完了したか。削除可能という意味ではない |
| `protection_reasons` | workspace全体を候補から外す理由 |
| `entries` | 直下の各項目の名前・サイズ・candidate/protected・理由 |
| `candidate_bytes` | 保護条件がない場合の候補ファイルの論理サイズ合計 |
| `measured_file_bytes` | 安全な通常ファイルとして確認した直下ファイルの論理サイズ合計 |
| `recursive_bytes` | 常にnull。子ディレクトリの容量を0と誤表示しない |
| `deletion_authorized` | 常にfalse。この出力をそのまま削除命令に使わない |

completeの記録とworkspaceのintentが一致し、直下に未知・危険な項目がなければ、`database.sql`と`database.sql.gz`だけを候補として表示する。これはファイル種別・所有者・mode・リンク数を確認した暫定候補で、内容ハッシュやS3上の現在の取得可否は再検証しない。論理サイズは実際の解放ディスク容量の保証ではない。

`intent.json`、`database.json`、`media.json`、`child.json`、`transfer.json`、`worker.lock`は常に保護する。既知形式の`odbfs3-preparation-<id>`ディレクトリも`media_subtree_not_inspected`として保護し、再帰走査しない。子処理の証拠・再開情報を削除対象に混ぜない。

## 保護と安全条件

- database/media/assemble/ready/publishingは`incomplete_or_resumable`、failedは`failed_requires_review`。failedだけを理由に削除候補にはしない。
- 使用中ロックは`workspace_busy`。既存ロックを`rb`で開いて共有ロックを非待機で取得する。ロックやディレクトリを新規作成しない。
- parentとworkspaceは実パス・所有者・0700を検査。通常ファイルは同じ実効UID・0600・単一リンクを要求。symlink、hardlink、特殊ファイル、不正modeは保護する。WordPress本体やuploadsの配下も拒否する。POSIX環境が対象。
- 未知項目が1つでもあれば全候補を保護へ変更する。pendingファイルも自動的に安全なゴミと判断しない。
- intentは8KiB、直下エントリは256件まで。上限超過は不完全として候補0にする。
- Collectorを前後で再読取りし、直下の一覧・inode等のメタデータを再照合する。調査中の変更や証拠欠落は`inspection_unavailable_or_changed`として保護する。

ファイル内容・名前・mode・mtime、DBレコードを変更しない。一般的な読取りと同様に、OSやファイルシステムがatimeを更新する可能性はある。共有ロックは協調するワーカー向けで、悪意ある同時書換えを完全に防ぐ境界ではない。別の明示cleanup実行では同じ条件を再検証して排他制御する。保存済みのdry-run結果を削除許可として再利用してはならない。

CLI終了コード：0=範囲内の調査完了（保護対象ありも含む）、2=引数不正、3=セット記録なし、4=安全に調査できない／使用中／上限／不正状態。JSONは1文書で、秘密情報や生例外は出さない。外部S3問い合わせは行わない。

## 検証

`tests/manual/test-recovery-cleanup-plan.php`で完了・未完了・failed・競合・リンク・mode・未知項目・証拠破損・上限・CLIの非変更性を検証する。`tools/test-recovery-status-local.py`は使い捨てWordPress/MariaDBとprivate volumeで実WP-CLIを動かし、DBダンプとファイルのhash/inode/size/mode/mtimeが前後で一致することを確認する。セットやファイルの準備は試験のsetupでありdry-runとは分離している。実S3、配布ZIP、本番配置の検証ではない。

PHP 8.1・8.3で追加29項目が合格。実WP-CLIのcleanup関連だけを再実行する場合は`python3 tools/test-recovery-status-local.py --cleanup-only`を使用する。テスト用の既存Docker imagesが必要で、使い捨てコンテナ・volumeの作成と試験後の破棄を行う。これは本番workspaceを指定するコマンドではない。
