# 0.4.3 rc.1 — localized setup / 言語別の導入案内

## Status / 公開状態

**Published as 0.4.3 on WordPress.org on 2026-11-12**, unchanged from this verified candidate (SVN revision 3797337). All 3,896 public ZIP files match. See the [publication record](release-0.4.3-publication.md). This release operation did not update the production installation or its settings. Existing ZIPs and tags are preserved.

2026-11-12に検証済み候補を変更せずWordPress.orgへ0.4.3として公開した（SVN 3797337）。配布ZIP全3,896ファイルが一致。本番サイトの更新・設定変更は行っていない。既存ZIP・タグは保全している。

- [GitHub candidate and matching source tag](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/releases/tag/v0.4.3-rc.1)
- Installable ZIP: `ozeki-database-backup-for-s3-0.4.3.zip`
- Plugin header / Stable tag: `0.4.3`
- SHA-256: `ba091c4f24f33905f82530438fccb0bda85ad84b082ecae242dab390f04a2665`
- Source tag: `v0.4.3-rc.1`; GitHub's automatic source archive is not an installable plugin package.

## Changes / 変更内容

Settings and setup guidance follow the administrator's WordPress profile language. Japanese translations cover 93 strings in the normal backup settings screen, media panel and connection tester. One guide button opens the appropriate English or Japanese guide. The English readme is the source for WordPress.org translations. Backup behavior and stored data formats are unchanged.

通常のバックアップ設定画面、メディア欄、接続確認の93文言を日本語化し、管理者のプロフィールの言語に合わせて表示する。ガイドボタンは対応する言語の1つだけ。readme.txtは英語を原文とする。保存処理や保存形式は変えていない。

The Japanese WordPress.org Readme is [prepared separately](wordpress-org-readme-ja-draft.txt), but **not submitted or approved**. Bundled settings translations work independently of that approval. Previously stored history messages, external diagnostics and technical identifiers are not rewritten. The separate developer-oriented Recovery Sets page/CLI remain outside the translation scope.

WordPress.org側の日本語説明は準備原稿の段階で、未提出・未承認。プラグイン同梱の設定画面の翻訳とは別工程となる。既存履歴の文章・外部診断情報・技術的な識別子は書き換えない。開発者向けの別画面Recovery SetsとCLIは今回の翻訳対象外。

## Exact-package validation / 完成ZIPの確認

The ZIP contains 3,896 files. Compared with public 0.4.2, it adds the Japanese PO/MO catalog and changes the entrypoint, settings page, readme and generated scoper loader. The loader only adds aliases for the three WordPress functions used by translation registration. All other packaged files are byte-identical to 0.4.2. The packaged entrypoint/readme/translations match source, the readme is English-only, and the compiled translation is readable. PHP syntax and scoped-dependency collision checks passed during the build.

完成ZIPは3,896ファイル。日本語翻訳2ファイルを追加し、入口ファイル・設定画面・readme・自動生成ローダーを更新した。ローダーの差分は翻訳登録で使うWordPress関数3つのエイリアスだけで、その他の同梱ファイルは公開済み0.4.2とバイト一致。原文と同梱文書・翻訳の一致、readmeの英語統一、翻訳ファイルの読取り、PHP構文と依存ライブラリの名前空間分離を確認した。

On WordPress 7.1.3 / PHP 8.3.36, the exact ZIP passed:

- Fresh installation and Plugin_Upgrader update from public 0.4.2.
- Full installed-file equality with the ZIP; settings, history, schedules, unfinished execution records and private workspace preserved across upgrade, same-version replacement and reactivation.
- The existing 38 guarded settings-handler checks in each installation case.
- Six site/user language combinations after fresh installation and the same six after upgrade (12 cases total), through authenticated real admin HTTP requests. The expected single guide link and translated controls appeared, with no introductory language mixing.
- Interleaved English/Japanese users, localized missing-destination notices and unchanged saved settings.

完成ZIPで、新規導入・公開済み0.4.2からの更新、全同梱ファイル一致、設定・履歴・定期処理・未完了記録・作業領域の保持を確認。同一版の再適用、無効化・再有効化、各38項目の設定処理確認も通過。実際のログイン済み管理画面で、新規導入後と更新後それぞれ言語設定6通り（計12ケース）、利用者の切替、入力不足通知を確認した。

Official Plugin Check 2.1.0 reports **zero errors and one warning** in both installation cases: `PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound`. This is a compatibility-review item about the bundled translation loader, not a failed backup or an admin-screen error. The loader comparison results are recorded below.

公式Plugin Check 2.1.0は両ケースとも**エラー0、警告1件**。同梱翻訳の読み込み関数についての警告であり、保存処理の失敗や画面上のエラーではない。互換性の比較結果は下記に記録する。

### Translation-loader compatibility decision

Keep the standard `load_plugin_textdomain()` call on `init` for older supported WordPress versions when no official plugin language pack is installed. This is not a suppressed warning. WordPress 6.8 added automatic discovery of bundled translation paths from `Text Domain` / `Domain Path` headers ([official explanation](https://make.wordpress.org/core/2025/03/12/i18n-improvements-6-8/)). The plugin still declares WordPress 5.9 as its minimum; that requirement has not been raised.

A separate negative control replaced only the loader call in a disposable installed copy, restarted its web container to exclude cached PHP, and restored the exact original entrypoint afterwards. On WordPress 7.1.3, Japanese remained available without the call. On WordPress 6.7, Japanese disappeared without the call and returned after restoration. The normal six-language-case matrix also passed on 6.7 with the original ZIP. The public candidate archive was never modified by these controls.

この警告は抑制せず記録する。公式の翻訳パックがない旧WordPressでも同梱翻訳を使えるよう、`init`での標準読み込み処理を維持する。別の使い捨て環境で該当呼出しだけを無効にし、PHPキャッシュを除くためWebコンテナを再起動して比較した。7.1.3では呼出しがなくても日本語表示できたが、6.7では日本語が消え、元の処理を戻すと復帰した。元のZIPでは6.7でも言語設定6ケースが通過。既存の最低要件5.9を引き上げず互換性を保つ判断とした。

Detailed machine-readable results: [release-0.4.3-rc1.json](release-0.4.3-rc1.json). The same ZIP checksum and individual reports accompany the GitHub release. These results do not claim that every supported WordPress/PHP combination has been tested.

The disposable tests use dm's dedicated rootless Docker test environment and internal-only networks. They do not use a production WordPress site or AWS credentials, and do not run a real S3 backup. Resources created by the runners are removed afterwards. The earlier source-level checks are recorded in the [localization review](localization-review-2026-11-12.md).

確認にはdmの専用rootless Docker試験環境を使用し、本番サイトとは別の使い捨てWordPress・内部ネットワークで実施する。AWS認証情報は渡さず、実S3へのバックアップは行わない。作成した試験用リソースは終了後に削除する。
