# 検証・復元結果の自動観測登録（開発版）

共通DB部品の採用とは別の`codex/recovery-operator-plan`で実装。実処理が通過した確認項目から観測を生成し、既存のmanifestに対応付けて登録する。任意の成功ラベルをCLIから投入する機能ではない。

## 実装した接続

- `ObservedRecoveryRun`：一つの永続実行枠に対して一度だけ検証・復元処理を開始し、確認項目、生成した観測、登録状態を保存する。
- `WordPressRecoveryObserver::forSet()`：completeなセットのmanifestを読み、WordPressの専用実行枠と`appendObservation`へ接続する。ヘルパー生成だけでは書込みや実行をしない。
- 既存の専用WordPress復元試験：保存したSQL・inventory・marker・全mediaの読み戻し検証からavailability観測を自動登録。別環境でSQLを投入し、WordPress表示、mediaハッシュ、attachment参照を確認した後にrestore観測を自動登録する。

実行状態は`running → delivery_pending → registered`。結果には`operation_passed`と`registration`を別々に返す。登録に失敗しても検証・復元結果を消さず、登録待ちを成功扱いしない。

## 実処理からの利用

信頼されたPHP処理が、永続化した実行IDを指定する。例は接続方法であり、次の関数名は利用側が実装する検証処理を表す。

```php
$run = WordPressRecoveryObserver::forSet(
    $wpdb, $setId, $persistedRunId, 'restore', $environmentId
);
$result = $run->execute(function (ObservedRecoveryRun $run) {
    $run->check('sql_import', fn () => importAndConfirmSql());
    $run->check('wordpress_content', fn () => checkRestoredWordPress());
    $run->check('media_hashes', fn () => verifyAllRestoredMedia());
    $run->check('attachment_references', fn () => checkAttachmentReferences());
});
```

各checkは実処理がtrueを返した後にだけ記録される。false・例外・必要項目不足は成功にしない。checkの例外を利用側が捕捉しても、その実行を成功へ戻さない。database単独profileの必須項目は既存`RecoveryObservation`契約に従う。

復元の失敗はfailed、可用性確認の一般的な失敗はunknownとして、通過済み項目だけを残す。通信エラーだけでmissing/inaccessibleを断定しない。生の例外・SQL・パス・接続情報を観測記録へ保存しない。

## 登録失敗時の再開

登録待ちになった場合は、同じ実行IDとmanifest・kind・environmentでヘルパーを再構築し、`deliver()`だけを呼ぶ。元のchecked_atと観測内容を保持して再送する。登録先の保存後に応答が失われても、既存のハッシュ固定の追記APIが重複を防ぐ。

`execute()`は既存実行枠に対して再実行を拒否する。途中停止してrunningが残った場合も、deliverは成功観測を作らず拒否する。実際の復元先や進捗の確認と再開判断が必要であり、runningを自動的に消してやり直さない。実行記録の保存失敗・競合も例外として止める。

WordPressの実行枠は`odbfs3_observer_run_<run-id>`。セット本体の`odbfs3_recovery_<set-id>`と分離し、autoload=no、既存のCAS方式を使用する。ジョブ実行IDとこの記録をcleanup対象にする規則はまだ導入していない。

## 復旧先と保管先

復元前はWordPressを起動できない場合がある。その場合は汎用`ObservedRecoveryRun`へ、呼出し側が所有する永続`JobStore`と登録先sinkを渡す。信頼できる元manifestを確定してから観測を開始し、別セットや別環境の記録へ送らない。

専用試験ではprivateなファイルjournalで復元を追跡し、復元後に独立した管理用optionsテーブルへ登録する。元のcomplete記録は試験開始時に保存した正確な記録を管理側へ用意する。復元サイトに含まれる古いCollector状態を上書きしない。この管理用テーブルは同じ試験DB内にあり、物理的な管理分離・独立署名を証明するものではない。

manifestの識別ができない段階の失敗を、推測したセットへ登録しない。現在の専用復元試験では、パッケージ事前検証を通してmanifestを確定した後のSQL投入・復元検証をrestore観測対象にする。事前検証の失敗は既存試験結果に残り、restore成功観測は生成されない。

## 専用試験と残る範囲

2026-10-19にPHP 8.1/8.3で追加契約20項目ずつ、実WordPress 7.0.2でnative/PHPそれぞれ保存側43項目・復元側44項目を確認。[検証集計](automatic-recovery-observations-validation.json)。件数は試験内の確認回数であり、独立した機能数として合算しない。

```sh
php tests/manual/test-observed-recovery-run.php
ODBFS3_WP_IMAGE=<WordPressとmariadb-clientを備えた専用イメージ> \
  bash tools/test-wordpress-recovery-local.sh
```

実試験は合成WordPressデータ、native/PHPダンプ、保存元DBの破棄、別の空DBへの復元、外部通信を遮断したネットワークで行う。ディスク上の模擬オブジェクト保管を使用し、今回の実行ではAWSへ接続しない。観測の自動登録・集約表示・元の完了証拠の保持を検査する。S3 bridgeがmanifestを作り直す場合にも、同じ新manifestのCollector記録を渡すコードを整えたが、この変更で実S3試験を実行したとは扱わない。

一般利用者向けの復元CLI、Cronからの自動検証、別ホストへの認証付き配送、runningからの復元処理自体の自動再開は今回の範囲外。本番配置や共通部品PRのマージも行わない。専用fixtureへの実処理接続と、製品側から利用できる観測実行基盤の実装である。
