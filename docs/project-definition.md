# Secure S3 Storage for WordPress

## Project Definition

**Document Version:** 0.1
**Status:** Draft
**Project Type:** WordPress Plugin
**Primary Goal:** Security-focused Amazon S3 integration for WordPress

---

## 1. Project Purpose

本プロジェクトの目的は、WordPressからAmazon S3へデータを安全に保存するためのWordPressプラグインを開発することである。

特に、AWSの長期的なAccess Key ID / Secret Access KeyをWordPressのデータベース、設定ファイル、管理画面などに保存する従来型の構成を可能な限り避け、IAM RoleおよびAWSの一時認証情報を利用することを基本方針とする。

単に「S3へファイルを保存できるプラグイン」を作るのではなく、AWS認証情報の安全な取り扱いを設計の中心に置く。

本プラグインは実運用可能な品質を目標とすると同時に、WordPress、PHP、AWS、IAM、S3、およびWebアプリケーションセキュリティに関する実装能力を示すポートフォリオとしても利用する。

---

## 2. Background

過去にWordPressからAmazon S3を利用する既存プラグインを使用していた際、AWS Access Keyの漏洩が疑われるセキュリティ事象が発生した。

AWSから警告が送られていたものの、海外滞在から帰国する時期と重なり、警告への対応が遅れた。その結果、AWS側による利用制限が発生し、システム運用に影響した。

この経験から、本プロジェクトでは以下の問題を重要な設計課題として扱う。

* 長期間有効なAWS認証情報をWordPress内に保存するリスク
* 認証情報が漏洩した場合の影響範囲
* 必要以上に広いIAM権限
* セキュリティ設定の状態が利用者から見えにくいこと
* AWS側で問題が発生していてもWordPress管理者が気付きにくいこと

このプロジェクトは、これらの問題に対する技術的な回答を実装することを目指す。

---

## 3. Core Design Principle

本プロジェクトにおける最重要原則は以下とする。

> **S3へ保存できることよりも、AWS認証情報を安全に扱うことを優先する。**

機能追加、UI設計、AWS構成、実装方法について判断が必要になった場合、この原則を優先する。

---

## 4. Design Principles

### 4.1 No Long-Lived AWS Credentials by Default

原則として、WordPress管理画面に以下の入力欄を設けない。

* AWS Access Key ID
* AWS Secret Access Key

WordPressデータベースにも長期AWS認証情報を保存しない。

初期実装ではEC2 IAM Role / Instance Profileを使用し、AWS SDK for PHPが取得する一時認証情報を利用する。

---

### 4.2 Least Privilege

IAM権限は必要最小限とする。

以下のような広範な権限を前提としない。

* AdministratorAccess
* AmazonS3FullAccess
* 全S3 Bucketへのアクセス

利用対象のBucket、Prefix、および必要なAPI操作だけを許可する構成を目指す。

---

### 4.3 Secure by Default

利用者が特別なセキュリティ知識を持っていなくても、安全性の高い構成になることを目指す。

安全でない設定を可能にする場合でも、安全な設定をデフォルトとする。

---

### 4.4 Visibility

セキュリティ状態およびAWS接続状態をWordPress管理画面から確認できるようにする。

将来的には以下のような情報を表示する。

* 使用中のAWS認証方式
* S3接続状態
* IAM権限の確認結果
* Bucket encryption状態
* Public Access Block状態
* バックアップ状態
* エラー状態

---

### 4.5 Credentials Must Never Be Logged

AWS認証情報を以下へ出力してはならない。

* WordPress debug log
* PHP error log
* Plugin log
* 管理画面
* HTTP response
* Exception messageの無加工表示

Access Key、Secret Access Key、Session Tokenなどは機密情報として扱う。

---

### 4.6 Separation of Responsibilities

プラグイン内部では、以下の責務を可能な限り分離する。

* AWS Authentication
* S3 Client
* Backup
* Scheduling
* Administration UI
* Logging
* Security Diagnostics

将来機能を追加してもAWS認証部分を不用意に変更しない構造を目指す。

---

## 5. Initial Target Environment

