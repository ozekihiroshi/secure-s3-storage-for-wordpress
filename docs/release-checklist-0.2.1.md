# 0.2.1 公開準備

2026-10-17時点で公開候補ZIPのローカル検査は完了。公開は未実施。
README改訂・検査注記・リリース文書をcommitしてpushし、そのcommitのCI成功を確認してから公開する。

## 公開候補

- ファイル: `build/ozeki-database-backup-for-s3-0.2.1.zip`
- サイズ: 8,207,651 bytes
- SHA-256: `059204332e09db69b156da02dfbb696d3c23594899d90a2d4ef2c3384a6829f6`
- プラグインヘッダーとreadme.txtのStable tag: `0.2.1`
- 英語公開文: [release-notes-0.2.1.md](release-notes-0.2.1.md)
- 利用目的・復旧範囲: [README.md](../README.md)

本番へ先に適用した0.2.1 ZIPとはハッシュが異なる。今回の製品ソース変更は
`S3Storage.php` の例外引き継ぎに関する検査注記と改行のみで、動作の変更はない。
本番適用ZIPは `build/ozeki-database-backup-for-s3-0.2.1-production-20261017.zip`
として保存した。公開準備中にAWS本番の再更新は行っていない。

## 完了した検査

- 依存namespace分離ビルド、構文検査、scoped release衝突試験。
- DB整合性44項目と履歴表示テスト。
- ZIP圧縮整合性検査。トップディレクトリは正しいslugのみ。
- ZIP内に開発build・tests・.git・.env・wp-config.php・SQLバックアップがないことを確認。
- 既存の隔離WordPress 7.0.2 / PHP 8.3環境で0.2.0からZIP更新成功。
- Plugin Check 2.1.0でエラーなし。

先行commit `8f1eedc65b16400c4d994b0a46257962af1a2336` のリモートCIは
Backup Job、Media Upload、Media Inventory、Database Integrityの4系統で成功。
[Plugin Check](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/actions/runs/39003897663)
のみ、内部例外の引き継ぎを出力とみなす指摘で失敗していた。
画面出力ではない箇所に限定して理由付きのPHPCS注記を追加し、今回のZIPでローカル再検査を通した。
修正後commitのリモートCI結果は別途必要。

## 公開時の手順

1. README、S3Storageの注記、リリース文書だけの差分を確認してcommit・pushする。
   無関係の未追跡 `ozeki-markdown-documents/` とbuild領域は含めない。
2. そのcommitの全CI、特にPlugin Checkの成功を確認する。
3. 上記ZIPのSHA-256を再確認する。再ビルドでハッシュが変わった場合は新しいZIPを再検査し、この記録も更新する。
4. WordPress.org SVN作業コピーの状態と既存タグを確認し、検査済みZIPの中身をtrunkと新規0.2.1タグへ準備する。
   既存タグは書き換えない。SVNへのcommitは公開操作なので、公開承認後に行う。
5. 同じソースcommitにGitタグとGitHub Releaseを作り、検査済みZIPと英語リリース説明を添付する。
6. WordPress.org配布版のバージョン・内容を確認し、READMEの公開版記載を0.2.1へ更新する。

ここではタグ作成・GitHub Release公開・WordPress.org SVN送信は行っていない。
READMEはGitHub向けであり、WordPress.orgの説明ページにはreadme.txtが使われる。
readme.txtには0.2.1の変更履歴、必要権限、検証失敗時の扱い、復元との区別を記載済み。

## 検証範囲

合成DBでの実S3取得・隔離復元と、承認済み本番DBの保存・整合性検査は
[本番更新記録](production-update-2026-10-17.md)および
[実S3試験](aws-database-integrity-test-2026-10-17.md)を参照。
SSE-KMS、最小権限ポリシー、大容量負荷、サイト全体の復旧を検証済みとは扱わない。
