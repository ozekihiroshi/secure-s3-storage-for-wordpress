# 0.4.2 公開記録 / Publication — 2026-11-12

WordPress.orgへ0.4.2を公開済み。検証済み0.4.2 rc.1の内容を再ビルドせず公開した。

- [WordPress.org](https://wordpress.org/plugins/ozeki-database-backup-for-s3/) / [配布ZIP](https://downloads.wordpress.org/plugin/ozeki-database-backup-for-s3.0.4.2.zip)
- SVN revision: **3797305**, committed by `ozekihiroshi` at `2026-11-12T00:43:22.483807Z`（09:43 JST）。ユーザーが端末で認証・公開を実行し、その後に独立して反映を確認した。
- 対応ソース：`0477c803c6c7467487f3682da8811f5345f90238`、既存タグ`v0.4.2-rc.1`を維持。
- [GitHub Release](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/releases/tag/v0.4.2-rc.1)：説明・公開状態を更新。既存の添付ZIPとタグはそのまま。

## 反映確認

- WordPress.orgの公開APIで版番号`0.4.2`を確認。
- 公開APIのDescriptionとInstallationに、日英の導入ガイドへのリンクがあることを確認。
- 公開配布ZIPを取得し、全**3,894ファイル**が検証済みZIPとバイト単位で一致。
- SVNの変更対象はtrunkの3ファイルと新規`tags/0.4.2`だけ。既存SVNタグの最終変更リビジョンは公開前と一致。
- 本番設定、既存のGitHub添付ZIP・タグは変更しない。今回の公開で本番プラグインの更新やS3設定は行っていない。

| ZIP | SHA-256 |
| --- | --- |
| 検証済み・GitHub配布ZIP | `30a227b766db693459dfb2a5b2544f95e34d5e56cbd3faeb4c0eda196876f162` |
| WordPress.org配布ZIP | `d42329d0f0d1febfee139b2861f83e29f63946b6476b54fb72eb13c121d5e499` |

WordPress.orgでZIPが再生成されるためアーカイブのハッシュは異なるが、同梱ファイルはすべて一致している。

[公開前の検証結果](release-0.4.2-rc1.md)と[機械可読の公開確認結果](release-0.4.2-publication.json)を参照。

## English

Version 0.4.2 is published on WordPress.org at SVN revision 3797305. The user authenticated and committed the release; subsequent independent verification confirmed version 0.4.2 in the public API, English/Japanese setup-guide links in both description and installation, and byte-identical contents for all 3,894 files in the public ZIP versus the validated candidate. Archive hashes differ because WordPress.org regenerates ZIPs.

The GitHub release description/status is updated while preserving tag `v0.4.2-rc.1` and every existing asset. Only trunk's three changed files and a new SVN 0.4.2 tag were published. Existing SVN tags, production settings and older release artifacts remain unchanged.
