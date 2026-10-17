# Ozeki Database Backup for S3 復旧基盤の現状監査

## 対象と結論

後続の開発変更は [DBバックアップの整合性検証](database-integrity-verification.md) を参照。本表は変更前のcommitに対する監査記録として残す。

2026-10-17 時点の `aeaf6b9de3084113d6cd2ae40cb8215a56afe110`（0.2.0）を、Upgrade Plan — Recovery Integrity Baseline と照合した。
メディアにはファイル単位の整合性確認と独立復元の土台がある。一方、DBはアップロード成功と復旧可能性の検証がまだ分離されておらず、DBとメディアを結ぶ Recovery Set は未実装である。

入力計画は `D:\Downloads\ozeki-database-backup-for-s3-upgrade-plan.md`。
SHA-256: `0a3697b5857c769b5ba4cb351fa4a1880d7afbff86fc5197c13ad775b27966d7`。
節番号はこの計画に対応する。以下のソース・テストパスはリポジトリルート基準。

今回は機能を変更していない。本番、S3、既存DB、Docker構成、配布ZIPも変更していない。
既存の未追跡ディレクトリ `ozeki-markdown-documents/` は別プロジェクトとして対象外。

## 判定の意味

- **実装済み**: 記載した範囲の処理がコードにある。あらゆる環境での動作保証ではない。
- **一部対応**: 部品や試験はあるが、要件全体の保証・導線・証跡が足りない。
- **未実装**: 対応する製品機能または統合試験を対象ソース・テスト・文書から確認できない。
- **検証待ち**: 実装や試験手段はあるが、その条件での実行確認が残る。

実装判定とは別に、今回実行した試験と過去の実機記録を末尾に示す。
`succeeded`、アップロード成功、独立復元成功は同じ意味として扱わない。

## DBの要件対応

| 計画 | 要件 | 判定 | 根拠と不足 |
| --- | --- | --- | --- |
| 1・4.1 | 手動・定期・CLIバックアップ | 実装済み | `src/Admin/SettingsPage.php`、`src/Cron/DatabaseBackupCronHandler.php`、`src/Cli/BackupCommand.php`。実環境での今回の再実行は対象外。 |
| 4.1 | SQL生成と非空確認 | 実装済み | `src/Backup/Database/NativeMySqlDumper.php`、`src/Backup/Database/Php/PhpMySqlDumper.php` が存在・サイズ確認。非空はSQLの完全性そのものの証明ではない。 |
| 4.1・14.1 | native/PHP fallback | 実装済み | `DumpBackendSelector.php` と両dumper。`tests/manual/test-dump-backend-selector.php`、`test-proc-open-disabled-fallback.php` 等の試験あり。 |
| 4.1 | gzip生成と書込み処理 | 実装済み | `src/Backup/Compression/GzipCompressor.php` と `CompleteStreamWriter.php`。部分書込み・ゼロ/false書込みの単体試験を今回実行。 |
| 4.1 | gzipの妥当性検証 | 一部対応 | 出力非空確認はあるが生成後の展開・内容照合はない。`gzclose()` の戻り値も未確認。`test-gzip-compressor.php` はDB依存の生成試験で、破損検出試験ではない。 |
| 4.1 | S3へのDB送信 | 実装済み | `src/Aws/S3Storage.php::upload()` が `putObject`。ローカルサイズとETagを返す。 |
| 4.1 | S3上のサイズ・metadata検証 | 未実装 | DB経路にはアップロード後の `HeadObject` 照合がない。返すサイズはローカルファイルの値。接続テストはDBオブジェクトの検証ではない。 |
| 4.1 | DBのSHA-256計算・永続化 | 未実装 | `DatabaseBackupService.php`、`DatabaseBackupResult.php`、`BackupHistoryEntry.php` にその証跡がない。SDKの転送時チェックと製品の検証記録は別物。ETagをSHA-256とみなさない。 |
| 4.1・7.2 | DBのS3取得・照合・展開 | 未実装 | `DatabaseBackupService` は送信後にローカルSQL/gzipを片付ける。独立取得から展開までの検証導線はない。 |
| 4.1・14.1 | 隔離DBへのimport試験 | 検証待ち | `tests/manual/test-database-restore.php` にnative/php選択、専用DB名制約、fixture、import後比較がある。今回未実行。現状はローカルSQLからの試験でありS3/gzipを通らない。 |
| 18 | 曖昧さのないDBバックアップID | 一部対応 | DB名＋秒単位時刻でキーを生成。同一秒・同一prefix/DB名では同一キーになり得る。`putObject` に上書き防止条件なし。並行呼出しによる再現試験が必要。 |

