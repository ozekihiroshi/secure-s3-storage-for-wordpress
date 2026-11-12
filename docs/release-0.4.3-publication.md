# 0.4.3 publication / 公開記録 — 2026-11-12

Version **0.4.3 is published on WordPress.org**, using the verified 0.4.3 rc.1 package without rebuilding it.

- [WordPress.org](https://wordpress.org/plugins/ozeki-database-backup-for-s3/) / [public ZIP](https://downloads.wordpress.org/plugin/ozeki-database-backup-for-s3.0.4.3.zip)
- SVN revision **3797337**, committed by `ozekihiroshi` at `2026-11-12T01:44:33.847253Z` (10:44 JST). The user authenticated and committed; the public distribution was independently checked afterwards.
- Source commit: `74b90522ff111bc7e138a72704d9f02720f02ecb`.
- [GitHub release](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/releases/tag/v0.4.3-rc.1): promoted from candidate to published release, preserving its original tag `v0.4.3-rc.1` and attached assets.

## Verification

- Public API reports version 0.4.3 and links to the English setup guide in Description and Installation.
- All **3,896 files** in the WordPress.org ZIP are byte-identical to the validated candidate, including the Japanese translations.
- SVN changes are confined to trunk and the new `tags/0.4.3`. Every older SVN tag retains its previous last-change revision.
- No existing GitHub ZIP or tag was replaced. Production installation, S3/IAM settings and backup schedules were not changed by this publication operation.

| Archive | SHA-256 |
| --- | --- |
| Validated / GitHub ZIP | `ba091c4f24f33905f82530438fccb0bda85ad84b082ecae242dab390f04a2665` |
| WordPress.org ZIP | `729f038076660f7595f1cc26ad5565a36ec2b79404a8c6bb2d61d0e1130ea5e1` |

WordPress.org regenerates the archive, so ZIP hashes differ; all contained files match. See [machine-readable publication evidence](release-0.4.3-publication.json) and [pre-publication checks](release-0.4.3-rc1.md). Plugin Check reported zero errors and one documented compatibility warning; the translation loader was retained after testing older WordPress behavior.

The WordPress.org Japanese description remains a prepared draft, not submitted or approved. Bundled Japanese settings translations are included in the published ZIP and do not depend on that approval.

## 日本語

0.4.3をWordPress.orgへ公開済み。ユーザーが端末で認証・コミットし、その後に公開APIの版番号・導入ガイドへのリンク、配布ZIP全3,896ファイルの候補との一致を確認した。ZIPはWordPress.org側で再生成されるためハッシュが異なるが、中身はすべて一致している。

GitHubリリースを公開済みとして更新し、元の候補タグ・添付ファイルは維持する。旧SVNタグと既存ZIPも保全。本番への導入やS3/IAM・定期バックアップ設定は変更していない。WordPress.org側の日本語説明は未提出・未承認であり、今回公開した設定画面の同梱日本語翻訳とは別の工程である。
