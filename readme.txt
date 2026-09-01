=== Ozeki Database Backup for S3 ===
Contributors: ozekihiroshi
Tags: backup, amazon s3, aws, database backup, media backup
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up WordPress databases and uploads to Amazon S3 without storing long-lived AWS access keys in WordPress.

== Description ==

Ozeki Database Backup for S3 is a security-focused WordPress database and uploaded-media backup plugin for Amazon S3.

The plugin is designed for environments where WordPress can obtain AWS credentials through the AWS SDK for PHP default credential provider chain, such as an EC2 instance using an IAM role.

The plugin does not provide fields for storing an AWS Access Key ID or Secret Access Key in the WordPress database.

Current features include:

* Manual database backups from WordPress administration.
* Automatic daily database backups using WP-Cron.
* WP-CLI database backup command.
* WP-CLI backup status command.
* Gzip-compressed SQL database backups.
* Amazon S3 upload using the AWS SDK for PHP.
* Native `mysqldump` or `mariadb-dump` when available.
* PHP-based database dump fallback when native database dump utilities or process execution are unavailable.
* Configurable retention using any positive integer keep count.
* Backup history stored locally in WordPress.
* S3 connection testing with temporary test objects.
* Automatic cleanup of plugin Cron events when the plugin is deactivated.
* On-demand uploads-directory backups started from WordPress administration.
* Background media inventory, checksum preparation, multipart upload, and verification using WordPress Cron.
* Per-file SHA-256 inventory and a final S3 completion marker.
* WP-CLI media status, worker, preparation, submission, and explicit failed-job cleanup commands.

= AWS authentication =

Ozeki Database Backup for S3 uses the AWS SDK for PHP default credential provider chain.

The plugin does not ask you to enter an AWS Access Key ID or Secret Access Key in the WordPress administration interface and does not intentionally store long-lived AWS credentials in the WordPress database.

For WordPress hosted on Amazon EC2, using an IAM role attached to the EC2 instance is recommended.

Other credential sources supported by the AWS SDK default credential provider chain may also work, depending on the hosting environment.

The AWS identity used by WordPress must have appropriate permissions for the configured S3 bucket and prefix.

= Database backup =

Database backups are created as SQL dumps, compressed with gzip, and uploaded to the configured S3 bucket.

Backup objects are stored below:

`<configured-prefix>/backups/database/YYYY/MM/DD/`

The plugin prefers a native MySQL-compatible dump utility such as `mysqldump` or `mariadb-dump` when available.

When a suitable native dump utility or PHP process execution is unavailable, the plugin can use its PHP database dump backend instead.

= Media backup =

Media backup covers regular files below the current single-site WordPress uploads directory. It is separate from database backup and must be started explicitly.

Media jobs prepare a sorted file inventory and SHA-256 checksums in bounded background steps, upload files as individual S3 objects, and publish a completion marker only after the inventory and uploaded objects have been verified. Large files use S3 multipart upload.

Media objects are stored below:

`<configured-prefix>/backups/media/<random-job-id>/`

Before media backup can be started from WordPress administration, the server administrator must define `ODBFS3_MEDIA_WORK_DIR` in `wp-config.php`. Its value must be an existing persistent POSIX directory owned by the WordPress PHP user, with mode 0700, outside the web root, `wp-content`, and uploads. Do not use a publicly served path or a temporary directory that may be cleared while a job is running.

For example:

`define('ODBFS3_MEDIA_WORK_DIR', '/private/persistent/wordpress-media-work');`

Keep the uploads tree unchanged while a media backup runs. New, removed, renamed, or changed files or directories can stop the job safely. Media backup has no automatic schedule or retention in this release. A failed job must be inspected and its exact Job ID explicitly cleaned with WP-CLI before another media job is submitted.

This release does not provide a production media restore command. The S3 completion marker and inventory are intended to support independent restoration and full SHA-256 verification. A complete WordPress recovery requires both an appropriate database backup and the corresponding media backup.

= Automatic database backups and WP-Cron =

Automatic backups use WordPress WP-Cron.

WP-Cron is triggered by WordPress requests and is not a real-time operating system scheduler. A backup scheduled for a particular time may therefore run later if the site receives no requests around that time.

For environments where predictable execution is important, use an operating system scheduler to run WordPress Cron periodically.

For a standard WP-CLI installation, an example is:

`*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now --quiet`

For a Docker Compose installation with a WP-CLI service, an example is:

`*/5 * * * * cd /path/to/docker-project && docker compose run --rm wp-cli cron event run --due-now --quiet`

