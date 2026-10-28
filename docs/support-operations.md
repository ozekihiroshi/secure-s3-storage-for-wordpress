# 不具合受付・通知の運用記録 / Support operations

確認日 / Checked: 2026-10-28 JST。管理者 / Maintainer account: `ozekihiroshi`。

## 受付先・確認結果 / Routes and verified settings

- [GitHub Issues（日英テンプレート）](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/issues/new/choose)：日英テンプレートを追加し、GitHubの選択画面に表示されることを確認済み。接続確認・バックアップ・検証などの対象操作と実行方法を記入できます。
- GitHubの **Watch → Custom → Issues** を有効化し、保存後のチェック状態を確認済み。
- [GitHub通知設定](https://github.com/settings/notifications)の **Participating, @mentions and custom** は **on GitHub / Email** がともに有効。Watchingも同じ配信設定。
- [WordPress.orgサポート](https://wordpress.org/support/plugin/ozeki-database-backup-for-s3/)：新規トピックのメール購読を有効化済み。管理者本人が **Subscribe to this plugin → OK** を操作し、**Unsubscribe from this plugin** へ変わったことを報告（2026-10-28）。WordPress.org登録メールアドレスへの通知です。

Japanese/English bug report templates were added and verified in GitHub's chooser. They ask for the affected operation and how it was started. **Watch → Custom → Issues** is enabled and was verified after saving. GitHub notification settings enable both **on GitHub** and **Email** for custom subscriptions and Watching. On WordPress.org, the maintainer personally confirmed **Subscribe to this plugin → OK** and reported the resulting **Unsubscribe from this plugin** state on 2026-10-28. New forum topics are subscribed by email to the WordPress.org account's registered address.

## 運用 / Operations

- 不具合は日英テンプレートで受付。質問にはBlank issueも使用できます。公開の報告に認証情報や非公開データを含めないよう案内しています。
- GitHubとWordPress.orgに同じ報告がある場合は相互リンクし、修正の追跡はGitHub Issuesにまとめます。WordPress.orgの審査メールは利用者サポートとは別です。
- 通知設定は管理者アカウントごとの設定です。担当者を追加・変更した際は、各担当者が購読とメールの受信先を確認します。
- 確認用Issue・トピックは投稿していません。通知設定の確認であり、実際のメール到着テストは実施していません。コード・ZIP・タグは変更していません。

Bug reports use the Japanese or English template; questions may use a blank issue. Keep credentials and private data out of public reports. Cross-link duplicate reports between GitHub and WordPress.org, and track fixes in GitHub Issues. Plugin review emails are separate from user support. Subscriptions are per account: each new maintainer should check subscriptions and email delivery. No test issues/topics were posted or email delivery tests performed. Code, ZIPs and tags were unchanged.
