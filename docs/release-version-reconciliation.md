# WordPress.org・GitHub・開発候補の版番号整理

> 履歴資料。2026-10-27にはWordPress.org 0.4.0配布とGitHub v0.4.0を確認済み。
> 以下は当時の調査を保持する。現在の状態は [製品レビュー](product-quality-review-2026-10-27.md) を参照。

2026-10-20 JSTに公式APIと配布ZIPを確認。

**同日の対応完了:** [GitHub v0.3.0](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/releases/tag/v0.3.0)を公開済みコミットへ追加し、WordPress.orgと同一のZIPを添付・再取得してSHA-256一致を確認。独立改善ブランチは[0.4.0](release-notes-0.4.0.md)へ採番した。以下の表と差異は対応前の調査記録であり、旧ZIPの証跡を保持するため残している。

| 対象 | 確認結果 |
| --- | --- |
| WordPress.org公式API / 配布ZIP | 公開版0.3.0。最終更新2026-10-17 08:44 UTC |
| GitHub main | `627e47c2b31527ef1486e582b79fd5dba875843f`。プラグインヘッダー0.3.0 |
| GitHub最新Release・タグ | v0.2.1。v0.3.0は未作成 |
| 独立改善ブランチの今回の試験ZIP | ヘッダー0.3.0だが、公開版とは異なる未公開改善版 |

GitHubソースが0.2.1のままなのではなく、タグ・Releaseの追随が不足している。また、開発候補に公開済みと同じ番号を使い続けたため、配布物の識別が不十分になっていた。従来文書の「0.3.0開発候補」は、このブランチの未公開ZIPを指すもので、WordPress.orgに0.3.0が未公開という意味ではない。

## 実体の比較

WordPress.org配布ZIP SHA-256：`ea7c6a3cd7f76f949a95af296af0c799556f38aeb2c249aed950f9ef2d6d8080`。
手元に保存されていた2026-10-17の公開用0.3.0 ZIPと、展開後の全ファイル名・内容が一致した。ZIP圧縮物自体のハッシュと展開ファイルの一致は区別する。

今回の試験ZIP SHA-256：`4a92b0e4ff85f16b34254f41ba54ea626ccaf050f82fd77930e0d6d8b4b80797`。
公開版に比べ、製品src・入口・uninstall・readmeを対象とする比較では追加18ファイル、変更4ファイル。予定設定・実行記録などの改善を含み、公開版0.3.0と同一ではない。

GitHub生ソースと配布PHPは名前空間分離ビルドによる変換があるため、単純なバイト差を機能差とは扱わない。今回の全ファイル一致は、公開ZIPと保存済み公開用ZIPの比較結果である。

## 公開前の整理方針

1. 公開済み0.3.0のソース・公開用ZIPとの対応を根拠に、GitHubのv0.3.0タグとReleaseを補う。新しい改善ブランチをv0.3.0として登録しない。説明は公開済み機能の範囲に限定する。
2. 今回の改善版は別の次版へ採番する。案は開発中`0.4.0-dev.1`、正式配布`0.4.0`。公開済み0.3.0の上書きや既存タグの移動はしない。
3. 採番変更時はヘッダー・定数・Stable tag・変更履歴を揃え、再ビルドした最終配布物を検証する。本試験のZIPを改名だけして新バージョンとみなさない。

今回は指定された最終ZIPの実S3検証を固定ハッシュで実施するため、そのZIPの版番号・内容を変更していない。GitHubタグ・Release作成、WordPress.org再公開も未実施。共通部品の採用は別変更として維持する。

確認元：[WordPress.org公式API](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=ozeki-database-backup-for-s3)、[GitHub main固定コミット](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/tree/627e47c2b31527ef1486e582b79fd5dba875843f)、[GitHub Releases](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/releases)。公開応答とZIP比較の原本は`release-readiness/schedule-settings-direct-s3/`に保管。