DBバックアップの実行エラーは例外により失敗扱いになるが、「正常に送信できたものが独立復旧できる」ことは別途確認が必要である。

## メディアの要件対応

| 計画 | 要件 | 判定 | 根拠と不足 |
| --- | --- | --- | --- |
| 4.2 | inventoryとファイルごとのSHA-256 | 実装済み | `src/Backup/Media/MediaManifest.php`、`MediaEntry.php`、`MediaSource.php`。順序・重複・footer・総量も検証。今回inventory試験93項目合格。 |
| 4.2 | 全inventory項目のアップロード | 実装済み | `MediaUploadStep.php` がplan chain、offset、件数、バイト数、inventory完了を検査してからマーカーを書く。今回転送試験50項目合格。 |
| 4.2 | S3サイズ・checksum照合 | 実装済み | 小さいobjectはSHA-256、大きいobjectはpart checksumとcomposite checksumを照合。`HeadObject` の長さとchecksumが不一致なら失敗。compositeはファイル全体のSHA-256とは別。 |
| 4.2 | 欠落・変更の検出 | 一部対応 | scan/hash時の変更、転送時のサイズ・checksum不一致に対する防御と試験がある。稼働中uploads全体の原子的snapshotではない。送信済みファイルの後続変更やDBとの時点一致まで保証するものではなく、更新停止の運用条件がある。 |
| 4.2 | incomplete multipartを完了扱いしない | 実装済み | `MediaUploadStep.php` のpart列挙・照合・complete・Head確認。途中状態は別checkpoint。再試行と応答喪失の試験あり。 |
| 4.2 | 最後に完了マーカーを書く | 実装済み | 全データとinventoryの処理後に `complete.json` を条件付き作成し、マーカーもHead検証する。Headの応答喪失で「マーカーはあるがジョブは未完了」の可能性は区別する。 |
| 5.3 | メディアの既存objectを上書きしない | 実装済み | jobごとのprefix、`IfNoneMatch: *`、plan/checkpoint検証。DB側には同等の保護がない。 |
| 7.2 | 別ディレクトリの独立復元と照合 | 実装済み | `MediaManifest::verify()` と `tools/test-media-s3-fixtures.php`。後者は制約付き検証用であり利用者向け復元コマンドではない。実S3復元の過去記録あり。 |
| 13 | exact failed job cleanup | 実装済み | `MediaFailedJobCleanup.php` と `MediaCommand::cleanup()`。記録済みupload IDと専用workspaceが対象。完了済みobjectは削除しない。今回cleanup試験の再実行はしていない。 |
| 10・12 | 未記録multipartの孤立対策 | 一部対応 | ID保存前の停止では未記録uploadが残り得る。`docs/media-upload-worker.md` はlifecycleと運用での発見を説明。自動探索・バケット設定変更はしない。 |
| 14.2 | 実AWS・実Cron・強制終了・並行実行の現行版確認 | 検証待ち | モックの中断・retry・lease試験と過去の実AWS記録がある。今回のHEADについて全条件を実機再検証したわけではない。 |

## 復旧セットと状態管理

