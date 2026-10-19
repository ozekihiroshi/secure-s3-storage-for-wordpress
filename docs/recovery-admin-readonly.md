# Recovery Sets管理画面（読取り専用）

共通DB部品の採用変更とは別の`codex/recovery-operator-plan`に追加。WordPress管理画面の **設定 → Recovery Sets** を開く。`manage_options`権限が必要で、購読者・未ログイン利用者は表示できない。

## 見られるもの

最初の画面はセット一覧。セットID、作成日時（UTC）、profile、処理状態、資産充足状態を表示する。IDのリンクから詳細へ進むか、上部のセットID欄へ32桁IDを入れて「状態を表示」を押す。

詳細は次の3つに分ける。

1. **セットの状態**：DB/mediaの対応するセットID、保存時の各検証と日時、manifestの記録・公開検証・SHA-256、取得整合性。`phase=failed`と`completeness=partial`等は別の軸として表示する。
2. **取得可否・復元試験の観測**：現在の評価、記録された結果、検証日時、鮮度、理由。鮮度の判定窓は24時間。古いavailable/passedを現在の成功として扱わず、`unknown / stale`と過去の結果を分けて示す。記録なしは`no_observation`、矛盾・破損・上限超過は集約不完全として表示する。保存完了前のセットに完了manifestの観測を表示しない。
3. **Cleanupの記録**：SQL/gzipごとの台帳状態。`not_started`は台帳なし、`pending`は準備済み、`needs_reconciliation`は削除予定の照合が必要、`complete`は台帳上の処理完了、`unknown`は安全に解釈できない状態。`deleting`を「現在実行中」と断定しない。

保存completeは復元試験passedではない。観測は記録済みの検査結果であり、ページ表示がS3の現在の可用性を確認することはない。cleanupも台帳の表示で、現在のファイルの有無は検査しない。cleanup台帳に日時はないため、実行日時は推測表示しない。

一覧は最大100件。読取り範囲の中で作成日時順に表示するが、上限超過・破損がある場合は不完全の警告を出す。全体の最新セットや全件成功を保証しない。必要なセットはIDで直接参照できる。詳細取得中にCollectorが変わった場合は、その表示を破棄して再読込みを案内する。

## 表示時の動作

DBのSELECTとメモリ上の契約検証だけを行う。workspaceを開かず、S3や通知サービスにも接続しない。作成・検証・cleanup・再開のボタン、POST処理、AJAX、定期ポーリングは追加していない。「状態を表示」はGETの読取り操作。

cleanup表示は元のCollector・manifestのハッシュに結び付いた専用台帳を検証し、状態コードだけを取り出す。ローカルパス・接続情報・SQL・生の例外をHTMLへ出さない。表示値はエスケープする。権限検査はデータ読取りより前に行う。

画面は本体の`Plugin::run()`からメニュー登録する。従来の設定画面・DBバックアップCron・共有部品の採用設定は変更しない。

## 専用環境の検証と範囲

[検証集計](recovery-admin-validation.json)：実WordPressの画面描画・権限等25項目が合格。前提となる既存契約試験や異なるPHP版の件数は合算しない。

- PHP 8.1/8.3で新規読取りモデル11項目を確認。壊れた台帳・誤った結び付け・不正メタデータが成功表示にならないこと、一覧の上限と非変更性を確認した。
- 既存cleanup実行26項目を両PHP版で回帰確認した。
- `python3 tools/test-recovery-status-local.py --admin-only`で、使い捨てWordPress/MariaDBの実際のメニュー登録・render callbackを検証。完全/失敗セット、観測、stale、cleanup照合待ち、破損、入力不正、管理者/購読者/未ログインの権限、DB optionsとworkspaceファイルの非変更性を確認する。

fixture準備の書込みは画面表示とは分離する。追加で、本体有効化・ブラウザログイン/画面クリック・権限・閲覧前後の非変更性を専用環境で確認済み。[ブラウザ受入と再現手順](recovery-admin-browser.md)を参照。実サイトへの配置は含まない。