最初の開発対象環境は以下とする。

### WordPress

* WordPress current stable release
* PHP 8.x
* Single Site
* Linux server

### AWS

* Amazon EC2
* IAM Role / Instance Profile
* Amazon S3
* AWS SDK for PHP

最初の段階ではEC2上で動作するWordPressのみを正式な対象とする。

---

## 6. Initial Architecture

```text
WordPress
    |
    | AWS SDK for PHP
    v
AWS Credential Provider
    |
    v
EC2 IAM Role
    |
    v
Temporary AWS Credentials
    |
    v
Amazon S3
```

WordPress自身は長期Access Keyを保持しない。

AWS SDK for PHPの標準Credential Provider Chainを可能な限り利用し、AWS認証処理を独自実装しない。

---

## 7. Project Scope

最終的にはWordPressのバックアップをAmazon S3へ安全に保存できる機能を構築する。

想定する主要機能は以下。

* S3接続
* Database backup
* Uploads backup
* Manual backup
* Scheduled backup
* Backup history
* Retention management
* Error logging
* Security diagnostics

ただし、これらを同時に実装しない。

マイルストーンごとに一つずつ完成させる。

---

## 8. First Development Milestone

### Milestone 1 — Secure S3 Connection

最初の実装目標はバックアップ機能ではない。

以下の構成を成立させることを最初の技術的成果物とする。

```text
EC2 WordPress
    |
    v
IAM Role
    |
    v
Temporary Credentials
    |
    v
Amazon S3
```

WordPressプラグインから指定Bucketに対して、

* PutObject
* GetObject
* DeleteObject

を実行できることを確認する。

---

## 9. Milestone 1 Definition of Done

Milestone 1は以下をすべて満たした時点で完了とする。

* [ ] WordPressプラグインとしてインストールできる
* [ ] プラグインを正常に有効化できる
* [ ] AWS SDK for PHPを利用できる
* [ ] AWS Access Key入力欄が存在しない
* [ ] AWS Secret Access Key入力欄が存在しない
* [ ] EC2 IAM Roleから認証情報を取得できる
* [ ] 一時認証情報を使用してS3へ接続できる
* [ ] 指定BucketへテストオブジェクトをPutできる
* [ ] テストオブジェクトをGetできる
* [ ] テストオブジェクトをDeleteできる
* [ ] AWS credentialsをログへ出力しない
* [ ] WordPress管理画面から接続テストを実行できる
* [ ] 接続成功またはエラー内容を安全に確認できる
* [ ] IAM Policyが必要最小限に制限されている

Milestone 1完了前にバックアップ機能の実装へ進まない。

---

## 10. Planned Milestones

### Milestone 0 — Project Definition

設計思想、スコープ、Non-Goals、セキュリティ要件、マイルストーンを定義する。

成果物:

```text
docs/project-definition.md
```

---

### Milestone 1 — Secure S3 Connection

IAM Roleを使用したS3接続を実現する。

---

### Milestone 2 — Administration UI

WordPress管理画面から以下を設定・確認できるようにする。

* AWS Region
* S3 Bucket
* Prefix
* Authentication method
* Connection status
* Test Connection

---

### Milestone 3 — Database Backup

WordPressデータベースをバックアップし、圧縮した上でS3へ保存する。

---

### Milestone 4 — Uploads Backup

`wp-content/uploads` をS3へバックアップする。

初期段階では実装の単純さを優先する。

差分バックアップなどの最適化は後続課題とする。

---

### Milestone 5 — Scheduler and Retention

定期バックアップを実装する。

以下を対象とする。

* WP-Cron
* Backup schedule
* Retention period
* Old backup deletion

---

### Milestone 6 — Security Hardening

以下を重点的に確認する。

* IAM Least Privilege
* IMDSv2
* S3 Public Access Block
* S3 Encryption
* Secure temporary files
* WordPress capability checks
* Nonce verification
* Input validation
* Output escaping
* Error handling
* Credential leakage prevention

---

### Milestone 7 — Security Diagnostics