| 計画 | 要件 | 判定 | 根拠と不足 |
| --- | --- | --- | --- |
| 4.3 | DBとメディアのRecovery Set | 未実装 | DBはS3キー、メディアはjob IDで別管理。同じ復旧点を意図した組み合わせを機械可読に関連付ける機能なし。 |
| 5.1・5.2 | DB・media・環境・検証情報のRecovery Manifest | 未実装 | `MediaManifest` はメディアのinventoryであり、この統合manifestではない。 |
| 5.3 | 統合manifestのschema・秘密情報除外・発行条件 | 未実装 | メディアinventory/markerにはformat/versionがあるが、新しいRecovery Manifestの契約は未定。 |
| 6 | complete/partial/failed/unverified | 未実装 | DB履歴はsuccess真偽値、メディアは実行状態。プロファイルに必要な構成要素から復旧準備状態を判定していない。 |
| 7.3 | 復元試験日時・対象ID・結果・環境の製品証跡 | 未実装 | 検証文書・fixture結果はあるが、任意のバックアップに紐付く統一的な製品出力なし。 |
| 8 | 履歴の基礎情報 | 実装済み | `BackupHistoryEntry` にsuccess、日時、DB名、backend、bucket/key、size、message。 |
| 8 | 開始/完了時刻・checksum・検証・set ID・retention結果 | 一部対応 | DB履歴とmedia jobが別構造。retentionの説明はメッセージに入るが、統一された構造化証跡にはなっていない。 |
| 8・12 | 秘密を含まない失敗分類 | 一部対応 | DBは一般化したメッセージ、mediaは `step_failed` 等。raw例外を保存しない処理はあるが、認証/権限/整合性/容量/変更等を運用判断できる分類が不足。 |
| 14.3 | DBとmediaを戻しWordPress参照まで確認する試験 | 未実装 | DB単体importとmedia単体復元は存在する。両者＋manifest＋投稿/添付参照の一連の統合試験はない。 |

## 運用と文書

| 計画 | 要件 | 判定 | 根拠と不足 |
| --- | --- | --- | --- |
| 4.4・15 | 対象/対象外の説明 | 一部対応 | `readme.txt` はDBとsingle-site uploads、theme/plugin/wp-config等の除外を説明。サーバ、Web、cron、証明書など別途必要な復旧資材を一枚のチェックリストにまとめる必要あり。 |
| 9 | 次回予定・Cronイベント有無 | 実装済み | `BackupScheduleManager::isScheduled()`、`getNextScheduledTimestamp()` とstatus表示。 |
| 9 | 最終成功した定期バックアップの判別 | 一部対応 | `StatusCommand` は成功履歴を表示するが、履歴に実行元区分がないため定期実行の成功だけを区別できない。 |
| 9 | overdue・stale警告とhealth判定 | 未実装 | 次回予定表示はあるが、経過時間からの健全性判定はない。media stale-worker対策とは別要件。 |
| 9 | OS schedulerの説明と非自動変更 | 実装済み | `readme.txt` にWP-Cronの限界とOS/Docker CLIの例。システムcronは導入しない。 |
| 10・11 | default credential chainとWPへの鍵非保存 | 実装済み | `src/Aws/S3ClientFactory.php` にcredential直指定なし。readmeにも長期鍵入力欄を持たない方針を明記。 |
| 10 | S3接続のread/write/delete検査 | 実装済み | `ConnectionTester` がランダムtest keyで検証・削除。これはバックアップ本体の検証ではない。 |
| 10 | DBのみ/media/retention/restore別IAM例 | 未実装 | readme・docsに用途別の完成した4種のポリシー例はない。接続テスト自身にもread/deleteが必要な点を権限設計に含める。 |
| 10 | prefix・暗号化・Public Access Block案内 | 一部対応 | prefix設定とアクセス/暗号化の運用責任の説明はある。復旧時の読取り、KMS、用途別権限を含む具体的手順が不足。 |
| 10 | DB retention | 実装済み | `S3BackupRetentionManager` は対象prefix/命名に合うDBのみ扱う。手動UIと定期/CLIで適用条件が異なる。media retentionは明記された対象外。 |
| 10・18 | 最小権限・KMS環境での実動作 | 検証待ち | 実S3履歴はあるが、今回用途別ポリシー/KMSを実証していない。 |
| 11 | 非AWSの認証手順 | 一部対応 | default chainによる他環境の可能性を説明するに留まる。webroot外credentials、runtime secrets、短期認証等の選択・導入・検証手順が不足。 |
| 11 | 非AWS各認証方式の実動作 | 検証待ち | SDK対応をそのままプラグインの検証済み環境とは扱わない。 |
| 12 | 安全な診断一覧 | 一部対応 | 接続テスト、backend、予定、履歴、media状態とworkdir検証はある。credential source分類、復旧set、overdue、孤立multipartを統合したhealth出力はない。 |
| 13 | backup/status/media操作CLI | 実装済み | `src/Cli/CommandRegistrar.php` と各Command。media statusはJSON。 |
| 13・16 | 安定した機械可読の共通CLI契約 | 一部対応 | DB statusは翻訳付きの表示文字列。全コマンド共通のschema/exit code/互換性契約を明文化・試験化する必要あり。 |
| 13 | verify/manifest inspection/health CLI | 未実装 | 登録コマンドに該当機能なし。名前は復旧モデルを決めてから確定する。 |
| 15 | 復旧手順・障害分類・非AWS案内 | 一部対応 | 開発者向けの試験手順はある。利用者がコードを読まずにDB＋mediaを安全に戻す一連の手順は不足。 |
| 3・16 | 汎用コアと案件固有機能の分離 | 一部対応 | 現行は汎用バックアップ機能。外部連携が依存できるversion付き状態契約が未整備。特定案件用dashboardやone-click restoreは今回追加しない。 |

