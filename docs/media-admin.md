# Media backup administration (development)

The existing settings page now has a **Media Backup** section. This is not a
new published release; the public 0.1.1 artifact must not be overwritten.
Existing database controls, schedules and retention settings are unchanged.

## Server setup before enabling Start

The administrator must provision a persistent private POSIX directory and set
this constant in `wp-config.php` before WordPress loads the plugin:

```php
define('ODBFS3_MEDIA_WORK_DIR', '/private/persistent/wordpress-media-work');
```

That is an illustrative path, not a directory the plugin creates. It must exist,
be owned by the PHP/WordPress OS user, have mode **0700**, and be readable,
writable and searchable by that user. Do not use a periodically cleared `/tmp`
directory for a real running backup. Persistence/lock semantics are the server
administrator's responsibility; a mode check cannot establish those properties.

The start preflight reuses `MediaHashCheckpointStore` validation: canonical paths,
no symlink components, correct owner/mode, outside uploads, ABSPATH,
WP_CONTENT_DIR and the server's DOCUMENT_ROOT when supplied. Other publicly
served aliases/mounts cannot be discovered reliably: keep work outside **all**
public directories. Windows ACL-only storage and multisite are not supported.
No browser-supplied filesystem path, credentials or S3 destination is accepted.
AWS region/bucket/prefix come from the existing saved plugin settings.

For a newly initialized uploads tree, initialize WordPress's normal year/month
directory **before** starting. The UI/start request does not create source
directories or relax changes detected by the worker. Month rollover or new
uploads can still fail a run. Quiesce writes for consistent DB/media recovery.

## Start, progress and results

- Only `manage_options` users see the section or can invoke either endpoint.
- Start uses authenticated `admin-post.php`, POST and an action-specific nonce.
  It only saves preparation metadata and schedules Cron; it does not enumerate,
  hash source contents, create an SDK client or upload data in the page request.
- A form includes the previously observed job ID (or `none`). The controller
  rechecks that generation before initialization and again before its final CAS.
  Double clicks and stale forms cannot enqueue a replacement, even when the
  intervening job already finished. Concurrent losers may leave private work
  artifacts, but cannot replace the winning job or publish success.
- The UI shows queued/running/succeeded/failed, the current phase, prepared file
  count and uploaded file/byte count. It does not guess a percentage. It shows
  the current/latest result; archived history remains in existing storage.
- JavaScript polls the authenticated, nonce-protected status endpoint every
  15 seconds while visible. Polling never runs a worker or writes a checkpoint.
  No-JavaScript users can use the ordinary refresh link. Closing the page does
  not cancel the job; **site traffic or a server scheduler is still required**
  to dispatch WP-Cron. This does not install an OS scheduler.
- A lost connection displays a status-refresh warning, not a failed-backup
  claim. Auth/nonce expiry stops polling. A stale/disabled start form is never
  automatically re-enabled; reload to review current state before another start.
- Only an explicit allowlist of status strings, job ID and safe counters is
  serialized. Checkpoints, source/private paths, credentials, upload IDs, signed
  URLs, raw exceptions and arbitrary error text never enter status JSON/HTML.
  Dynamic text is escaped in PHP and assigned with `textContent` in JavaScript.
- A saved job without a Cron event is shown with a scheduling warning. Do not
  resubmit just because a post-save scheduling failure produced an error notice.

## Failed jobs and deferred operations

The initial UI **does not restart a failed job**. This protects an incomplete
multipart upload from being hidden by repeated button clicks before explicit
server-side inspection. The authenticated handler also rejects a forged start
with the failed job's current generation, not just the disabled button.

An operator can inspect `wp ozeki-database-backup-for-s3 media status`, address
the cause, and use the existing explicit CLI `media cleanup` if an unfinished
multipart upload needs aborting. A new CLI `media enqueue /private/work` is an
explicit operator action that archives the terminal result; do not manually
erase job records to unlock the UI. Cleanup/retry buttons, pause/cancel, media
schedule/retention, archived-history browsing and automatic private-file cleanup
remain separate implementation steps. No filesystem/S3 cleanup runs on rendering,
polling or uninstall.

## Verification and limits

- PHP 8.1.34 and 8.3.33, non-root network-disabled containers, read-only source,
  private tmpfs, 32 MiB PHP limit: admin 47 checks, preparation 415, preparation
  batches 3,052, upload 45, job runner 49, all passed. Admin allocated peak:
  4,198,400 bytes. Tests include capability/nonce/method rejection, private-path
  validation, stale-generation race, no scan/S3 on start, no mutation on polling,
  and the registered Cron callback completing without any browser request.
- Node 20: polling method/nonce, stale-form behavior, text-only rendering,
  hidden-page throttling, network error and expired-auth handling passed.
- Source production PHP passed the local PluginCheck PHPCS standard.
- Development ZIP built successfully with scoping/autoload/collision checks:
  `build/admin-ui-development/ozeki-database-backup-for-s3-0.1.1.zip`, 8,197,534
  bytes, SHA-256
  `8f4f8546d6bf79f6ff04d416fb2653a2949d12cff4a32f1d9b83e5f134a9c3e8`.
  All 3,861 file entries passed ZIP integrity checking and were extracted on
  Linux; JavaScript bytes matched the source. Scoped admin classes autoloaded
  with their public action/configuration constants intact. The fully extracted
  distribution passed the PluginCheck PHPCS standard (vendor excluded); this
  is not a new full Plugin Check CLI or GitHub Actions result.
  Slow NTFS extraction was stopped and replaced with complete Linux extraction
  at `/tmp/odbfs3-admin-zip.NSl6ohlB`; the incomplete Windows extraction is not
  a deployable staging directory. No prior artifact was overwritten.
- WordPress 7.0.2 / PHP 8.3.33 in isolated local **8082**: actual WordPress
  rendering/capabilities/enqueue functions verified anonymous hiding, disabled
  start without configuration, enabled start with a valid private directory,
  old database controls preserved, and no job/work changes. Code was loaded
  from a generated temporary CLI test directory, not installed over the plugin.
  The same check also passed with the ZIP's scoped first-party code using the
  site's existing bundled dependencies. This was not an authenticated HTTP test.
- The browser-control tool failed to start due to a local ACL/sandbox error.
  Interactive/visual testing, authenticated real HTTP start/status, browser-close
  HTTP-Cron acceptance and a newly installed distribution ZIP still need testing.
  No new AWS upload/restore was performed for the admin UI; the prior report
  proves the underlying worker, not this new browser workflow.
- No version bump, commit/push, GitHub Release or WordPress.org publication is
  part of this implementation step. Temporary local test evidence is retained.