WordPress管理画面からAWSおよびプラグインのセキュリティ状態を確認できる機能を追加する。

例:

```text
Authentication
✓ EC2 IAM Role

Static credentials
✓ Not configured

S3 connection
✓ Connected

Public access
✓ Blocked

Encryption
✓ Enabled

IAM scope
✓ Restricted
```

---

### Milestone 8 — Non-AWS Hosting Support

EC2以外のホスティング環境からAWSへ安全にアクセスする方法を検討する。

第一候補:

* AWS IAM Roles Anywhere

この段階で初めて、AWS外サーバ向け認証方式を正式に設計する。

---

### Milestone 9 — Public Release Quality

GitHubおよびWordPress.orgで公開可能な品質へ仕上げる。

対象:

* README
* readme.txt
* Documentation
* Coding Standards
* Internationalization
* Tests
* Security review
* Installation guide
* AWS setup guide
* Screenshots
* Release packaging

---

## 11. Non-Goals

以下は初期開発の対象外とする。

* WordPress Media LibraryのS3 Offload
* CloudFront CDN連携
* S3互換ストレージ対応
* Dropbox対応
* Google Drive対応
* Azure Blob Storage対応
* WordPress Multisite対応
* AWS管理画面を完全に代替するIAM管理機能
* 自動IAM Role作成
* 完全なWordPressサイト移行ツール
* AWS外サーバ向け認証
* Enterprise向け集中管理
* Commercial licensing system

これらのアイデアが必要になった場合、現在のMilestoneには追加せずBacklogへ記録する。

---

## 12. Scope Control Rule

開発中に新しい機能案が出た場合、以下の質問を行う。

> **この機能は現在のMilestoneのDefinition of Doneを満たすために必要か？**

必要である場合のみ現在のMilestoneへ追加する。

必要でなければBacklogへ記録する。

新しいアイデアを理由に現在のMilestoneを拡張しない。

---

## 13. Proposed Repository Structure

```text
secure-s3-storage-for-wordpress/
├── secure-s3-storage.php
├── composer.json
├── README.md
├── readme.txt
│
├── src/
│   ├── Admin/
│   ├── Aws/
│   ├── Backup/
│   ├── Security/
│   └── Scheduler/
│
├── assets/
├── languages/
├── tests/
│
└── docs/
    ├── project-definition.md
    ├── architecture.md
    ├── security.md
    ├── aws-setup.md
    ├── threat-model.md
    └── backlog.md
```

この構造は現時点では案であり、Milestone 1開始時に必要最小限へ調整する。

---

## 14. Portfolio Objective

本プロジェクトは実用的なWordPressプラグインとして開発すると同時に、以下の技術能力を示せる成果物とする。

* WordPress Plugin Development
* PHP
* Composer
* AWS SDK for PHP
* Amazon S3
* AWS IAM
* Temporary Credentials
* Least Privilege Architecture
* Secure Credential Management
* WordPress Security
* Error Handling
* Plugin Architecture
* Technical Documentation

単なるサンプルコードではなく、設計理由、セキュリティ判断、AWS構成、実装、テストまで説明可能なプロジェクトを目指す。

---

## 15. Project Success Criteria

本プロジェクトが成功したと判断する基準は、単にS3へのバックアップが成功することではない。

以下を満たすことを成功条件とする。

1. 長期AWS認証情報をWordPressへ保存せずに運用できる。
2. IAM権限が必要最小限に制限されている。
3. AWS接続状態をWordPress管理者が確認できる。
4. セキュリティ上重要な設定状態を確認できる。
5. 障害時に原因を追跡できる。
6. WordPress標準のセキュリティ原則に従っている。
7. コードおよび設計を第三者へ説明できる。
8. GitHub上でポートフォリオとして提示できる。
9. 実際のWordPress環境で継続利用可能な品質に到達する。

---

## 16. Guiding Question

開発中に方向性について迷った場合、最初に次の問いへ戻る。

> **この変更は、WordPressからAmazon S3をより安全に利用するという目的に貢献しているか？**

答えが明確にYesでなければ、その変更は現在の開発対象から外すことを基本とする。

