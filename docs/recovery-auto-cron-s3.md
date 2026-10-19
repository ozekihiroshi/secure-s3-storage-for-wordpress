# 自動WP-Cron・重複アクセス・停止再開の実S3試験

対象は`codex/recovery-operator-plan`の専用試験。共通部品の変更とは独立し、固定候補ZIP `34315c718ac34a970f64dcc5353c1208ec3b40c272be803bdfae127217200d43`の製品src/vendorを変更せず使用する。

**2026-10-19、run `53af17ef468d`でnative/PHP両方式とも合格。** 自動起動、8件の並行通常アクセス、DB保存後の強制停止、実時間のリース期限経過後の同一セット再開、完了後の再起動による状態保持、S3全資産の新規取得・検証を確認した。停止前のSQL/gzipは完了後も同じハッシュで、再取得されていない。

各方式8オブジェクト、native 28,900 bytes / PHP 28,161 bytes（計57,061 bytes）。製品src/vendor 3,884ファイルは元ZIPおよび実行前後で一致。WordPress 7.0 / PHP 8.3 / MariaDB 11.8。記録は[検証集計](recovery-auto-cron-s3-validation.json)、元ログ・snapshot・取得資産はローカル`release-readiness/auto-cron-s3/`。今回もS3のVersion IDはnull、暗号化応答はAES256で、固定版保持の合格とはしない。

## 自動起動の範囲

生成データの専用WordPressに、開発用MU bootstrapで`WordPressRecoverySchedule`を登録し、試験用2秒間隔のイベントを設定する。試験制御はサイトのトップページへ通常のHTTP GETを送る。`do_action()`やhandlerの直接呼出しで保存を進めず、WordPressがloopbackで起動する`wp-cron.php`のイベント処理を使う。各callbackで`DOING_CRON`を記録する。

MU bootstrapと短い試験間隔は試験用で、配布プラグインの自動設定UIや本番スケジュールではない。WP-Cronはアクセス起点なので、アクセスがないサイトの時刻どおりの起動を保証しない。実サイト向けの外部cron設定・有効化UI・長期運転は別工程。

## 試験手順と境界

1. 完了台帳のない専用DB/siteで、通常HTTPアクセスから製品Pipeline/Publisherを実S3へ接続する。
2. 製品PipelineのDB保存処理が返った直後に、試験用wrapperで待機する。製品のリースや判定は変更しない。8件の並行通常アクセスを送り、同じ処理が二重に進まずCollectorとworkspace内容が変わらないことを確認する。
3. 専用HTTPワーカーコンテナを強制停止する。専用DBとworkspaceを保持し、新しいコンテナで同じ設定を起動する。停止前後のCollectorとworkspaceハッシュを照合する。
4. 元の60秒リースが実時間で切れるまで待つ。台帳、時刻、WordPressのCron lockを編集しない。通常アクセスから自動処理を再開し、同じintent/set IDで完了することを確認する。
5. 完了後も重複アクセスとワーカー再起動を実施する。同日の完了台帳とworkspaceを保持し、`already_complete`が返ることを確認する。
6. 保存元DBを破棄し、別プロセスの空ディレクトリへS3から取得する。元のmanifestとCollectorのmanifestが同一で、DB/mediaのサイズ・ハッシュが一致することを確認する。

並行アクセスの試験はWordPress標準Cronロックによる抑止を含む。製品Collectorへ強制的に同時実行を到達させる負荷試験や、WordPressロックを回避した攻撃試験とは区別する。停止点はDB PUTが返った後で、PUT通信中の結果不明や任意のクラッシュ位置を網羅しない。

前回の隔離復元44項目の結果は[直接ワーカー試験](recovery-direct-worker-s3.md)に保持する。今回は自動Cronと中断再開後のS3資産取得・検証が対象で、隔離WordPressの表示試験を繰り返した件数には数えない。

## 資源と保護範囲

既存の試験バケット`ceri-secure-s3-storage-test`の新規prefixに生成データだけを保存する。S3上の既存資産を削除せず、IAM・本番サイト・既存FTP/MySQLを変更しない。専用DBは192MiB/0.35CPU、専用PHPワーカーは256MiB/0.4CPUに制限し、HTTPはホストのループバックだけで待ち受ける。外部WordPress HTTP/mailは拒否し、自分自身の`wp-cron.php`だけ許可する。AWS SDKのS3/認証通信は製品の経路を使用する。

同一EC2の既存認証チェーンを使う。固定Version ID、Object Lock、独立署名、権限分離の受入とはしない。試験コード、元ログ、状態snapshot、コードハッシュを残して判定する。