## テストの照合結果

### 今回実行したテスト

WSL Ubuntu-24.04 / PHP 8.3.6。下記PHPコマンドはリポジトリルートで実行し、すべて終了コード0。
外部DBやAWSへは接続しない。テスト専用一時fixture以外のサイトデータは使っていない。

| コマンド | 結果 |
| --- | --- |
| `php -d memory_limit=32M tests/manual/test-media-upload.php` | PASS、50 checks |
| `php -d memory_limit=32M tests/manual/test-backup-job-runner.php` | OK、49 checks |
| `php -d memory_limit=32M tests/manual/test-media-inventory.php` | OK、93 checks |
| `php -d memory_limit=32M tests/manual/test-complete-stream-writer.php` | OK |
| `php tests/manual/test-media-s3-client.php` | PASS、11 checks。実SDK＋ローカルHTTPハンドラー、ダミー認証情報。 |
| `node tests/manual/test-media-admin-js.js` | PASS。Windows Node 24.19.0、模擬DOM/通信。 |

### 既存試験の範囲と不足

| 計画 | 既存の根拠 | 次に必要な確認 |
| --- | --- | --- |
| 14.1 DB | `test-mysql-dumper.php`、`test-php-mysql-dumper.php`、`test-database-restore.php` 等 | 隔離DBで両backendを実行。空/Unicodeのfixtureに加え、大量行、切断、圧縮破損、S3失敗、S3からの取得・展開・importを一連で試験。 |
| 14.1 圧縮 | `test-gzip-compressor.php`、今回のstream writer試験 | 現行gzip試験はDB依存で生成確認中心。生成物の展開一致、破損/途中切断、finalize失敗の注入が不足。 |
| 14.2 media | inventory/upload/SDK試験、preparation/hash/cleanup/admin等の専用試験 | 今回未実行の専用試験を後続で再実行。権限拒否は非root、強制終了・Cron停止は隔離実環境で確認する。 |
| 14.3 recovery | DB単体importとメディア独立復元 | 同一fixtureのDBとmediaを取得して、投稿・添付ファイル参照と除外資材まで検査する統合受入試験を追加する。 |

CIの定義では、`backup-job-tests.yml`、`media-inventory-tests.yml`、`media-upload-tests.yml` がPHP 8.1/8.3でジョブ・メディア系を試験する。
`plugin-check.yml` にはZIP作成後のPlugin Checkがある。一方、DBの実import・S3からのDB復旧を日常的に回すworkflowはない。
workflowの存在は現在のCI成功を意味しない。本監査ではリモートCIの再実行や結果確認はしていない。

