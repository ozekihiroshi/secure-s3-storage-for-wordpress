# 独立製品としての整理・品質確認（2026-10-27 JST）

> 以下は初回整理の記録。その後の依頼により0.4.1へ採番し、最終ZIPの受入検証へ進んだ。
> 最新の準備状態は [0.4.1検証報告](release-0.4.1-validation.md) を参照。公開自体は行わない。

## 結論と基準

Ozeki Database Backup for S3は独立したWordPressプラグインとして維持する。
基準はGitHub `main`（調査時 `dafa66348bc9854db7fb3c14933628956e3d3599`）。
今回の作業はこのmainからの `codex/backup-product-quality`。Resilienceの導入は不要。
共通化のためのコード統合は行わない。

依頼時の「WordPress.org公開0.3.0、main開発0.4.0」は現時点と異なる。
[WordPress.org](https://wordpress.org/plugins/ozeki-database-backup-for-s3/)の版表示と
[0.4.0配布ZIP](https://downloads.wordpress.org/plugin/ozeki-database-backup-for-s3.0.4.0.zip)
を確認し、ZIPのプラグインヘッダーとStable tagはいずれも0.4.0だった。
取得したZIPのSHA-256は `8988a2635f1e98e0fbdfdd47eb7820e5575a656962422fab4de0b056ec038c02`。
過去の固定候補ZIP `4876c397…` と同一アーカイブではない。今回、公開ZIP全体の
実行・復元試験や過去候補との全ファイル同一性を確認したとは扱わない。
公開ZIPのreadmeにも「development candidate」が残っていたため、現在の説明を訂正した。

## ブランチ・PR・タグ・成果物

| 対象 | 確認した関係 | 判断 |
| --- | --- | --- |
| `main` / `origin/main` | GitHub mainは `dafa663`、PR #2のmerge commit。ローカルmainは取得前 `627e47c` だった | 今後の製品開発は最新origin/mainから開始。古いローカルmainを現在版と誤認しない |
| `v0.3.0` | `627e47c`、内部Recovery Set基盤の公開履歴 | 保存する。既存タグやZIPを置換しない |
| `v0.4.0` | `dafa663`、現在のGitHub mainと一致 | 公開版の固定点として保存する |
| `codex/recovery-operator-plan` | `e97c273`。PR #2はマージ済み。mainとのtree差分なし | 開発ブランチとしての役割は完了。削除は任意であり、必要な未追跡成果物の退避と所有者判断後に限る |
| `codex/shared-backup-core` | `b2e78eb`。PR #1はopen Draft、4コミット、mainへ未採用 | 共通DB部品の独立した実験。今回の改善・次回更新の前提にしない。保留を推奨 |
| `codex/backup-product-quality` | 最新mainを基に今回作成 | 保護修正と製品文書だけをレビューする作業ブランチ |

[PR #1](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/pull/1)は、
Resilience側 `packages/backup-core` を保守元とする固定版の同梱と互換aliasが目的。
別プラグインを実行時に要求する設計ではないが、現在mainとの競合があり、
採用する場合は現行main上で互換性と配布ZIPを改めて検証する必要がある。
共通部品の版・出所・ハッシュ・取り込み責任をこの製品で管理し、自動追随しない。

[PR #2](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/pull/2)は
0.4.0の独立した運用改善として取り込み済み。0.3.0との差は主として
status/latest/observations、管理画面、実行記録、予定判定、開発者Cron入口、
観測配送、限定cleanupとその試験。DBダンパー・Composer依存・共有DB部品の採用変更ではない。
比較は `git diff v0.3.0 origin/main`。旧タグv0.1.0、v0.1.0-pre-release、
v0.1.1、v0.2.0、v0.2.1も保持されている。調査時の未マージPRは #1 のみ。

既存のshared-core作業場所にあったREADMEとshared-backup-core文書の未コミット変更、
未追跡 `ozeki-markdown-documents/` と `tools/prepare-wordpress-release-030.sh` は変更しない。
既存operator作業場所と過去の試験ZIP・JSONも再利用して書き換えない。
未マージPRのマージ、既存ブランチ削除、履歴書換え、公開操作は行っていない。

## 実装に基づく保証範囲

| 観点 | 現在の処理・根拠 | 保証しないこと／注意 |
| --- | --- | --- |
| SQL生成 | `NativeMySqlDumper` は終了コードと非空出力を確認。`PhpMySqlDumper` は別接続でsnapshot transactionを開始し、schema/行取得・書込み・commit失敗を例外化 | 非空と終了成功は全SQLの意味的正しさや全DBオブジェクトの復元を証明しない。非トランザクション表・実行中DDLは完全snapshotの保証外 |
| gzip | `DatabaseBackupService` と `GzipVerifier` が元SQLのサイズ・SHA-256、展開上限、CRC、切断・余剰データを検証 | 圧縮照合は元データの業務的完全性検証ではない |
| S3保存 | `S3Storage` がSHA-256付きPUT後、HEADのサイズとchecksumを比較。ランダムキーと `IfNoneMatch=*` | HEAD時点の保存整合性。各バックアップの独立GET・importは通常処理に含まない。後日の可用性・改ざん耐性ではない |
| 失敗と保持 | DB例外時は成功結果を返さず、CLI/Cronの当該成功後retentionへ進まない。今回、後日のretentionでも残す世代を再照合 | アップロード済み失敗残留物は存在し得る。失敗物があることを成功表示の根拠にしない |
| メディア対象 | `MediaSource` / preparationがsingle-site uploads配下の通常ファイルを列挙。隠しファイルも拡張子だけでは除外しない。symlink・特殊ファイル・不正パスは拒否 | core/themes/plugins/config、別ディレクトリ、multisite対応は対象外。unsupported entryは黙って除外するのではなく失敗させる |
| インベントリ | sorted inventory、SHA-256、plan chain、件数・bytes・inventory完了を `MediaUploadStep` が照合。part checksumと完了HEADも確認 | 列挙・hash取得・転送は時間を要する。全uploadsの同時点snapshotではなく、実行中の更新を止める運用が必要 |
| メディア完了 | 各オブジェクト・inventory検証後にconditional `complete.json` とそのHEAD照合。途中失敗はジョブ成功にならない | marker PUT後の応答喪失などでremote markerとローカル状態が一致しない場合は確認が必要。存在するmarkerだけでサイト復旧成功としない |
| Recovery Set関連付け | frozen set/source/destination、DB・media job ID、manifestの資産キー・サイズ・hash・scope・requirementsを検証。manifestはHEADと独立GETで固定バイトを照合 | DBのみprofileのcompleteはメディアを含まない。DBとメディアの同時点一致ではない |
| 完成・不足 | `RecoveryCollector` / `RecoveryStatus` がphase、none/partial/complete、DB/media/manifest検証状態を分離。profile不足は完成扱いしない | statusは保存された証拠の読取り。現在S3から消えた資産はstatusだけでは発見しない。別途availability観測が必要 |
| 過去の検証 | `RecoveryObservationSummary` はmanifest hash、検証時刻、対象checks、environmentと鮮度を保持。stale/conflict/incompleteを成功へ昇格させない | freshでも指定した観測時点・検査項目の記録。現在の自動再検証や独立署名ではない |
| 運用状態 | DB履歴・メディア進行/失敗表示。Recovery Setは実行・未完了・予定時刻と猶予に基づく評価を表示 | standalone DBに全件の実行開始/heartbeat/未実行通知はない。Recovery Setの予定判定もlocal/read-timeで外部監視ではない |
| Cron | DBは日次WP-Cron、メディアは明示起動後のworker。Recovery Setは開発者の明示登録で1回1工程 | アクセスなしでは遅延。予定判定設定の停止は実Cron停止ではない。DBのtransient lockは全経路の厳密な排他・長時間worker生存証明ではない |
| 一時ファイル | 通常DBはfinallyで自身のSQL/gzipを片付ける。private fileと既存ファイル上書き拒否。Recovery cleanupはlock/hash/journalで限定削除 | 強制終了やunlink失敗の完全清掃は保証しない。Recovery workspaceと証拠は保存し、容量管理は運用責任 |

## 今回修正した小規模な不備

### 1. 保持枠に検証未完了のオブジェクトを数えていた

従来retentionは名前とLastModifiedでソートして先頭K件を残した。
例えばkeep=2で古い正常A、新しい検証失敗B、さらに正常Cがある場合、
C成功後にB+Cを保持枠としてAを削除できてしまう。当該失敗時にretentionを止めるだけでは
後日の削除を防げない。

変更：新規DB PUTに元gzipの `Metadata['odbfs3-sha256']` を同梱。
削除候補を返す前に保持枠K件全てをHEADし、一覧サイズ・実サイズ・S3 SHA-256・
元digestメタデータを照合する。1件でも不足・不一致・拒否なら候補を返さず例外で停止。
CLI/Cronの既存処理が「バックアップ成功、retention失敗」と分離して報告する。
以前は応答失敗でも保存自体が正常だったオブジェクトは、新しい照合に通れば保持資材に数えられる。
その場合も過去の失敗履歴を書き換えたり、復元試験済みに昇格させたりしない。

移行：0.4.0以前のメタデータがない保持世代は確認不能として削除を中止する。
新しい照合可能なK世代が揃えば、それより古い従来形式のバックアップを通常どおり整理できる。
データ移行や古いオブジェクトへの書込みはしない。keepが大きいほど移行中は容量が増え得る。
keep以下しか存在しない場合は削除も追加HEADも行わない。
新しいIAM操作は追加しないが、保持枠へのHEAD/checksum読み取り権限と最大K回の追加リクエストが必要。
メタデータは同じ信頼境界内の照合証拠であり、署名や第三者の復旧保証ではない。

### 2. 削除対象の名前判定が広すぎた

従来はDB prefix内の `db-` で始まり `.sql.gz` で終わるファイルを広く対象にしていた。
手動保存した `backups/database/manual/db-precious.sql.gz` まで候補になり得る。
変更：日付階層、DB名の許可文字、同じ日付のtimestamp、任意の128bit suffixという
実際の生成形式に限定する。旧timestamp形式は継続対応。削除直前の名前ガードも同じ形式を使用。
単独DB用prefixは他サイトと共有しない。形式を意図的に模倣した別資産まで識別する仕組みではない。

### 3. S3一覧の不完全なページングを受理していた

`IsTruncated=true` なのに次tokenがない場合、途中の一覧で削除候補を作れた。
tokenの繰り返しには終了条件がなかった。変更：完了フラグ、contents型、
次tokenの存在と再使用、同じkeyの重複を確認し、不正な一覧では削除計画を中止する。
正常な複数ページ、同一時刻の既存sort、keep設定の正の整数仕様は維持する。

## 今回の検証

検証コマンド・確定結果は [今回の検証記録](product-quality-validation-2026-10-27.md) に記載する。
既存試験を利用し、追加はAWSへ通信しないretentionの回帰試験1本と、PUTメタデータの
実SDKシリアライズ確認。新しい試験基盤・本番DB・実S3削除は使用しない。
過去の実S3・隔離復元・配布ZIP試験は [証拠索引](verification-index.md) から辿れる。
過去の試験成功を今回変更後の配布物に転用しない。

## 残す制約と次回WordPress.org更新

| 区分 | 今回の判断 |
| --- | --- |
| 実装範囲外 | 汎用create/restore UI、全障害自動再開、通知/watchdog、100件超catalogのページング、全サイトsnapshotは実装しない。今回の限定修正の完了条件に追加しない |
| 追加検証 | 今回の変更を含む最終ZIPでPlugin Check、新規導入・0.4.0からの更新、実S3メタデータ/保持ガード、必要な隔離復元を確認。SSE-KMS・多数世代・長期間運用・幅広いWP/PHP互換性は未認証 |
| 運用制約 | source更新停止、外部Cron、private workspaceの容量、独立した復元手順、専用prefix。S3の別主体による変更・削除や照合後の競合は防げず、必要なら外部の保全方針で扱う |
| 保持の境界 | HEADは保存整合性のみ。S3 version単位の保持、Object Lock、署名、独立した復旧権限はない。retentionと外部削除の原子的な処理は提供しない |

次回は0.4.0を置換せず、新版（小修正なら0.4.1を候補）として採番する。
リリース時にheader・Stable tag・changelogを揃え、固定ZIPのhashと試験範囲を記録する。
現時点ではソース修正のレビュー用で、採番・公開・新タグ作成はしていない。
新規部品統合や大規模な残課題の解決を、この更新の前提にしない。
