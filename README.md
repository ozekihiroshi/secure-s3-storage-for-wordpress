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
and a unified recovery manifest are implemented internally in the 0.3.0 source,
but are not connected to the admin, CLI or Cron entry points. Existing standalone
backups do not automatically become linked recovery sets. Coordinate writes when
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

## Development direction

The 0.3.0 source includes persistent recovery-set state, resumable DB/media
collection, conditional manifest publication and independent S3 verification.
Synthetic WordPress restoration from real-S3 artifacts passed with both dump
backends. See [the verification record](docs/aws-recovery-set-verification-2026-10-17.md).
This is an internal foundation, not a new operator-facing backup or restore command.
Private workspace retention and disk capacity remain the caller's responsibility;
do not enable cloned workers or remove their workspaces without an explicit policy.

Next steps are operator-facing orchestration and cleanup guidance. Clearer operational failure
states, scheduling health checks, and stable machine-readable outputs should
support integrations without making the core customer-specific.

The [operator roadmap and implementation inventory](docs/recovery-operator-roadmap.md)
separate the existing recovery-set foundation from the pending status CLI,
latest-set index, Cron orchestration, and workspace cleanup. This work is
independent of shared database-core adoption. Publication completion, current
availability, and restoration-test results will be reported separately.

A read-only [Recovery Set status command](docs/recovery-set-status.md) now exposes
existing site-local set records as a table or versioned JSON. It does not create
sets or contact S3. The separate [latest-set index and observation aggregation](docs/recovery-catalog-observations.md)
commands expose the latest attempt versus latest complete set, and locally
recorded availability/restoration observations bound to the exact manifest.
Cron orchestration, automatic observer integration, and cleanup remain planned work.

Those next steps are planned capabilities, not current guarantees or a finalized external
integration API. See the [integrity implementation](docs/database-integrity-verification.md)
for the completed work and remaining verification boundaries.

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
