# WordPress実ワーカーからのS3直接保存・隔離復元

対象は`codex/recovery-operator-plan`。共通部品変更とは独立した試験で、専用WordPress・専用DB・生成データだけを使用する。製品コードは前回の最終候補ZIP（SHA-256 `34315c718ac34a970f64dcc5353c1208ec3b40c272be803bdfae127217200d43`）から取り出し、書き換えない。

結果の機械可読な記録は[検証集計](recovery-direct-worker-s3-validation.json)。前回の橋渡し方式の記録は別に保持する。今回の既存コンテナはWordPress 7.0 / PHP 8.3 / MariaDB 11.8で、前回のローカルWordPress 7.0.2と区別する。

**2026-10-19、run `54a405194fef`でnative/PHP両方式とも合格。** 各方式で直接取得・保存48項目、隔離復元44項目。各8オブジェクトの新規取得と検証が成功し、ワーカーのmanifestとS3のmanifestは同一だった。保存量はnative 15,746 bytes、PHP 15,200 bytes（計30,946 bytes）。製品src/vendor 3,884ファイルは実行前後と元ZIPで一致した。

両方式で復元観測の`passed`登録と集約を確認した。S3応答の暗号化はAES256、Version IDはnullであり、今回も固定版保持の合格とは扱わない。元ログ・取得資産・Collector・復元観測・コードハッシュはローカルの`release-readiness/direct-s3-worker/`に保管する。S3上の生成データは保持し、既存資産は削除しない。

## 経路

WordPressを起動し、`WordPressRecoverySchedule`に登録したhookを明示的に呼び出す。実際の`RecoverySetCronHandler`、WordPress上のCAS台帳、`RecoveryCollector`、`RecoveryPipeline`、`RecoveryPublisher`が、製品`MediaS3Client`を通して実S3へ直接保存する。前回のdisk transportへの保存や、別プロセスによるmanifestの保存先書換え・再公開は行わない。

保存先は既存試験バケット`ceri-secure-s3-storage-test`、`wordpress-test/direct-worker/<run-id>/<backend>/`。DB gzip、media 4件、inventory、完了marker、manifestを保存する。native/PHPのDB取得方式を順番に実行する。保存ワーカーはEC2ホストネットワーク上の制限付きコンテナを使い、既存インスタンスの認証チェーンを利用する。認証情報のPCへの搬出、IAM変更、既存のDB・サイトのmountは行わない。

保存完了・取得観測・cleanupを確認した後、保存元DBコンテナを破棄する。空の取得先を持つ別プロセスでS3からダウンロードし、保存ワーカーが作ったmanifestとCollector内のmanifestが同じこと、全資産のサイズ・ハッシュが一致することを確認する。このダウンローダーは読取り専用の試験補助で、S3へ再公開しない。

復元先は別DB・別site/private領域。外向き通信のないDocker内部ネットワークを用い、取得済みファイルを読取り専用で渡す。保存元site/private/cache領域やEC2認証情報は渡さない。復元前の改ざん・欠損拒否、投稿・画像・シリアライズ設定、HTTP表示、復元観測の登録を確認する。

## 再実行と資源

`tools/test-wordpress-direct-s3-remote.py`は、専用EC2で動かす開発用runner。`/tmp/odbfs3-direct.*`に固定候補の`candidate.zip`、リポジトリの`tests/`と`tools/`だけを含む`helpers.tar.gz`を置いて実行する。WordPress CLI/apacheとMariaDBの既存Dockerイメージを使用する。新しい実行IDを採番し、既存prefixを再利用しない。

DBは192MiB/0.35CPU、ワーカーは256MiB/0.4CPUに制限する。取得側のDB公開はループバックのみ、復元側は公開ポートなし。ワーカーは専用ディレクトリ所有者と同じ非root UIDで実行する。失敗した実行を再開する場合は、まず残った証跡とS3状態を調べる。今回のrunnerは一般利用者向け導入機能や再開CLIではない。

## 保証しない範囲

実製品ワーカーをWordPressのhookから実行するが、管理画面での有効化操作、HTTPアクセスによる`wp-cron.php`の自動起動、複数プロセス・複数日の継続運転は別試験。Recovery Set作成には開発者の明示的接続が必要という現在の製品仕様を変えない。

取得は専用fixtureのread-only補助、隔離復元は既存の開発用復元試験を使用する。一般向け復元UI/CLIを提供したとは扱わない。WordPressコアとテーマは別途供給する。バージョン固定保持、Object Lock、独立署名、保存/取得ロール分離、侵害前の清浄性は本試験の保証外。

## 試験環境の準備時の問題

最初の試行ではDocker内部ネットワークのDBポートを取得できず、ワーカー起動前に停止した。次の試行はcapabilityを落としたrootとbindディレクトリの所有者が一致せず、WordPress起動前に停止した。どちらもS3書込み前で、元ログを保存した。取得用ネットワークと復元用内部ネットワークを分け、UIDを揃えた。WordPressが早期終了しても正常完了と誤認しないよう、完了Collectorの存在・phaseも検査する。製品欠陥の試験件数には混ぜない。