These are examples only. Paths, users, container configuration, and execution permissions depend on your hosting environment.

= Retention =

Retention can be disabled or configured with any positive integer as the number of latest database backups to keep.

Retention operates only on database backup objects matching the plugin's expected backup naming structure below the configured S3 prefix.

Manual backups do not automatically apply retention.

Automatic backups and backups executed through the plugin's WP-CLI backup command apply the configured retention policy after a successful backup.

= Uninstall behavior =

Deactivating the plugin removes its scheduled database and media WordPress Cron events but keeps plugin settings, backup history, and media job state.

Uninstalling the plugin removes its local WordPress settings, backup history, current media job state, and archived media job metadata.

Uninstalling Ozeki Database Backup for S3 does NOT delete database or media backups stored in Amazon S3. It also does not automatically remove private media work directories or discover unknown incomplete multipart uploads.

This is intentional. Remote backups should not disappear merely because the WordPress plugin is removed.

== External Service ==

Ozeki Database Backup for S3 connects to Amazon Web Services (AWS), specifically Amazon Simple Storage Service (Amazon S3), in order to store and manage database and media backup objects.

The plugin uses the AWS SDK for PHP to communicate with AWS.

When the plugin performs a database backup, it sends the following to Amazon S3:

* The configured S3 bucket name.
* The configured S3 object prefix and generated backup object key.
* The gzip-compressed SQL database backup file.

When the plugin performs a media backup, it sends the following to Amazon S3:

* The configured S3 bucket name.
* The configured S3 object prefix and generated media backup keys.
* Files from the WordPress uploads directory.
* A generated inventory containing relative paths, sizes, SHA-256 checksums, and object mappings.
* A generated completion marker after all media objects and the inventory have been verified.

Media upload and verification may create, list, inspect, complete, or abort multipart uploads and may inspect uploaded objects. Explicit cleanup of an exact failed media job may abort only its recorded incomplete multipart upload. The plugin does not automatically delete completed media backup objects.

Files in the WordPress uploads directory may contain personal, private, copyrighted, or otherwise sensitive content. Administrators are responsible for selecting an appropriate S3 destination, access policy, encryption configuration, retention policy, and legal basis for storing that content.

A database backup may contain any information stored in the WordPress database. Depending on the site, this may include personal or sensitive data such as user accounts, email addresses, post content, comments, plugin settings, and other database records.

When the S3 connection test is run, the plugin:

* Checks access to the configured bucket.
* Creates a temporary test object containing a generated test string and timestamp.
* Reads the temporary object back to verify access.
* Deletes the temporary test object.

Retention operations may list and delete database backup objects within the configured backup prefix according to the selected retention policy.

Use of Amazon S3 is subject to Amazon Web Services terms and privacy policies:

AWS Customer Agreement:
https://aws.amazon.com/agreement/

AWS Privacy Notice:
https://aws.amazon.com/privacy/

AWS Service Terms:
https://aws.amazon.com/service-terms/

No telemetry, analytics, advertising, or unrelated tracking data is intentionally sent by Ozeki Database Backup for S3 to the plugin author.

== Installation ==

1. Install and activate Ozeki Database Backup for S3.
2. Make sure the WordPress server can obtain AWS credentials through the AWS SDK default credential provider chain.
3. Grant the AWS identity access to the intended S3 bucket and prefix.
4. Open Settings > Ozeki Database Backup for S3.
5. Enter the AWS Region, S3 Bucket, and optional S3 Prefix.
6. Save the settings.
7. Use "Test Connection" to verify S3 read/write/delete access.
8. Use "Backup Now" to create the first database backup.
9. Optionally enable the daily automatic database backup schedule and configure retention.
10. To use media backup, create a persistent private POSIX directory outside all web-accessible directories, owned by the WordPress PHP user with mode 0700.
11. Define `ODBFS3_MEDIA_WORK_DIR` in `wp-config.php` with that directory's absolute path.
12. Return to the settings page and use "Start Media Backup". Keep uploads unchanged until the job succeeds or fails.

For Amazon EC2, an IAM role attached to the instance is recommended instead of storing long-lived AWS access keys on the WordPress server.

== Screenshots ==

1. Configure the AWS Region, S3 bucket and prefix, automatic backup schedule, and retention count.
2. Verify S3 object access and create an on-demand compressed database backup from the WordPress administration screen.

== Frequently Asked Questions ==

= Does this plugin store my AWS Access Key ID or Secret Access Key? =

