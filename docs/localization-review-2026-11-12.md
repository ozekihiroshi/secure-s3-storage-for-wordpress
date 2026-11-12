# Settings localization review — 2026-11-12

## Status

Development source only. The published WordPress.org 0.4.2 package, GitHub release assets/tags and production settings have not been changed. No new release ZIP has been published. WordPress.org Japanese Readme translations are prepared but **not submitted or approved**.

## Changes

- `readme.txt` uses English only. Its guide links point to the English guide; the Japanese translation draft points to the Japanese guide.
- The normal backup settings page uses WordPress's gettext translations. The bundled Japanese PO/MO catalog covers all 93 strings in SettingsPage, MediaBackupPanel and ConnectionTester, including buttons, status labels and connection-test messages.
- A single setup-guide button follows `determine_locale()`, including the logged-in administrator's profile language. Japanese opens the Japanese guide; other locales use the English guide. "Site Default" follows the site language. Existing WordPress core translations supply core controls such as Save Changes.
- The text domain is registered on `init`, avoiding premature translation loading. The release builder includes the PO/MO catalog and checks its presence after dependency scoping.
- The Japanese guide uses the actual Japanese control names. Both guides explain that language-aware controls are in development and that public 0.4.2 still has the previous bilingual introduction.
- WordPress.org preparation: [Japanese Readme draft](wordpress-org-readme-ja-draft.txt). It covers the description, installation, external service, FAQ, screenshots, CLI/Recovery Sets and changelog. The text is for translation review and registration, not a replacement for the English package readme. Readme translation approval is separate from the bundled UI translation.

This does not change backup execution, AWS credentials, destination settings, schedules or stored history. Existing history messages, external diagnostic text and technical identifiers are not rewritten. The separate developer-oriented Recovery Sets page and CLI are outside this settings-page translation scope.

## Verification

The local Docker service was slow to start. Verification used **new disposable containers in dm's existing rootless Docker test environment**, not a production WordPress site. The containers used a new internal-only network, no published port, no AWS credentials and WordPress outbound HTTP disabled. They were removed after the run.

Base: validated 0.4.2 ZIP (SHA-256 `30a227b766db693459dfb2a5b2544f95e34d5e56cbd3faeb4c0eda196876f162`), unpacked into a test-only directory with the modified entrypoint, settings class and languages directory overlaid. This was a source-change test, not validation of a new release ZIP.

WordPress **7.1.3**, PHP **8.3.36**. Installed the official Japanese core language pack. Tests logged in through the real login page and requested the real admin settings page over HTTP within the container. They did not force the locale with a test filter.

| Site language | User profile language | Screen and guide | Result |
| --- | --- | --- | --- |
| English | Site Default | English | Pass |
| English | English | English | Pass |
| English | Japanese | Japanese | Pass |
| Japanese | Site Default | Japanese | Pass |
| Japanese | English | English | Pass |
| Japanese | Japanese | Japanese | Pass |

Assertions checked the single correct guide URL, headings, backup/connection/media/save controls and absence of the other language's introductory heading. Interleaving Japanese and English administrators did not leak a previous user's locale. Authenticated connection-test submissions with valid nonces and an empty destination produced the correct English/Japanese validation notices without connecting to S3. Saved plugin settings remained empty and unchanged.

The catalog compiler verifies all settings/media/connection gettext strings are translated, preserves numbered format placeholders and reads the compiled MO back through GNU gettext. PHP/Python syntax and whitespace checks passed. See [machine-readable results](localization-review-2026-11-12.json).

## Reproducing

Run `python3 tools/compile-translations.py` after editing the PO file. It regenerates the MO file deterministically and rejects incomplete translations or changed format placeholders.

`tools/test-settings-locales.py EVIDENCE_DIRECTORY` creates its own isolated Docker network, site volume and containers, and removes those resources in a finally block. The evidence directory needs the unchanged public candidate ZIP and a `language-changes.tar` containing the development entrypoint, `src/Admin/SettingsPage.php` and `languages/`. Set `ODBFS3_TEST_WP_CLI` to an existing official WP-CLI phar; optionally set `ODBFS3_TEST_DOCKER` to a Docker command with rootless socket arguments. The runner downloads the official core Japanese pack on the test host. AWS is not contacted.

The historical ZIP runner retains its original bilingual assertions only for packages containing that older banner. New language behavior is covered by this authenticated-user matrix rather than an artificial locale filter.

## Before publishing

Assign the next release version and validate its exact ZIP, including translation files and Plugin Check. Register the Readme translations against the corresponding WordPress.org strings and obtain the required translation approval; uploading a plugin ZIP alone does not publish the Japanese directory description. Keep the current public ZIPs/tags intact.

## 日本語の要約

通常のバックアップ設定画面を利用者の言語に合わせ、日英併記をなくした。日本語翻訳93文言を同梱し、ガイドボタンは表示言語に合う1つだけにした。readme.txtは英語原文に統一し、WordPress.org用の日本語訳は別原稿として準備した。翻訳原稿は未提出・未承認。

dmの専用rootless Docker試験環境に新しい隔離WordPressを作り、実際にログインして6通りの言語設定を確認した。サイトと利用者の言語が異なる場合、利用者間の切替、接続先未入力時の通知も合格。保存設定は不変で、実S3へは接続していない。作成した試験コンテナは終了後に削除した。本番、公開済みZIP、タグは変更していない。新しい配布ZIPの検証とWordPress.org側の翻訳承認は、次の公開工程で別途行う。
