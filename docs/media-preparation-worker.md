# Background preparation and Cron handoff (development)

Implemented behind explicit CLI submission. Not a published release, admin UI,
automatic media schedule or media retention policy. Existing database backups
and the synchronous `media prepare` / prepared-plan `media start` remain intact.

## Start and observe

Use the WordPress worker's OS user and an existing persistent storage parent
outside the actual document root and uploads. Work files must be on a POSIX
filesystem with reliable cross-process `flock`; Windows users should use WSL.
Do not enable this on a shared/distributed filesystem without validating locks.
No credentials, policy, mounts or directories outside the supplied parent are
configured automatically.

```sh
wp ozeki-database-backup-for-s3 media enqueue /private/persistent/work
wp ozeki-database-backup-for-s3 media status
# Optional: run one batch explicitly when WP-Cron traffic is absent.
wp ozeki-database-backup-for-s3 media tick
```

Submission snapshots the source root and AWS destination, creates private work
metadata and queues the existing one-minute Cron event. It does not scan media,
hash file content, instantiate an AWS client or send S3 requests. Duplicate jobs
are rejected; previous terminal results are archived as before. Schedule failure
leaves a durable queued job that init/status recovery can subsequently dispatch
(status itself is read-only; use init or tick).

`status` includes the preparation phase and safe error code. The file/byte
counters remain **uploaded** progress, so they are zero during preparation.
Deactivation stops events and preserves the job. Reactivation/init can recover
the event; source/directory changes meanwhile may correctly fail the run.

## Execution phases and limits

1. `enumerate`: one complete directory per step, streamed into private queue and
   path files. Child directory/file metadata is recorded. A folder gets at most
   10 seconds (less if the lease is near expiry). File contents are not read.
2. `sort_runs`: sort at most 128 path records / approximately 256 KiB per step.
   No global in-memory file list is created.
3. `sort_merge`: two-way merging with persistent input/output offsets, again at
   most 128 records / approximately 256 KiB per step. An individual record may
   cross the byte target, up to the existing 64 KiB record limit. Odd runs are
   carried to the next pass without copying their entire contents.
4. `files`, `file_hash`, `file_hashed`, `parts`: read sorted paths, verify saved
   source identity, calculate full-file SHA-256 in bounded steps, then reread in
   bounded steps to calculate multipart checksums and confirm the full hash.
   Each content-reading step reads at most 8 MiB in at most 1 MiB buffers and
   stops cooperatively after 2 seconds or near lease expiry. The second pass
   preserves the original inventory-versus-upload-plan integrity check.
5. Finish the inventory footer and its full SHA-256, prepare its multipart
   descriptor using the same bounded reader, and revalidate directories one at
   a time in `validate_directories`.
6. Write `ready.json` last, then CAS-switch to the existing upload checkpoint.
   Only after that switch can the controller instantiate the S3 client and run
   `MediaUploadStep`. Only S3's final verified completion marker marks success.

All limits are cooperative: a blocking filesystem call/fsync is not an OS-level
watchdog. The controller processes at most 1,000 total steps / about 20 seconds
per invocation, with at most 100 upload steps even across preparation/upload
handoff. The shared time budget is checked between steps; a blocking final step
can overrun it. Each preparation step still commits its own durable checkpoint.
An S3 object descriptor is bounded by S3's 10,000-part limit, not by total media
count or size. No arbitrary total-file or total-byte limit is introduced.

## Large single-directory policy (approved)

PHP directory handles cannot be saved and directly repositioned across requests.
Do not repeatedly skip an ever-growing directory prefix and call it bounded
resumption. If a complete directory exceeds the enumeration time budget, the job
fails with **`preparation_requires_cli`**. It does not loop forever, silently omit
files, publish a partial plan, instantiate the S3 client or upload partial data.
CLI status explains the alternative:

```sh
wp ozeki-database-backup-for-s3 media prepare /private/persistent/work
wp ozeki-database-backup-for-s3 media start /path/printed/by/prepare
```

These are explicit operator actions; the plugin does not spawn a long-lived CLI
process or automatically raise execution limits. Synchronous CLI preparation is
not resumable: on interruption use a new plan. Private abandoned files are kept.

## Crash safety and workspace trust

