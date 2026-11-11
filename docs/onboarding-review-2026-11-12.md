# 導入案内の確認 / Onboarding review — 2026-11-12

## 変更内容

AWSに詳しくない利用者向けに、[日本語](getting-started-ja.md)・[英語](getting-started.md)の導入ガイドと、初回用IAMポリシー全文を追加した。環境の利用可否、S3・IAMの用語、AWSコンソールの手順、既存ロールを壊さない追加方法、WordPressの入力欄、初回保存の成功確認とエラー時の次の操作を説明する。

`readme.txt`のDescriptionとInstallationからガイドへリンクし、次の公開時に「詳細を表示」から到達できる形にした。設定画面上部にも日英併記の案内を追加。保存処理・AWS認証・権限検査・スケジュール処理は変更していない。

## 隔離確認と修正

本番とは接続しないローカル内部ネットワークのWordPress 7.1.3 / PHP 8.3.36を使用。公開済み0.4.1のコピーに変更したSettingsPage.phpだけを配置した。実際のSettingsPage::render_page()をWordPress上で呼ぶ確認用ページをChromeで開き、英語・日本語指定の両方で次を確認した。通常の管理画面メニュー全体やWordPress.orgの詳細モーダルそのものの試験とは区別する。

- 上部の日英案内が読め、各ガイドへのリンクが正しい。
- 保存先は空欄、ScheduleはDisabled、Retentionは0。
- Save Changes、Test Connection、Backup Nowの順序と名称がガイドに一致。
- ガイドと違っていたprefixの入力例を`sites/my-site/`へ統一。
- 薄い入力例は保存済み設定ではないことを日英ガイドに追記。
- JSONはRaw表示から全文をコピーする手順を追記。
- Recent Backups / Verificationという実際の欄名を明記。
- 初回IAMポリシーでは接続確認ファイルだけ削除可能なため、readmeの保持削除案内にも追加権限の準備を明記。

PHP構文、ポリシーJSON構文、ローカル文書リンクを確認した。画面は日英併記の新しい案内を検証しており、既存画面の全翻訳を追加・保証する変更ではない。

AWS側では既存実装のAPIと公式手順を照合したが、この確認で新しいバケット・IAMロールを作成したり、実S3へバックアップしたりはしていない。初めてAWSを設定する人による実利用試験も未実施。導入画面の接続確認は書込み・読取り・試験ファイル削除を伴うことをガイドに明記した。

## 公開状態

この変更はGitHubの次回公開用ソースと文書の変更。公開済みZIP・タグ・WordPress.orgの説明・本番設定は変更しない。GitHubのガイドはpush後に閲覧できるが、既存インストールの案内とWordPressの「詳細を表示」へ反映するには、別途公開手順が必要。

## English summary

Added matching English/Japanese guides and a complete first-backup IAM policy, linked from readme Description/Installation and a bilingual introduction on the settings page. An isolated WordPress 7.1.3 / PHP 8.3.36 instance rendered the actual settings class in a review harness, inspected with Chrome in English and Japanese modes. Links, empty destination fields, disabled schedule, retention zero and button labels matched the guides. The prefix placeholder, placeholder explanation, Raw JSON copy instructions, history labels and retention permission guidance were corrected.

PHP/JSON syntax and local links were checked. This was not a live AWS provisioning/backup test, a novice user study, a full admin-navigation test or a test of the live WordPress.org details modal. Published ZIPs/tags and production settings remain unchanged. GitHub guidance becomes available after push; WordPress.org and installed-plugin changes require a later release.
