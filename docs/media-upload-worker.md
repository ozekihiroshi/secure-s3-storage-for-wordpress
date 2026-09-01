# Prepared media uploads and background dispatch (development only)

This implements the next transfer slice, **not a published v0.2 release**.
Existing database Backup Now, schedules and retention are unchanged. There is
no new media button and no media retention. Do not upload a development build
over the public 0.1.1 artifact.

## Execution model

The steps below describe the existing prepared-plan route. The new explicit
`media enqueue <work-parent>` route also prepares the plan through the same Cron
worker before upload; see [background preparation](media-preparation-worker.md).
Per-directory enumeration has a 10-second cooperative budget and fails with a
CLI-preparation instruction if exceeded. No S3 client is created during
preparation, and no partial readiness/completion marker is published.

1. `media prepare <work-parent>` synchronously creates an inventory and a
   multipart upload plan using the WordPress uploads root. It does not contact
   S3. Files are read in 1 MiB buffers; no complete copy of uploads is made.
2. `media start <plan-directory>` saves a job with a snapshot of the region,
   bucket, prefix, source root and plan summary, then schedules the worker.
3. WP-Cron processes up to 250 upload steps per invocation, stopping between
   steps after 20 seconds. Preparation retains its separate 1,000-step cap.
   A step has a 60-second lease. Network
   requests have a 5-second connect and 20-second total timeout, reduced to
   the remaining lease budget. These are cooperative bounds, not an OS watchdog.
4. A complete marker is published only after every file and the inventory
   have verified S3 checksums and byte counts, and the prepared plan hash chain
   and totals match. The job then becomes `succeeded`.

The synchronous `media prepare` command is **not resumable**. Interrupting it
leaves an unpublished private directory; prepare a new plan. It remains the
fallback for a directory too large for the background enumeration budget.
Background preparation is resumable through `media enqueue`, but its new
scoped-ZIP/real-AWS end-to-end verification is still required before publication.

## Commands (do not run on production before the deployment/test review)

Run as the same OS user as the WordPress worker so 0700/0600 permissions work.
Use a persistent private directory **outside the document root and uploads**,
visible at the same absolute path to CLI and the web container. In Docker this
needs a dedicated volume; container `/tmp` is lost on recreation. Mount setup
is a separate, user-approved environment change, not performed by these commands.

```sh
wp ozeki-database-backup-for-s3 media prepare /private/persistent/work
# Outputs a new /private/persistent/work/odbfs3-... directory.
wp ozeki-database-backup-for-s3 media start /private/persistent/work/odbfs3-...
wp ozeki-database-backup-for-s3 media status
wp ozeki-database-backup-for-s3 media tick
```

`tick` processes one worker batch, not an infinite daemon. After a returned
nonterminal batch, the worker chains a single event for five seconds later rather
than waiting for a fixed recurring minute. While a batch is running, a slower
60-second recovery event remains scheduled so an unexpected PHP process exit
does not strand the durable job. Actual dispatch still depends on WordPress
requests or a system Cron runner. A durable queued job survives a scheduling
failure; `status` shows it and the next init/CLI tick can recover it. Deactivation
clears worker events, not job state. Reactivation/init restores an unfinished
job. An old event is bound to its original job ID.

The prior terminal result is archived before replacing the single job slot;
an active job cannot be replaced. Status reports completed-file bytes, not
in-flight part bytes, so progress may pause during a large file.

## Remote layout and integrity

```
<configured-prefix>/backups/media/<random-job-id>/
  files/<sha256-of-UTF-8-relative-path>
  inventory.jsonl
  complete.json
```

Hashed path keys avoid S3 key-length problems and preserve arbitrary valid
relative names through the inventory. File contents are ordinary individual
S3 objects, **not a chunk-archive format**. Inventory format remains version 1.
The completion marker describes the path-to-key mapping and the full SHA-256
of the inventory. A restore tool must require and validate this marker and
inventory, download into a separate empty directory, then check every restored
file's full SHA-256. There is no production restore command yet.

Files up to 8 MiB (including empty files) use conditional PutObject. Larger
files use multipart upload, normally with 8 MiB parts. Part size grows with
file size to respect S3's 10,000-part / 5 GiB-part limits; service limits still
apply. There is no plugin-wide file-count or total-byte cap.

Preparation validates each file against the inventory's full SHA-256 and
records part SHA-256 values. S3 verifies those prepared values during upload,
so changed bytes cannot silently replace the inventoried bytes. Completion
checks paginated ListParts sizes/checksums and HeadObject's composite SHA-256
(which is explicitly **not** the full-file SHA-256). ETags are completion
identifiers, never treated as checksums. SSE-KMS permissions must allow checksum
verification as well as upload.

