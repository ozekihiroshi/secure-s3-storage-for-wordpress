# Recovery Setの明示的なworkspace cleanup

`codex/recovery-operator-plan`の独立変更。共通DB部品採用PRとは別管理。自動cleanupやS3削除は行わず、指定した完了セットのローカルDB一時ファイル2種だけを削除する。

## 実行と再開

まず[dry-run](recovery-cleanup-plan.md)で候補と保護理由を確認する。

```sh
wp ozeki-database-backup-for-s3 recovery-set cleanup-plan <set-id> \
  --workspace-parent=/private/backup-workspaces --format=json
```

セットIDを同じ値で2回明示して実行する。

```sh
wp ozeki-database-backup-for-s3 recovery-set cleanup <set-id> \
  --workspace-parent=/private/backup-workspaces \
  --confirm-set=<set-id> --format=json
```

`<set-id>`は32桁IDを置き換える。`--confirm-set`の一致が必要で、対話入力や曖昧な「最新セット」指定は使わない。表形式は`--format=table`。削除するのは`database.sql`と`database.sql.gz`だけで、workspaceディレクトリ自体は残る。

保存時completeは現在のS3取得可否や復元成功を保証しない。このコマンドはS3へ接続せず、既存の公開証拠とローカルファイルを照合する。必要な復元確認を済ませたうえで、残していたローカルコピーを削除する操作として実行する。

途中で失敗した場合は、返された状態と原因を調べ、障害を解消して**同じコマンド・同じID・同じ親ディレクトリ**で再実行する。台帳を消して新規処理へ戻したり、証拠を書き換えたりしない。改変・再作成・破損が原因の場合、再試行しても安全条件を満たすまで停止する。

## 削除直前の検証

1. dry-runの条件をその場で読み直す。過去のdry-run出力を削除許可として受け取らない。
2. 既存`worker.lock`を新規作成せずに開き、非待機の排他ロックを保持する。ロック取得後にも再検証する。
3. Collectorのcompleteとintent、実パス、所有者、0700/0600、単一リンク、未知項目がないことを確認する。失敗中・再開可能なセットは対象外。
4. **両ファイルを削除する前に**、それぞれのサイズとSHA-256をmanifestに結び付いたDB証拠と照合する。片方でも不一致なら、最初の削除を始めない。
5. workspace外の専用台帳へセット・Collector・manifest・ディレクトリ・ファイルの同一性を固定する。
6. 各unlinkの前に削除予定を永続化し、再度dry-run条件、台帳・Collector、ファイル同一性とSHA-256を照合する。削除結果を台帳へ記録してから次へ進む。

検証の読取りは各ファイル最大1GiB。超える候補は削除せず停止する。ファイル名を自由指定する機能、再帰削除、forceオプションはない。

## 台帳と途中失敗

WordPressの非autoload option `odbfs3_cleanup_<set-id>`を使い、既存のCollector・観測・Cron台帳を変更しない。ファイルごとの状態をCASで更新する。

| 状態 | 意味と再開時の扱い |
| --- | --- |
| `prepared` | 検証・対象固定済み。削除予定はまだ記録していない。理由なく不在になった場合は停止 |
| `deleting` | unlinkより先に永続化した削除予定。存在するなら同一性・hashを再検証して残りを処理 |
| `deleted` | unlink成功と不在を確認し、台帳更新済み。同名ファイルが戻っていたら削除せず停止 |
| `absent_after_intent` | deletingの後、再開時には不在だった。応答喪失と整合するが、誰が削除したかは断定しない |
| `absent_before` | 初回受付時から不在。今回削除した件数として扱わない |

1つ目の削除後に2つ目で失敗しても、記録を残して終了する。再実行では削除済みファイルを繰り返し処理しない。ファイルが再作成されていた場合は、同じ内容でも古い台帳で削除しない。台帳保存のACK喪失、unlink直後の台帳障害も同じ記録から照合する。完了後の同一コマンド再実行は台帳を更新しない。

結果の`state=complete`は対象2種が処理済み／不在であること。`blocked`は事前保護、`incomplete`は再検証・削除・台帳処理が完了しなかったことを示す。`files`には読めた永続台帳の状態を表示し、読めなければ空のままとする。失敗結果を成功へ置き換えない。

CLI終了コードは0=complete、2=確認ID不一致等の要求不正、4=blocked/incomplete。必須引数の欠落等をWP-CLI自身がdispatch前に拒否する場合は1であり、WP-CLIの標準エラー出力となる。dispatch後のJSONは1文書で、生の例外や秘密情報を含めない。

## 保持するものと限界

`intent.json`、`database.json`、`media.json`、`child.json`、`transfer.json`、`worker.lock`、メディア子ディレクトリ、Collectorの完了証拠、観測、Cron記録、cleanup台帳は保持する。S3のオブジェクトと保持設定には触れない。削除後のCronはcompleteを読んで終了し、DB一時ファイルを再作成しない。

POSIXの協調ワーカーを対象としたロックであり、同一OSユーザーによる悪意あるunlink直前のパス差替えを完全に封じる機能ではない。特権侵害下の削除保証として扱わない。電源断後にファイルが戻る等、台帳と実体が食い違った場合も再作成ファイルとして停止する。OSクラッシュ耐久性や複数ホストの共有filesystemは今回の受入範囲外。

## 専用環境の検証

[検証集計](recovery-cleanup-execution-validation.json)：追加26項目がPHP 8.1/8.3で合格し、dry-runの29項目も回帰確認済み。実取得試験はnative/PHPそれぞれ保存側47・復元側44チェック。既存の前提試験や各backendの件数は合算しない。

- PHP 8.1/8.3：`tests/manual/test-recovery-cleanup.php`。改変、ロック、未知ファイル、リンク、Collector変更、台帳ACK喪失、削除直後の台帳失敗、2つ目のunlink失敗、途中再開、再作成、確認IDを検証。
- `python3 tools/test-recovery-status-local.py --cleanup-execute-only`：生成fixtureの実WP-CLI。確認なし／不一致を拒否、専用台帳以外のDB内容と証拠を保持、完了後の再実行不変、dry-run候補0を検証。新規optionの作成による採番カウンターは比較から除外する。
- `tools/test-wordpress-recovery-local.sh`：native/PHPで実際に取得・公開したSQL/gzipを削除し、Cron再作成なし・完了証拠不変・元DB破棄後の隔離復元を検証。転送先はディスク上のS3テスト実装であり、実AWS試験ではない。

いずれも生成データの使い捨て環境。試験fixtureの破棄は運用cleanupとは別で、本番workspace・既存FTP/MySQLを対象にしない。
