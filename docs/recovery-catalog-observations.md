# 最新セット索引と観測結果の集約（開発版）

この機能は共通DB部品採用とは別の`codex/recovery-operator-plan`に実装する。既存Recovery Setの状態・保存時の完了証拠を書き換えず、利用者が最新試行、最後のcomplete、後日の観測を区別して読むための入口である。

## 最新セット

```sh
wp ozeki-database-backup-for-s3 recovery-set latest --source-id=<32桁のsource-id> --format=json
wp ozeki-database-backup-for-s3 recovery-set latest --source-id=<32桁のsource-id> --profile=database_uploads --format=json
```

site-localなWordPress optionsのRecovery Set記録を1回のSELECTで読み、その時点の索引を組み立てる。別の可変キャッシュを保持せず、表示のために索引を保存・更新しない。source-idは必須であり、現在のWordPressから自動推測しない。profile省略時はdatabase/database_uploads両方を対象にする。

`schema_version: 1`、source_id、profile、index_complete、records_scanned、invalid_records、matching_records、truncated、reason_codeと、以下を返す。

- latest_attempt：created_atが最も新しい試行。failedや途中状態も含む。
- latest_complete：保存時のmanifest公開検証まで完了したセットのうち、created_atが最も新しいもの。

同時刻ならset_idの降順で決定する。完了時刻やファイルmtimeでは並べ替えない。両者には[statusの公開項目](recovery-set-status.md)が入る。後日の観測は下記のobservationsコマンドで取得する。status自体のJSON契約は変更していない。

最大100記録を扱い、101件目がある場合はindex_complete=false／index_limit_exceededとする。保存件数制限ではなく、この読取り実装の上限である。破損・巨大・ID不一致の行があってもindex_complete=false／index_unreadable_recordsとし、latest_attempt/latest_completeは両方nullにする。source/profileを判定できない破損行を「対象外」とみなして隠さない。件数が増えた運用には、次段階で上限超過を隠さないページング設計が必要。

索引の対象は保存されたサイト内記録だけ。記録そのものが削除されたセットや別サイト、S3上の孤立資産の発見を保証しない。

## 後日の観測

```sh
wp ozeki-database-backup-for-s3 recovery-set observations <set-id> --format=json
wp ozeki-database-backup-for-s3 recovery-set observations <set-id> --max-age=86400 --format=json
```

complete記録の厳密なmanifestを基準に、保存済み観測だけを集約する。現在のS3への接続や復元試験は行わない。max-ageは1〜31536000秒、既定86400秒。これは表示の鮮度判定窓であり、検証周期や保護保証ではない。

schema_version、set_id、manifest_sha256、observed_at、max_age_seconds、records_scanned、aggregation_complete、invalid_records、truncated、reason_codeを返し、availabilityとrestoreはそれぞれ次の項目を持つ。

- state：最新の有効な観測が鮮度内ならその状態。未観測・古い・不正・矛盾ならunknown。
- recorded_state / checked_at：選んだ観測の元の状態・時刻。古い観測でも残す。表示時刻で検証時刻を更新しない。
- freshness：fresh/stale/unknown。
- environment_id / checks：復元対象の環境IDと実施項目。ある環境の成功を全環境へ一般化しない。
- reason_code：no_observation、observation_stale、observation_conflict、aggregation_incomplete等。

availabilityとrestoreで、それぞれchecked_atが最新の観測を選ぶ。新しいfailed/inaccessible/missingが古い成功より優先する。同じ最新時刻で内容が矛盾する場合はunknown／observation_conflictとし、成功優先の並べ替えをしない。

観測なしはno_observationであり、外部で試験が未実施という断定ではない。期限切れではrecorded_stateを残すが、stateを成功にしない。異なるmanifest/set ID、未来時刻、壊れたJSON・ハッシュ、検証項目不足があれば集約不完全とし、以前の成功を代わりに返さない。100件を超える観測も同様に不完全とする。完了記録がない・読めない・未完了のセットには、観測を適用しない。

## 観測の永続化API

信頼されたローカルの観測処理は、実際の確認後に`WordPressRecoveryCatalog::appendObservation($setId, $record, $nowUtc)`を呼ぶ。recordは既存`RecoveryObservation`の契約（set_id、manifest_sha256、kind、state、checked_at、environment_id、checks）。このAPIは確認作業を実行せず、渡された観測の構造と対応関係を検証する。

正規化した観測バイト列のSHA-256を含む専用option名へ、autoload=noでINSERT IGNOREする。同じ観測の再送は同じIDとなり、既存行を更新しない。異なる観測は追記する。元のCollector記録・manifestを変更しない。書込み結果の照合ができない場合は成功を返さず、呼出し側は同じ観測の再送で照合できる。

通常のstatus/latest/observationsからこのAPIは呼ばれない。任意の「成功」を登録するCLI・REST・管理画面は設けていない。独立署名や観測者の認証を提供する仕組みではなく、WordPress DBへ書ける主体からの改ざん耐性も主張しない。[後続の自動登録実装](automatic-recovery-observations.md)で専用検証・復元処理から接続した。一般利用者向けの実S3定期検証への接続は引き続き未実施。

## 終了コードと境界

0は読取り・集約の成功であり、バックアップや観測の成功ではない。2は引数不正、3は該当セットなし（latestでは条件に合う記録なし）、4は索引/観測の不完全・読取り失敗。期限切れや観測なしは内容として返し、集約可能なら0。phase/state/freshnessとあわせて判断する。

全データ取得はsite-localで、SELECTは件数と行サイズを制限する。DBエラーの生文面は出さず、一時的なエラー表示抑止は処理後に復元する。statusと同様、WordPressや他プラグインの起動全体の副作用まで保証するものではない。

## 専用環境での検証

2026-10-19にPHP 8.1/8.3で追加契約43項目ずつ、実WP-CLIで追加5ケースに合格。[検証集計](recovery-catalog-validation.json)。既存status105項目とRecovery契約127項目も前提試験として通過し、追加機能の件数と合算していない。

```sh
php tests/manual/test-recovery-catalog.php
python3 tools/test-recovery-status-local.py
```

契約試験は最新失敗と過去complete、source/profile、同時刻、破損・上限、manifest対応、鮮度、未来時刻、観測の矛盾、追記の冪等性、JSON/終了コードを検査する。実WP-CLI試験は外部通信できない使い捨てWordPress/MariaDBで、合成complete・failed・観測を登録し、全読取り前後のDBダンプ一致と追記前後の完了証拠一致を検査する。実S3の検証や本番への配置は含まない。