The filesystem is not an atomic snapshot. The manifest describes preparation;
later new files are outside that snapshot. Quiesce writes when pairing media
with a database backup. The web-server OS account and private plan directory
are trusted; local hashes are corruption detection, not protection against a
compromised account rewriting the plan and WordPress database together.

## Failure / incomplete-upload policy

Each saved checkpoint releases the lease. A killed worker repeats only its
uncommitted step. UploadPart repeats use the same prepared checksum. Lost
CompleteMultipartUpload acknowledgements recover through verified HeadObject.
Conditional writes prevent a stale worker from replacing an existing object.
Transient HTTP/network failures retry from the checkpoint up to three attempts;
access denied and integrity failures are terminal. Raw exceptions and credentials
are never stored in job options or printed by the CLI.

No failed run triggers retention or deletes earlier backups. Cleanup is never
automatic, including on page load, Cron recovery, deactivation or uninstall.
An operator must supply the exact current failed Job ID and confirm the explicit
`media cleanup <job-id> --yes` CLI operation. The operation first binds its exact
multipart key/upload ID and private workspace inode identity into the durable
failed-job record. It then aborts only that recorded incomplete multipart and
removes only allowlisted 0600 generated files from that exact 0700 workspace.
It never calls DeleteObject, follows links, recursively removes a path, or touches
completed objects, other backup runs, fixtures or restore evidence.

A pending cleanup blocks replacement of the job record. Interrupted cleanup is
resumed from that pending record; an already-missing multipart or workspace is
success, and repeating a completed cleanup performs no external I/O. Unexpected
entries, a held worker lock, changed inode/owner/mode, malformed state or an
unprovable destination fail closed without deleting the unexpected data. The
completed record is sanitized and no longer retains local paths, object keys or
upload IDs.

A crash after multipart initiation but before saving its upload ID can still
leave an unknown orphan. S3's `AbortIncompleteMultipartUpload` lifecycle rule is
a recommended safety net. A process loss before recording the ID requires
operational orphan discovery/cleanup. Neither the lifecycle rule
nor any bucket policy is changed automatically. Completed objects
from failed runs remain until an explicit future cleanup policy is approved.

## Verification and remaining gates

- `tests/manual/test-media-upload.php`: disk-backed mock S3, 17 MiB single file,
  small/empty/Unicode files, multipart pagination, process/ack loss, bounded
  retries, corruption/source-change failure, independent restored-file hashes,
  Cron registration, immutable destination, duplicate submissions, deactivation,
  schedule recovery and terminal result archive.
- `tests/manual/test-media-s3-client.php`: real SDK with a local HTTP handler;
  byte ranges, signatures, checksums, HTTP budgets and safe error translation.
- `tests/manual/test-media-cleanup.php`: exact Job ID, durable pending/resume,
  multipart 404 idempotency, completed-object retention, allowlisted local
  deletion, lock/link/inode failures and I/O-free completed retry.
- `media-upload-tests.yml`: PHP 8.1/8.3 CI; tests do not require AWS credentials.

Actual AWS scoped-ZIP uploads, real WordPress Cron/job storage and independent
download/restore of the 1 GiB fixture passed; see the
[2026-08-31 report](aws-media-cron-zip-test-2026-08-31.md) for exact scope.
The completion-paced single-event regression reduced the comparable callback
span from 1,858 to 536 seconds and independently restored all files; see the
[2026-09-01 report](aws-media-cron-single-event-test-2026-09-01.md).
This is not a matching DB/media site-restoration test.

Real process-kill and concurrent-worker acceptance also passed in the isolated
AWS environment. A stopped PHP holder kept the production job lease and real
workspace lock; a competing production controller was fenced both before and
immediately after `SIGKILL`, the checkpoint remained unchanged, and a production
replacement resumed after natural lease expiry. The exact failed-job cleanup was
then repeated idempotently. See the environment report at
https://github.com/ozekihiroshi/wp-rescue/blob/main/aws-media-ziptest/PROCESS-KILL-ACCEPTANCE-TEST.md.

Remaining 0.2.0 publication gates are a clean committed release ZIP, Plugin
Check, fresh ZIP installation and lifecycle checks on 8082, and a final isolated
AWS database/media/cleanup regression using that exact artifact. A separate
media schedule, media retention, a production restore command, broader KMS/IAM
configurations and an extreme 10,000-part object remain future features or
hardening work; they must not be claimed by this release. Do not describe mock-S3
checks as real AWS tests.

References:
- https://docs.aws.amazon.com/AmazonS3/latest/API/API_PutObject.html
- https://docs.aws.amazon.com/AmazonS3/latest/userguide/checking-object-integrity-upload.html
- https://developer.wordpress.org/reference/functions/wp_schedule_single_event/
