# Recovery Sets ブラウザ受入（専用環境）

2026-10-19、WordPress 7.0.2 / PHP 8.3 / MariaDB 11.8で、本体プラグイン0.3.0を有効化し、Edge 155の独立したheadlessセッションから検証した。共通部品採用とは別ブランチ。製品PHPの追加修正は不要だった。

## 確認結果

- 管理者の「設定 → Recovery Sets」から一覧、IDリンクから詳細を開ける。
- 完了と失敗、DB/mediaの対応、manifest、取得可否と復元試験を区別して表示する。
- cleanup台帳なしの`not_started`と、模擬台帳の`needs_reconciliation`をそれぞれ確認。後者を現在実行中とは表示しない。
- ID検索はGET。変更・実行ボタンやPOSTフォームはない。不正ID・配列ID・存在しないIDも確認。
- 購読者にメニューはなく、一覧・詳細の直接URLは403。未ログインはログイン画面へ遷移。
- ブラウザのJavaScriptエラーなし。一覧・詳細のスクリーンショットを目視確認。
- WordPress初回管理画面初期化後、21項目のブラウザ操作の前後で、`odbfs3_*`・`secure_s3_*`・`cron`・`active_plugins`の値/名前/autoloadとworkspaceのhash/inode/size/mode/mtimeが一致。

2種類の台帳状態で同じ21項目を実施。42件の独立試験として合算しない。元のcallback25項目とも合算しない。全DBの不変を主張しない（ログインによるユーザーセッション等はWordPressが更新する）。atimeは比較しない。

## 再現手順（開発者向け）

ローカルにDockerのWordPress php8.3-apache、cli-php8.3、MariaDB 11.8が必要。Composer lockに一致する完全なvendorと、Node/Playwright・Chromium系ブラウザを用意する。今回のvendorは既存ローカルディレクトリを読取り専用マウントし、両composer.lockのSHA-256一致を確認した。製品ソースは本ブランチだけをマウントする。

1. WSLで`ODBFS3_TEST_VENDOR`をvendorの絶対パスへ設定。必要に応じて`ODBFS3_BROWSER_STATE`を状態JSONのパスへ設定（全Pythonコマンドで共通）。既定の状態JSONはプロジェクト親の`release-readiness/recovery-browser-state.json`。
2. `python3 tools/test-recovery-browser-local.py`で生成データ専用の環境を作成。本体をWP-CLIで有効化する。既存状態JSONがあれば重複作成を拒否する。
3. 内部ネットワークでポート公開が使えない場合、別のWSL端末で`python3 tools/recovery-browser-relay.py <状態JSONの絶対パス>`を起動。127.0.0.1:18763から専用コンテナへだけ中継する。
4. Node側で`PLAYWRIGHT_MODULE`をPlaywrightモジュール、`BROWSER_EXECUTABLE`をブラウザ実行ファイルのパスに設定し、`node tools/test-recovery-admin-browser.cjs <状態JSON> <結果フォルダ>`を実行。各パスは実行OSから読める形式を使う。
5. 最初の管理画面アクセスでWordPress標準Cronが登録される。初期化後、`python3 tools/recovery-browser-fixture-control.py`の出力を保存し、ブラウザ試験をもう一度実行して同コマンドの出力と比較する。JSONの2つのhashが一致すること。
6. `python3 tools/recovery-browser-fixture-control.py --pending`で専用模擬cleanup台帳を準備。Node側の`EXPECT_CLEANUP=needs_reconciliation`を設定し、再度前後比較とブラウザ試験を行う。これはfixture書込みであり、ページ閲覧とは別の操作。実ファイル削除は行わない。
7. 中継端末でCtrl+C。`python3 tools/recovery-browser-fixture-control.py --stop`で記録された専用コンテナ・ネットワーク・ボリュームを削除する。結果ファイルは保持される。

試験はlocalhost:18763限定。fixture/fixtureviewerは生成された試験専用アカウントで、パスワードはスクリプト記載のfixture-only-long-password。外部公開・実サイト利用は禁止。外部通信はDocker内部ネットワークとWP_HTTP_BLOCK_EXTERNALで遮断、WP-Cronと自動更新も無効。AWS認証・本番データ・通知先は設定しない。

## 環境・試験ツールのメモ（製品不具合と区別）

- CUA/Node REPL初期化でkernel assetsのパスエラー。CLIから独立Edgeプロファイルを起動して代替した。
- 内部Dockerネットワークの公開ポート接続不可はループバック中継で解決。ネットワークの外部遮断は維持した。
- 初版の試験は購読者のログイン先をダッシュボードと固定して待機タイムアウト。実際はprofile.phpへ正常遷移していたため、試験の待機条件を修正した。
- 模擬cleanup seedのwpdb参照不足を修正した。製品コードは変更していない。
- 初回管理画面でWordPress標準Cronが増えるため、初期化前後の総hash一致は成立しない。初期化後のブラウザ操作を比較して一致を確認。プラグインのバックアップCronは未設定。

## 検証範囲

ブラウザ表示・権限・読取り専用性の受入であり、実S3、実バックアップ作成、削除、復元の再検証ではない。Collector/観測/cleanup台帳は生成fixture。既存のCLI/契約試験は別記録。本番配置・公開・共通部品のマージは行わない。
