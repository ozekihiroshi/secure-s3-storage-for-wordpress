# Recovery Set status（開発版）

既存のサイト内Recovery Set記録を読み取るWP-CLI入口。通常のDBバックアップ履歴からセットを推測・作成しない。既存の`status`コマンドは変更しない。

```sh
wp ozeki-database-backup-for-s3 recovery-set status <32桁のset-id>
wp ozeki-database-backup-for-s3 recovery-set status <32桁のset-id> --format=json
```

IDは小文字16進32桁。既存セットのIDを指定する。IDは管理画面の一覧、latest、作成処理のreportから確認できる。status自体は一覧取得や作成を行わない。WordPressを読み込めるローカルWP-CLI実行権限が必要。

後続実装として、[latest索引と観測集約](recovery-catalog-observations.md)を別サブコマンドに追加した。このstatusの既存出力・観測範囲は維持している。

## JSON schema_version 1

- set_id/source_id/profile/created_at：セット識別情報。時刻は既存記録のUTC ISO 8601。
- phase：database/media/assemble/ready/publishing/complete/failed。エラー応答はunknown。
- completeness：none/partial/complete。未検証の途中データは充足と数えない。DB単独profileでmediaがないことは不足ではない。DB検証済みでもmanifest公開検証前はpartial。
- assets：検証済みDB/mediaのset IDとmedia job ID。同じセットに属することは既存契約で検証してから出す。
- verification：DB/mediaの保存時検証状態と時刻。mediaを要求しないprofileはnot_included。
- manifest：ローカル記録の有無、SHA-256、保存時の公開検証状態と時刻。ローカル記録の存在だけでcompleteにしない。
- capture_consistency：manifestに記録された整合性。未作成ならnull。uncoordinatedを整合済みと解釈しない。
- availability：この操作はS3へ問い合わせないためunknown、checked_atはnull。
- restore：この入口は別の復元観測記録をまだ取り込まないためnot_tested、checked_atはnull。外部で復元試験が行われていないという断定ではない。
- observation_scope：常にlocal_collector_record_only。この範囲での記録だけを示す。
- reason_code：正常ならnull、既存ジョブ失敗ならcollection_failed/publication_failed/recovery_exhausted。

存在しないセットはset_not_found、不正な引数はinvalid_request、読取りエラー・壊れた記録・ID不一致・不正な完了証拠はstate_unreadable。これらはschema_version/phase/reason_codeと、検証済みの場合にset_idだけを返す。生の例外や不正データは表示しない。

`--format=json`ではコマンド出力をJSON文書1つにする。WordPress起動前のWP-CLI構文エラーや、他プラグインが出す警告はこの出力契約の範囲外。

| 終了コード | 意味 |
| --- | --- |
| 0 | 記録の読取り成功。セット自身のphaseがfailedでも0 |
| 2 | 引数不正 |
| 3 | サイト内に対象記録がない |
| 4 | 状態を安全に読み取れない |

監視は終了コードだけでバックアップ成功と判断せず、phase・completeness・各検証項目を読む。

## 読取りの境界

コマンド本体はWordPressJobStoreのSELECTを1回行い、既存Collectorの厳密な検証を再利用する。Collectorのtick/publish、CAS書込み、workspaceの生成・ロック、S3アクセスは呼ばない。環境情報、生のcheckpoint、lease、SQL、接続情報、ファイルパスは出さない。WordPress全体や他プラグインの起動処理まで副作用がないことを保証するものではない。

## 検証の再実行

2026-10-19にPHP 8.1/8.3でstatus契約105項目ずつ、および実WP-CLIの5ケースとDB前後一致を確認した。[検証集計](recovery-status-validation.json)。既存Recovery契約127項目は前提fixtureの検査であり、status固有の件数と合算しない。

```sh
php tests/manual/test-recovery-status.php
python3 tools/test-recovery-status-local.py
```

前者は合成レコードと書込み拒否ストア、wpdb/WP-CLIの代替で状態とエラーを検証。後者はLinux/Dockerでローカルのwordpress:php8.3-apache、wordpress:cli-php8.3、mariadb:11.8を使う。外部通信なしの専用ネットワーク・使い捨てDB/volumeを作り、終了時に自分が作った資源だけを片付ける。サブネットは既存Dockerネットワークとホスト経路に重ならない10.254.0.0/16内から選ぶ。

実WP-CLI試験はテスト専用bootstrapで本番と同じCommandRegistrarを読み込む。プラグイン全体を有効化した受入試験や実S3取得試験ではない。complete記録も専用合成データである。実WordPress DBの前後ダンプ一致でstatusによるデータ変更がないことを確認する。
