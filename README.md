# Ozeki Database Backup for S3

Back up WordPress to your own S3 bucket. Ozeki Database Backup for S3 gives
site administrators manual and scheduled database backups, plus on-demand
uploads-directory backups, from WordPress administration and WP-CLI.

Use the server's AWS identity. The AWS SDK default credential provider chain
supports IAM roles and other runtime credential sources. The plugin has no
fields for storing long-lived AWS access keys in the WordPress database.

## Get the plugin

Ozeki Database Backup for S3 is published on [WordPress.org](https://wordpress.org/plugins/ozeki-database-backup-for-s3/).
In WordPress, open **Plugins > Add New**, search for **Ozeki Database Backup for S3**,
then install and activate it. The directory release documented here is 0.2.0.

This is the development repository. Its historical name,
`secure-s3-storage-for-wordpress`, differs from the published plugin name.
Use the directory package for installation; it includes the runtime dependencies.

The development version is 0.2.1. It adds SQL/gzip and S3 SHA-256 integrity
verification, unique conditional-write backup keys, and verification evidence
in backup history. It has been applied to the operator's existing WordPress
site; WordPress.org publication is a separate step.
See [integrity verification](docs/database-integrity-verification.md) and
[deployment record](docs/production-update-2026-10-17.md).

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

## Requirements

- WordPress 5.9 or later and PHP 8.1 or later.
- An S3 bucket, destination prefix, and AWS identity with the required access.
- Working WordPress Cron for scheduled database backups and background media jobs.
- For media jobs: a persistent private POSIX work directory outside web-accessible
  paths, owned by the WordPress PHP user with mode `0700`.

AWS storage and request charges may apply. Bucket access, encryption, retention,
and restore procedures remain part of the administrator's setup.

## Create the first backup

1. Configure the server's AWS credential source and bucket permissions.
2. Open **Settings > Ozeki Database Backup for S3**.
3. Enter the region, bucket, and optional prefix, then save.
4. Run **Test Connection** to verify S3 read, write, and delete access.
5. Select **Backup Now**, inspect the recorded result, and practise restoring
   the database in a separate environment.
6. Enable daily backups and choose retention when the setup is ready.

Media backup needs one additional setting. Define `ODBFS3_MEDIA_WORK_DIR` in
`wp-config.php` with the private work directory's absolute path, then start a
media job explicitly. Keep the uploads tree unchanged until it completes.
See [readme.txt](readme.txt) for the complete setup and WP-CLI instructions.

## Recovery and operating boundaries

Database and media backups are separate. A complete recovery needs appropriate
copies of both, plus any site files outside the uploads directory. This release
does not provide a production media restore command, automatic media scheduling,
or media retention.

WP-Cron runs when WordPress receives requests. Use a system scheduler to invoke
WordPress Cron if your installation requires predictable execution. Failed
media jobs require inspection and explicit WP-CLI cleanup by their exact job ID.

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
