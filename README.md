# Ozeki Database Backup for S3

Ozeki Database Backup for S3 helps administrators keep WordPress database and
uploaded-media backups in their own S3 bucket, without storing long-lived AWS
access keys in WordPress. It provides manual and scheduled database backups
and on-demand uploads-directory backups through WordPress administration and
WP-CLI.

The goal is a reusable foundation for verifiable recovery: knowing which
backup materials exist, whether their integrity has been checked, and what
else is needed to restore a site. Upload completion alone is not proof of
recoverability. The current plugin verifies individual backup components;
it is not a complete site recovery or one-click restore system.

## Design principles

- **Keep credentials out of WordPress.** Prefer server identities and temporary
  AWS credentials, such as an EC2 IAM role. The plugin uses the AWS SDK default
  credential provider chain and offers no access-key fields. It does not enforce
  temporary credentials across every provider in that chain.
- **Make success depend on verification.** Required integrity checks must pass
  before a backup is reported successful. Distinguish stored-object integrity
  from a tested restore.
- **Keep the core useful for a single site.** Routine backups should not require
  a centralized management service. Customer-specific dashboards, notifications,
  and orchestration belong in integration layers, not in the backup core.
- **Keep recovery boundaries visible.** Explain what is included, what must be
  saved separately, and which recovery checks remain the operator's responsibility.

## Get the plugin