The plugin does not provide settings fields for long-lived AWS Access Key IDs or Secret Access Keys and does not intentionally store them in the WordPress database.

AWS authentication is handled through the AWS SDK for PHP default credential provider chain.

= Is an EC2 IAM role required? =

No.

An EC2 IAM role is the recommended authentication method when WordPress is hosted on Amazon EC2, but other credential sources supported by the AWS SDK default credential provider chain may also work.

= Are database backups encrypted? =

The plugin uploads backups to Amazon S3 over the AWS SDK connection.

Encryption at rest depends on the configuration of the destination S3 bucket and applicable AWS settings.

Administrators should configure the S3 bucket according to their own security and compliance requirements.

= Does uninstalling the plugin delete my S3 backups? =

No.

S3 database backup objects are intentionally preserved when the plugin is uninstalled.

= Does the automatic backup run at an exact time? =

Not necessarily.

The automatic backup uses WP-Cron, which depends on WordPress requests to trigger due events.

For more predictable execution, configure a real operating system scheduler to periodically execute due WordPress Cron events.

= What happens if mysqldump is not available? =

The plugin can fall back to a PHP-based database dump implementation when a supported native dump utility or process execution is unavailable.

The PHP fallback opens a separate database connection using the database constants already defined by WordPress. This keeps its consistent-snapshot transaction isolated from WordPress's shared database connection; it does not accept database connection values from an HTTP request or plugin setting.

= What does the S3 connection test do? =

It checks access to the configured S3 bucket, writes a temporary test object, reads it back, verifies the contents, and then deletes it.

= What does media backup include? =

It includes regular files under the current single-site WordPress uploads directory. It does not include the WordPress database, themes, plugins, `wp-config.php`, or other site files. Run a separate database backup for the corresponding database state.

= Can this plugin restore a media backup? =

Not in this release. A completed media backup contains a verified inventory, individual file objects, and a completion marker intended for an independently verified restore process. Test restoration procedures before relying on any backup system.

= Can media backup run automatically or apply retention? =

Not in this release. Media backup is started explicitly and is separate from the daily database schedule and database retention setting.

== WP-CLI ==

Create a database backup:

`wp ozeki-database-backup-for-s3 backup`

Display backup configuration and status:

`wp ozeki-database-backup-for-s3 status`

Queue media preparation and upload using an existing private work directory:

`wp ozeki-database-backup-for-s3 media enqueue /private/persistent/wordpress-media-work`

Display safe media job status:

`wp ozeki-database-backup-for-s3 media status`

Run one bounded media worker callback, for example from a server scheduler:

`wp ozeki-database-backup-for-s3 media tick`

For a directory that exceeds the background enumeration budget, prepare and submit from WP-CLI:

`wp ozeki-database-backup-for-s3 media prepare /private/persistent/wordpress-media-work`

`wp ozeki-database-backup-for-s3 media start /private/persistent/wordpress-media-work/odbfs3-preparation-...`

After inspecting an exact failed media job, explicitly clean only its recorded incomplete multipart upload and private workspace:

`wp ozeki-database-backup-for-s3 media cleanup <job-id> --yes`

== Changelog ==

= 0.2.0 =

* Added on-demand backups of the WordPress uploads directory from the settings page.
* Added bounded background file enumeration, sorting, SHA-256 preparation, upload, and verification through WordPress Cron.
* Added individual S3 media objects, multipart support for large files, a verified inventory, and a final completion marker.
* Added authenticated media start and status controls with safe progress reporting.
* Added WP-CLI media enqueue, prepare, start, tick, status, and explicit failed-job cleanup commands.
* Added persistent leases, workspace locking, crash recovery, and stale-worker fencing for media jobs.
* Added exact, idempotent cleanup for a failed job's recorded incomplete multipart upload and private workspace without deleting completed backups.
* Added strict private-work storage validation and source-change detection.

= 0.1.1 =

* Renamed the plugin to Ozeki Database Backup for S3.
* Removed the fixed 7, 14, or 30 backup retention choices; any positive integer keep count is now supported.
* Clarified the separate database connection used by the PHP dump fallback.
* Fixed Daily Cron registration when settings are saved for the first time.

= 0.1.0 =

* Initial public release.
* Added manual database backup to Amazon S3.
* Added automatic daily database backups using WP-Cron.
* Added configurable S3 backup retention.
* Added WP-CLI backup and status commands.
* Added native MySQL/MariaDB dump support with PHP fallback.
* Added backup history.
* Added plugin activation, deactivation, and uninstall lifecycle handling.
* Added AWS default credential provider authentication.