### 過去の実機証跡

- `docs/aws-media-transfer-test-2026-08-31.md`: 実S3でmultipartの型差異を発見・修正し、fixture復元を検証した記録。
- `docs/aws-media-cron-single-event-test-2026-09-01.md`: commit `adc1a7627b8b442278676402cd18646a2ed8a2b7` のscoped ZIP、実WordPress HTTP Cron、2,006ファイル・1,107,367,888 bytes、独立ダウンロードと全ファイルSHA-256一致の記録。

これらは実機根拠として有用だが、今回のHEADを再試験した結果ではない。後者もDB・添付参照・サイト全体の復旧ではないと明記している。

## 優先課題と次の実装順

1. **DB検証の強化（B）**: ローカルgzip妥当性、SHA-256、S3サイズ/checksum検証と証跡。検証が失敗した場合は成功履歴・成功後retentionへ進めない。独立ダウンロード→展開→隔離importの試験を用意する。
2. **識別と上書き防止（B/C）**: DBキーの秒単位衝突を解消する。旧キーを読むretentionとの互換性を保つ。UI/CLI/Cronを跨ぐ並行実行も試験する。
3. **メディア保証範囲の確定（D）**: 既存処理を活用し、変更停止条件、途中停止、マーカーの存在とjob状態の差、未知multipartの扱いを試験・文書で固定する。
4. **Recovery Setとmanifest（C）**: DBとmediaのID、開始/終了時刻、必要な構成要素、除外資材、検証段階を定義する。原子的snapshotとは表現しない。
5. **統合復元手順（E）**: 本番を上書きせず、別DB・別ディレクトリからWordPress参照まで確認し、対象ID・結果・環境を証跡として残す。
6. **運用と配布（F/G）**: overdue、失敗分類、CLI契約、用途別IAM、非AWS手順、CI、ZIP/Plugin Checkの順で固める。

検証レベルは「送信済み」「リモート整合性確認済み」「復元試験済み」を区別する。
manifest発行後にretentionや外部操作で資材が消える可能性もあるため、「過去の検証結果」と「現在も復旧資材が揃うか」を分けて設計する。
manifest導入時はDB retentionが関連資材を消す場合のルールを必ず決める。

## 計画18の完了条件の判定

| 完了条件 | 現状 |
| --- | --- |
| DBを独立検証できる | 一部対応：ローカルimport試験はあるがS3経路が不足 |
| mediaを独立検証できる | 実装済み：fixture用手段と過去の実S3証跡あり |
| 構成要素のIDが曖昧でない | 一部対応：mediaはjob ID、DBキーの衝突対策が残る |
| 復旧関係が機械可読 | 未実装 |
| 不完全をcompleteにしない | 一部対応：mediaの防御はあるが統合状態とDB独立検証がない |
| 必要な資材の識別 | 一部対応：範囲説明はあるがset単位で識別しない |
| 不足資材の識別 | 未実装：set単位の判定なし |
| 復元手順の文書化 | 一部対応：開発用手順中心 |
| 隔離環境の復元試験 | 一部対応：単体試験の土台はあるがDB＋media統合がない |
| AWS認証情報を露出しない | 一部対応：非保存・安全なエラー処理あり。全診断経路/環境の非漏洩確認は継続が必要 |
| 最小権限IAM | 一部対応：用途別ポリシーと実機検証が残る |
| Cron健全性の判定 | 一部対応：予定表示はあるがoverdueなし |
| 運用に役立つ失敗理由 | 一部対応：安全な一般化はあるが分類不足 |
| 小規模サイトで簡単に運用 | 一部対応：基本UIはあるが復旧確認の導線不足 |
| 案件固有の要件を外部拡張できる | 一部対応：共通の安定出力契約を整備する必要あり |

Milestone Aの要件整理は完了。次はBを中心に進める。未実装の要件を「検証だけで済む項目」と混同せず、実装後の受入条件としてこの表を更新する。