Ozeki Database Backup for S3 is published on [WordPress.org](https://wordpress.org/plugins/ozeki-database-backup-for-s3/).
In WordPress, open **Plugins > Add New**, search for **Ozeki Database Backup for S3**,
then install and activate it. The directory release documented here is 0.2.1.

This is the development repository. Its historical name,
`secure-s3-storage-for-wordpress`, differs from the published plugin name.
Use the directory package for installation; it includes the runtime dependencies.

Version 0.2.1 is available from [GitHub Releases](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/releases/tag/v0.2.1).
It adds SQL/gzip and S3 SHA-256 integrity
verification, unique conditional-write backup keys, and verification evidence
in backup history. It has been applied to the operator's existing WordPress
site and is also published on WordPress.org.
See [integrity verification](docs/database-integrity-verification.md) and
[deployment record](docs/production-update-2026-10-17.md).

The current source version is 0.3.0. Its internal recovery-set implementation
and operator deployment are separate from the published 0.2.1 directory release;
updating this repository does not publish an update to WordPress.org.

## Who it is for

Keep backup storage under your control. The plugin suits administrators who
already manage an S3 bucket and can configure the WordPress server's AWS
identity. An EC2 instance role is one supported setup; AWS credentials do not
need to be entered through WordPress.

Inspect what was backed up. Database jobs create gzip-compressed SQL dumps,
while media jobs produce per-file SHA-256 inventories and a completion marker
after verification. Backup history and WP-CLI status commands support routine
operational checks.

## Backup capabilities

| Area | Available behaviour |
| --- | --- |
| Database | Manual, daily WP-Cron, and WP-CLI backups; native MySQL/MariaDB dump utility with a PHP fallback. |
| Database retention | Optional keep count; applied after successful scheduled or WP-CLI backups. Manual backups do not apply retention automatically. |
| Uploaded media | Explicitly started jobs for regular files in a single site's uploads directory; background inventory, multipart uploads, and verification. |
| Authentication | AWS SDK default credential provider chain, including IAM roles. |
| Removal | Uninstall removes local plugin settings and history while preserving completed backups in S3. |

## What a successful backup means

In 0.2.1, a successful database backup means that the generated SQL is non-empty,
gzip expansion matches its size and SHA-256, and S3 reports the uploaded object's
expected size and SHA-256. Unique object keys and conditional writes prevent
accidental replacement of an existing backup. New history entries retain the
compressed file's SHA-256 and the `remote_integrity` verification state; older
entries without evidence remain unverified.

If a required check fails, the backup is not reported successful and the
success-dependent retention step does not run. An object uploaded before a
verification failure may remain in S3 for inspection. This protects existing
backups from success-dependent deletion; it does not make an incomplete job valid.

Media jobs verify their uploaded files and inventory before writing a completion
marker. Neither a media completion marker nor database integrity evidence proves
that the complete WordPress site can be restored.

Independent download, checksum, decompression, and isolated database import tests
have passed for synthetic fixtures using both native and PHP dump backends and
real S3. These are development tests, not automatic restore tests of every user's
backup. See the [test scope and results](docs/aws-database-integrity-test-2026-10-17.md).

## Requirements

- WordPress 5.9 or later and PHP 8.1 or later.
- An S3 bucket, destination prefix, and AWS identity with the required access.
- Working WordPress Cron for scheduled database backups and background media jobs.
- For media jobs: a persistent private POSIX work directory outside web-accessible
  paths, owned by the WordPress PHP user with mode `0700`.

AWS storage and request charges may apply. Bucket access, encryption, retention,
and restore procedures remain part of the administrator's setup.
Scope permissions to the intended bucket, prefix, and operations. Database
integrity checks require object metadata and checksum read access as well as
upload access; connection testing and retention need their respective delete
permissions. Encryption permissions, including KMS where used, must also be
checked. The current real-S3 integrity tests used SSE-S3, not SSE-KMS, and do not
certify a least-privilege policy for every deployment.

## Create the first backup

1. Configure the server's AWS credential source and bucket permissions.
2. Open **Settings > Ozeki Database Backup for S3**.
3. Enter the region, bucket, and optional prefix, then save.
4. Run **Test Connection** to verify S3 read, write, and delete access.
5. Select **Backup Now**, inspect the recorded result, and practise restoring
   the database in a separate environment.
6. Enable daily backups and choose retention when the setup is ready.

The connection test checks temporary test objects, not the contents or
recoverability of a database backup.

Media backup needs one additional setting. Define `ODBFS3_MEDIA_WORK_DIR` in
`wp-config.php` with the private work directory's absolute path, then start a
media job explicitly. Keep the uploads tree unchanged until it completes.
See [readme.txt](readme.txt) for the complete setup and WP-CLI instructions.

## Recovery and operating boundaries

The plugin backs up the database and regular files in a single site's uploads
directory. It does not back up WordPress core, themes, plugins, `wp-config.php`,
or files elsewhere. Full recovery also needs the appropriate software versions,
configuration, secrets, and infrastructure setup, such as web-server settings,
scheduled tasks, and certificates. Preserve or document those separately.

Database and media jobs are independent: the plugin does not guarantee an atomic
snapshot or a shared point in time between them. A recovery set linking the two
and a unified recovery manifest are implemented in the 0.3.0 source. This
development branch adds read-only CLI/admin views, an opt-in developer Cron
entry and explicit local temporary-file cleanup. It does not provide a general
create/restore button or CLI. Existing standalone backups do not automatically
become linked recovery sets. Coordinate writes when
preparing a consistent recovery point, then test database and attachment
references together in an isolated environment.

There is no user-facing production restore command or button for either database
or media, and no automatic media scheduling or media retention. Download and
verification helpers and fixture test tools are not a general-purpose restore
workflow. Never point isolated restore tests at a production database. Backup
history is limited to the latest 20 entries and is not a permanent recovery
catalog; retain the evidence and recovery instructions you need separately.

WP-Cron runs when WordPress receives requests. Use a system scheduler to invoke
WordPress Cron if your installation requires predictable execution. Failed
media jobs require inspection and explicit WP-CLI cleanup by their exact job ID.

## Recovery Set operations (development branch)

These improvements are on `codex/recovery-operator-plan`, separate from shared
DB-core adoption. They are implemented and tested in dedicated environments;
this does not publish a new directory package or enable Recovery Set scheduling
on an existing site. See the [operator guide (Japanese)](docs/recovery-operations.md)
for the complete workflow and failure handling.

| Task | Entry point | Boundary |
| --- | --- | --- |
| Inspect a set | `recovery-set status <set-id> --format=json` | Reads saved component/manifest evidence; no S3 check |
| Find the latest | `recovery-set latest --source-id=<source-id> --format=json` | Latest attempt and latest complete are separate; incomplete index is explicit |
| Read later verification results | `recovery-set observations <set-id> --format=json` | Recorded availability/restore results and freshness; does not run verification |
| Create through Cron | Explicit `WordPressRecoverySchedule::register(...)` integration | Developer-supplied adapters/reporting; one Collector operation per invocation |
| Inspect cleanup candidates | `recovery-set cleanup-plan <set-id> --workspace-parent=<private-parent> --format=json` | Read-only; protection reasons and provisional candidates |
| Execute cleanup | `recovery-set cleanup <set-id> --workspace-parent=<private-parent> --confirm-set=<same-set-id> --format=json` | Only completed local SQL/gzip temporary files, with revalidation and a journal |
| View in WordPress | **Settings > Recovery Sets** | Administrator read-only list, details, observations and cleanup progress |

Prefix all CLI entries with `wp ozeki-database-backup-for-s3`; replace IDs and
paths with the actual values. Run from the intended WordPress installation.
Read commands returning exit code 0 mean the record was read, not that the
backup or restore succeeded. Inspect the JSON states and completeness flags.
An empty catalog does not create a set or import standalone backup history.

**Cron is not a ready-to-enable daily Recovery Set setting.** The existing daily
DB-backup setting remains separate. The new hook requires trusted integration,
fixed configuration, locking adapters and a result reporter. Repeated calls are
needed to finish one set; a single daily callback is insufficient. Daily slots
use UTC (09:00 JST). Failed sets and configuration changes stop for review;
there is no automatic reset. See [Cron setup and stopping](docs/recovery-cron-entry.md).

Before cleanup, confirm the recovery materials you still need, inspect the
plan, then explicitly repeat the exact set ID. Cleanup does not query S3 or
prove current recoverability. It preserves media subtrees, evidence and
journals, and never changes S3 retention. Automatic/recursive cleanup is not
provided; each candidate file has a 1 GiB validation limit. Follow the
[cleanup procedure and interruption handling](docs/recovery-cleanup-execution.md).

The [admin page](docs/recovery-admin-readonly.md) requires `manage_options`.
Its views do not start backups, validation or cleanup. Storage completion,
recorded availability and restoration tests remain separate. Stale or unreadable
evidence is not promoted to success. Set and observation indexes have a
100-record read limit; the UI/CLI report incomplete results rather than hiding it.

Verification includes PHP contract checks, dedicated WordPress CLI and
native/PHP acquisition/restoration using a disk S3 test double, plus
[full plugin activation and browser acceptance](docs/recovery-admin-browser.md).
The earlier [real-S3 foundation tests](docs/aws-recovery-set-verification-2026-10-17.md)
are separate evidence, not an end-to-end acceptance of all these new interfaces.
Source activation tests do not replace final distribution-ZIP upgrade tests.
The [reviewed candidate ZIP](docs/recovery-cumulative-review.md) has now passed
fresh installation, upgrades from local 0.2.1/0.3.0 packages, and pending-state
preservation tests. Its exact hash and scope are recorded; it is not a published release.

The same unchanged ZIP also passed a [real-S3 round trip and isolated restore](docs/recovery-zip-real-s3.md)
with synthetic data using both native and PHP database backends. This is a
test bridge using the packaged runtime, not a production worker acceptance test.
Version-pinned retention, independent signing and separated read/write roles
are outside this test's scope.

A subsequent [direct WordPress worker test](docs/recovery-direct-worker-s3.md)
also passed with that ZIP: registered WordPress hooks ran the production pipeline
directly against S3, then a fresh download was restored in an isolated environment.
No manifest rewriting or asset republication was used. Automatic Cron scheduling
and a general-purpose restore interface remain separate work.

For details, start with the [current inventory](docs/recovery-operator-roadmap.md)
and [publication checklist and remaining limitations](docs/recovery-operator-release-readiness.md).
Before distributing an update, review the cumulative changes, validate the final
package and upgrades, and align release documentation. General create/restore
interfaces, failure-reset/configuration migration, pagination and long-running
Cron monitoring remain follow-up work. No automatic restore guarantee is made.

Backup for S3 remains focused on backup/recovery materials and verification.
Ozeki WordPress Resilience addresses independent trust, retained recovery assets
and recovery after compromise or source loss. This branch does not add a mandatory
plugin dependency, independent signatures or a watchdog. Shared-core adoption
and its release validation remain a separate change.

## Data and external service

Backups are sent to Amazon S3. Database contents, uploaded files, and inventories
can contain personal data; the site operator controls the destination and its
access, encryption, and retention. The plugin's S3 connection test also creates
and deletes temporary objects. See the external-service and uninstall sections
in [readme.txt](readme.txt) for details.

## Support and development

- [WordPress.org support](https://wordpress.org/support/plugin/ozeki-database-backup-for-s3/)
- [GitHub issues](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/issues)
- [Operational documentation](docs/)
- [Release builder](build-release.sh) and [automated tests](tests/)

Licensed under [GPL v2 or later](LICENSE).