Hash contexts remain immutable 0600 checkpoint files selected by digest/reference
in the job store. Queue, sort, inventory and plan files additionally need appends.
`MediaPreparationStep::tick` therefore holds an exclusive nonblocking file lock
**before reading/claiming the job and through the final CAS**. Calling its
`execute` directly without that lock and the exact claimed store record fails.

A paused process holding the lock prevents another preparation worker from
mutating scratch data, even if its lease expires. The lock is released on process
exit. Once acquired, the next worker reads the authoritative job checkpoint,
truncates only uncommitted output tails and replays that step. Never break a lock
by unlinking its file. A hung process may require operator intervention; the lease
is not a process watchdog. A stale preparation handler rechecks the phase under
lock and does not damage a job already handed off to upload.

Only generated, run-owned files may be truncated. Files are opened exclusively
on creation and made 0600 before data writes. Existing files must be private,
single-link regular files with matching owner and open/name identity. Work roots
are canonical, 0700, outside uploads/web, and bound to the job's directory inode.
Native hash decoding is restricted to integrity-checked local checkpoints, never
an import endpoint. Raw contexts may contain source bytes and are not exposed in
job state, CLI status, logs or S3 manifests.

This trusts the WordPress OS account and job database, as the prepared-plan
uploader already does. It is not protection against a compromised account
rewriting files and database together. Metadata checks are not atomic snapshots;
same-size, timestamp-preserving edits can evade them. Full checksums are checked
again in the part pass and by S3 during upload. Quiesce writes when pairing DB and
media backups. A matching DB/media site-restoration test is still a release gate.

No automatic work cleanup is introduced. Old sort passes, immutable checkpoints,
abandoned tails and completed plans remain private and consume disk. Disk use
depends on metadata and sorting passes, not a second full media copy. Cleanup
needs a separate policy accounting for live/stale workers. Uninstall never deletes
these files or S3 objects. Missing files after power loss fail closed; file fsync
alone does not promise directory-entry persistence across a host power failure.

## Source initialization and small-file batches

Initialize a new test site's normal WordPress upload year/month directory before
enqueueing. The isolated AWS fixture helper calls `wp_upload_dir` with directory
creation only during setup, after validating the fresh fixture root. Backup
workers never create source directories or exempt WordPress-generated paths.
A new empty directory added after the snapshot still fails validation. A month
rollover or real concurrent uploads may therefore require a new job after writes
are quiesced; initialization is not permission to ignore later changes.

`test-media-preparation-batches.php` covers 600 small files and independent
restoration, the 1,000-step preparation cap, the shared 20-second budget, the
100-step upload cap including handoff, and failure on a late empty year/month
directory. The larger preparation batch reduces idle Cron intervals without
combining durable checkpoints or weakening source identity/checksum checks.

## Verification

`tests/manual/test-media-preparation.php` uses 27 synthetic media files including
a 17 MiB file, hidden/empty/nested/Unicode paths, small sort batches forcing many
merge passes, and an independent source/restore rescan. Tests cover:

- Actual child-process exit after directory/merge/part writes before job CAS;
  persisted lease expiry, tail rollback and replays without duplicated entries.
- Cross-process lock contention, fresh handlers/processes, and stale handler at
  preparation/upload handoff.
- No scan/S3 client at enqueue, duplicate rejection, immutable destination,
  schedule recovery, registered Cron handler preparation and upload, final event
  removal, empty uploads and safe directory-timeout failure.
- Compatibility with inventory v1 and the existing S3 model uploader, including
  multipart checksum validation and exact restored paths/sizes/full SHA-256.

Before the batching change, PHP 8.1.34 and 8.3.33: **416 assertions each**, with a 32 MiB PHP limit and 6 MiB
observed allocated peak. These ran as an unprivileged user in disposable,
network-disabled containers with read-only source mounts and private tmpfs data.
Those tests use WordPress API and S3 doubles, not real HTTP Cron or AWS.
The updated preparation test has 415 assertions (fewer loop iterations with
larger batches); the new batching test adds 3,052 assertions. Both pass in the
PHP 8.1/8.3 CI matrix. For the new scoped ZIP's real HTTP-Cron preparation,
S3 upload and independent restoration of 2,006 files including a 1 GiB file,
see [the AWS batching acceptance report](aws-preparation-batches-test-2026-08-31.md).
