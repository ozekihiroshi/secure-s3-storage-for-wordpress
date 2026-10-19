# 最終候補ZIPの実S3往復・隔離復元

2026-10-19。対象はOzeki Database Backup for S3の`codex/recovery-operator-plan`。共通部品採用の変更とは独立した試験で、公開・本番配置は行わない。

## 対象と再現方法

前回の新規導入・更新・状態保持を通過した0.3.0候補ZIPをそのまま使用する。SHA-256は`34315c718ac34a970f64dcc5353c1208ec3b40c272be803bdfae127217200d43`。試験補助コードを展開先へ追加するが、製品の`src`と同梱`vendor`は書き換えない。全ファイルのハッシュをZIPと比較し、SSH転送先でも実行前後に照合する。

WordPress 7.0.2 / PHP 8.3 / MariaDB 11.8の専用Docker環境で、nativeとPHPのDB取得方式を別々に試す。S3通信は既存のcommunity接続先のPHP 8.1.2と認証チェーンを利用する。試験用バケットは`ceri-secure-s3-storage-test`、リージョンは`ap-northeast-1`。実行ごとに専用prefixを作り、今回のrun IDは`02175543dc75`。

```bash
ODBFS3_WP_IMAGE=owr-external-lab:local \
ODBFS3_REAL_S3=1 ODBFS3_ALLOW_SYNTHETIC_S3=1 \
ODBFS3_FIXTURE_SUBNET=<未使用の内部サブネット> \
bash tools/test-wordpress-recovery-zip.sh /path/to/verified-candidate.zip
```

このrunnerは開発専用。Dockerイメージ、PHP、SSH接続先と試験prefixのS3権限を事前に用意する。既存サイトへ試験PHPを配置しない。途中失敗時はログと作成済みprefixを確認し、結果不明のPUTを手動再送しない。

## 試験で確認する範囲

**結果：native / PHP両方式とも合格。** 各方式で取得47項目、隔離復元44項目を通過した。各8オブジェクトの全件再取得・サイズ/SHA-256照合、応答喪失後の照合、412競合を確認した。保存量はnative 14,680 bytes、PHP 14,134 bytes（合計28,814 bytes、16オブジェクト）。製品src/vendor 3,884ファイルは両方式で元ZIPと一致した。リクエスト課金等を含む実請求額は測定していない。

1. 生成したDB・画像・日本語/絵文字・投稿参照・シリアライズ設定を取得する。開発者用Cronの重複呼出し、実取得の観測登録、SQL/gzipのcleanup後も完了証拠が残ることを調べる。
2. 保存元DBを破棄し、取得済み資産を実S3へ保存する。DB gzip、media 4件、inventory、完了marker、manifestの計8件を各方式で扱う。
3. manifest PUT成功後の応答喪失を模擬し、再生成したCollectorが追加PUTなしで完了へ照合する。実S3の条件付き書込み競合（412）で既存manifestが変わらないことを調べる。
4. S3から全件を取得し、サイズ・SHA-256を検証する。取得前のファイルをfallbackにせず、新しいvolumeへ受け渡す。
5. 外部通信のない内部ネットワークで復元する。改ざん・欠損・危険なパスはDB変更前に拒否する。DBとmediaの対応、表示HTML・画像のHTTP取得、観測結果の自動登録を検証する。復元したCron、メール、外向きHTTPは実行させない。

## 判定上の制限

この試験は、専用環境で取得した資産を橋渡し処理で実S3へ送信し、保存先参照を再構成する。製品のS3クライアント・Collector・Publisherを使用するが、稼働WordPressの本番ワーカーからS3まで無中断に連続実行した試験や、管理画面での復元操作試験ではない。

今回の応答ではVersion IDが得られていない。bucket versioning / encryption / public access blockの設定読取りはAccessDenied。各保存オブジェクトのAES256応答は確認できるが、バケット設定全体の合格とは扱わない。取得後のサイズ・ハッシュ検証は行うが、固定Version IDによる不変性、Object Lock保持、独立署名、保存と復元の権限分離は本試験の保証範囲外。

WordPressコア・テーマは同じ版を別途供給する。サイト全体の復元や感染前の清浄性を保証しない。長期Cron運転、実サイトでの導入、一般向け作成・復元入口、共通部品統合後の受入は別工程。

## 試験基盤の修正

Windows改行のシェルが開始前に停止したため、Linux改行へ正規化し`.gitattributes`へ設定を追加した。S3試験helperは、ZIP内の名前空間付きAWS例外も捕捉するよう修正した。どちらも試験基盤の変更で、固定済みZIPは変更していない。最初の開始前失敗ログも残す。

結果と各オブジェクトの証跡は[検証集計](recovery-zip-real-s3-validation.json)に記録する。元ログ・取得tar・Collector・locator・実行コードのハッシュ一覧はローカル検証用ディレクトリに保存する。実S3上の今回の生成データは証跡として残し、既存オブジェクトの削除や本番データの送信は行わない。
