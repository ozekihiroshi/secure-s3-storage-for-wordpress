# Ozeki Database Backup for S3

An independent WordPress plugin for backing up a site's database and uploaded
media to an administrator-owned Amazon S3 bucket. Ozeki WordPress Resilience is
not required for installation, configuration, backup or inspection.

The plugin provides manual and daily database backups, on-demand uploads backups,
integrity checks and backup history. Recovery Sets associate recovery materials
and their evidence. Storage verification is not a promise of complete site recovery.

## Versions and installation

Install from [WordPress.org](https://wordpress.org/plugins/ozeki-database-backup-for-s3/)
or a packaged [GitHub release](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/releases).
Packages include runtime dependencies; a source checkout requires Composer.
The repository's historical name is `secure-s3-storage-for-wordpress`.

**0.4.1 is available on [WordPress.org](https://wordpress.org/plugins/ozeki-database-backup-for-s3/)
and [GitHub Releases](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/releases/tag/v0.4.1)**.
It adds retention safeguards and clarified product documentation.
Version 0.4.0 includes PR #2's Recovery Set operator features, while 0.3.0
introduced the internal Recovery Set foundation.

See the [publication record](docs/release-0.4.1-publication.md) for current distribution
status and the [release validation](docs/release-0.4.1-validation.md) for package checks.

## Requirements and first backup

- WordPress 5.9+, PHP 8.1+, an S3 bucket and a server-side AWS credential source.
- S3 upload and metadata/checksum read permissions; connection testing and
  retention also need their respective delete permissions. Check KMS access if used.
- Working WP-Cron, or an operating-system scheduler invoking WordPress Cron.
- For media: a persistent private POSIX work directory, owned by the PHP user,
  mode `0700`, outside web-accessible paths, `wp-content` and uploads.

1. Configure the server's AWS identity and bucket permissions.
2. Open **Settings > Ozeki Database Backup for S3** and save region, bucket and prefix.
3. Run **Test Connection**, then **Backup Now**, and inspect the result.
4. Practise restoring into an isolated environment before relying on the backup.
5. Enable daily database backup and retention when ready.

The AWS SDK default credential provider chain supports IAM roles and other
server credential sources. There are no AWS access-key fields in WordPress;
the plugin does not enforce temporary credentials across every SDK provider.
Use a separate destination prefix per site. AWS request and storage charges apply.

For media, define `ODBFS3_MEDIA_WORK_DIR` in `wp-config.php`, then explicitly
start a job. Keep uploads unchanged until completion. Full setup and command
syntax are in [readme.txt](readme.txt).

## What a successful backup means

| Area | Implemented checks and boundary |
| --- | --- |
| Database | Native dump exit status or PHP dump error handling; non-empty SQL; gzip expansion equals the SQL size and SHA-256; S3 HEAD equals the uploaded gzip size and SHA-256. This does not validate every SQL construct by importing it. |
| Overwrite protection | Random DB keys and conditional writes; media and Recovery Set publication also use conditional writes. A separate authorized actor can still change or delete objects later. |
| Media | Regular files under the single-site uploads root, sorted inventory, file/part checksums and S3 object checks, then inventory and completion marker. Links and unsupported entries fail the job instead of silently being followed. |
| History | Latest 20 standalone DB results, including integrity evidence for newer entries. Old entries are not automatically verified. This is not a permanent catalog. |
| Recovery Set | Frozen set/source identity and destination bind DB, optional media and manifest evidence. Incomplete attempts remain distinct from complete sets. |

A required verification failure does not report success or start that attempt's
retention. Uploaded objects can remain after failure; inspect them before removal.
Media partial uploads do not constitute a completed backup. Explicit failed-job
cleanup is available by exact job ID; it does not delete completed S3 backups.

Database retention runs after successful scheduled/CLI backups, not after manual
admin backups. **The 0.4.1 safeguard** verifies all objects
in the keep window against their original digest metadata before allowing older
objects to be deleted. Missing evidence, denied checks, mismatches or incomplete
listing stop cleanup. Older releases lack that metadata: deletion may be deferred
until enough newer verifiable backups exist. No media or Recovery Set S3 retention
is provided. See [technical details](docs/product-quality-review-2026-10-27.md).

## Recovery Set operations

Version 0.4.0 adds **Settings > Recovery Sets**, status/latest/observations JSON,
execution records, schedule assessment and explicit local DB temporary-file cleanup.
These views read saved evidence; refreshing them does not contact S3 or run a restore.
An observation has its own check time, freshness and scope. A historical successful
restore is not a current availability check.

The schedule-assessment form changes expected times only. It does not schedule,
start, stop or reschedule backup acquisition. Actual Recovery Set acquisition
requires the explicit developer Cron integration, fixed configuration and locking
adapters. Each invocation advances one operation. Standalone backups are not
automatically converted into sets. General create/restore UI and CLI are absent.

Use the [operator guide](docs/recovery-operations.md) for commands and incident
handling. CLI exit code 0 means a read succeeded; inspect phase, completeness and
freshness. Set/observation indexes are bounded to 100 records and expose incomplete
results. Cleanup retains evidence, media and S3 data, and only removes the two
eligible completed DB temporary files after revalidation (1 GiB per-file limit).

## What is not guaranteed

- A complete site restore: core, themes, plugins, `wp-config.php`, secrets and
  server configuration must be supplied separately.
- An atomic DB/media snapshot. InnoDB transaction snapshots do not make all
  engines, concurrent schema changes or independently copied media consistent.
- Automatic restore testing of every backup or successful recovery under every
  failure condition. Fixture restoration evidence has a specific tested scope.
- Independent authenticity, source-compromise protection, Object Lock, immutable
  storage or separated recovery roles. Checksums and metadata are not signatures.
- Exact-time WP-Cron execution, external notification or a continuously running
  watchdog. No traffic can mean no Cron execution. A saved unfinished execution
  record does not prove that its worker is alive.
- Guaranteed cleanup after abrupt process termination. Private workspaces and
  incomplete multipart uploads can require explicit inspection and cleanup.

Deactivation and uninstall preserve S3 backups. Uninstall removes ordinary plugin
settings/history and media job metadata but retains Recovery Set records and
private workspaces. Stop externally registered Recovery Set schedules separately.

## Documentation and maintenance

- [Current review: branches, guarantees, fixes, constraints and release readiness](docs/product-quality-review-2026-10-27.md)
- [Verification records and historical documentation](docs/verification-index.md)
- [Database integrity implementation](docs/database-integrity-verification.md)
- [Recovery Set operations](docs/recovery-operations.md)
- [WordPress.org support](https://wordpress.org/support/plugin/ozeki-database-backup-for-s3/)
- [Issues](https://github.com/ozekihiroshi/secure-s3-storage-for-wordpress/issues)

Develop from `main`. Shared DB-core adoption in PR #1 was closed without merging;
the experiment is archived and is not a prerequisite for this product. See the
[branch cleanup decision](docs/branch-cleanup-2026-10-27.md) and
[local archive and restoration record](docs/local-archive-2026-10-27.md). Any future shared component must
be pinned and bundled, have an identified maintainer/update owner, and pass this
product's own release checks. Resilience changes must not silently change backups.

Backups and inventories can contain personal data. The operator controls the S3
destination, access, encryption and retention. The connection test creates, reads
and deletes temporary test objects. See [readme.txt](readme.txt) for external-service
details. Licensed under [GPL v2 or later](LICENSE).
