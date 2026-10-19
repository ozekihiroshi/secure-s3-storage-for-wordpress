# 予定判定設定のブラウザ受入確認

2026-10-20 JST。対象ソースは `641f751`。Backup for S3単独の予定判定設定を、専用WordPress 7.0.2 / PHP 8.3 / MariaDB 11.8と生成データで確認した。共通部品、通知、外部監視、本番設定は変更していない。

## 操作と期待結果

- 管理者で「設定 → Recovery Sets → セット」を開き、予定判定の有効化、初回予定、周期、起動・完了猶予を保存し、再表示された値と判定を確認。
- 2画面を開き、一方で設定変更後、古い画面から保存するとHTTP 400。最新値を維持。
- 停止すると「予定判定を停止中」。過去日時での再開を拒否し、未来日時で再開できる。
- 周期以上の完了猶予、確認欄なしをHTTP 400、不正nonceをHTTP 403で拒否。
- 購読者にはメニューを表示せず、直接GET/POSTも403。管理者フォームの内容を転用しても保存できない。未ログインGETはログイン要求、POSTも拒否。
- 各拒否後、設定が変わっていないことを再読込みで確認。

停止・再開・古い画面の拒否のスクリーンショットも目視確認した。

## 変更されないことの確認

ブラウザ初期化後に基準を採取。ハンドラーのない専用Cronイベントを登録しておき、操作前後でWordPressの`cron`オプション全体のSHA-256を比較する。予定設定を除いたプラグインoptions・active_plugins、およびworkspaceのスナップショットも比較する。予定設定だけは意図的に変更される。

これはCron登録内容の不変性の試験。専用環境では自動WP-Cronを無効化しているため、実際の定期バックアップ起動試験とは区別する。ホストのcrontab変更操作は行わない。ログインに伴うセッション情報までDB全体が不変とは主張しない。

## 試験環境の観測

Edge 155.0.4283.62で、意図的に拒否したadmin-post画面への遷移時に `InvalidStateError: Transition was aborted because of invalid state. ViewTransition opt-in disabled` を観測。個別のnonce拒否でも再現した。ブラウザの画面遷移取消として記録し、プラグインの保存処理の不合格とは扱わない。試験は、この名前・全文・URLに一致するものだけを別記し、その他のpageerrorは失敗にする。

初回試験は機能チェックを通過した後、上記pageerror検査で失敗した。再実行では同値保存が古い画面を作らないことも分かったため、初期値を明示して確実に異なる設定へ更新するよう試験を修正した。製品コードの修正ではない。

## 再実行

1. `tools/test-recovery-browser-local.py`で使い捨て環境を作り、`tools/recovery-browser-relay.py`でループバック接続する。
2. ブラウザ試験の`--warmup`でログイン初期化。fixture-controlの`--canary`で専用イベントを追加し、通常出力をbefore.jsonへ保存。
3. `node tools/test-recovery-schedule-settings-browser.cjs <state.json> <evidence-directory>`を実行。PlaywrightとEdgeは環境変数`PLAYWRIGHT_MODULE`、`BROWSER_EXECUTABLE`で指定する。設定済みの専用fixtureを再試験するときだけ`--existing`を付ける。
4. fixture-controlの通常出力をafter.jsonへ保存し、Cron・保護対象options・workspaceの一致を確認。
5. リレーを停止し、fixture-controlの`--stop`で記録された専用リソースだけを片付ける。

結果は[機械可読記録](recovery-schedule-settings-browser-validation.json)にまとめる。ローカルの画面・詳細ログは`D:\workspace\wp-rescue\release-readiness\schedule-settings-browser`。実S3、配布ZIP更新、本番適用の試験は今回の対象外。
