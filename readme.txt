=== Ozeki Database Backup for S3 ===
Contributors: ozekihiroshi
Tags: backup, amazon s3, aws, database backup, security
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create WordPress database backups and store them in Amazon S3 without storing long-lived AWS access keys in WordPress.

== Description ==

Ozeki Database Backup for S3 is a security-focused WordPress database backup plugin for Amazon S3.

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

= Automatic backups and WP-Cron =

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

Deactivating the plugin removes its scheduled WordPress Cron event but keeps plugin settings and backup history.

Uninstalling the plugin removes its local WordPress settings and backup history.

Uninstalling Ozeki Database Backup for S3 does NOT delete database backups stored in Amazon S3.

This is intentional. Remote backups should not disappear merely because the WordPress plugin is removed.

== External Service ==

Ozeki Database Backup for S3 connects to Amazon Web Services (AWS), specifically Amazon Simple Storage Service (Amazon S3), in order to store and manage database backup objects.

The plugin uses the AWS SDK for PHP to communicate with AWS.

When the plugin performs a database backup, it sends the following to Amazon S3:

* The configured S3 bucket name.
* The configured S3 object prefix and generated backup object key.
* The gzip-compressed SQL database backup file.

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
9. Optionally enable the daily automatic backup schedule and configure retention.

For Amazon EC2, an IAM role attached to the instance is recommended instead of storing long-lived AWS access keys on the WordPress server.

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

== WP-CLI ==

Create a database backup:

`wp ozeki-database-backup-for-s3 backup`

Display backup configuration and status:

`wp ozeki-database-backup-for-s3 status`

== Changelog ==

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