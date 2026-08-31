# Bounded preparation batches / AWS ZIP acceptance — 2026-08-31

Result: **small and large preparation → HTTP Cron → S3 → independent restore passed**.

## Change and provenance

- Plugin source: `5d8dd7a24ddcd97993b4e09f556fb306ce9ff632`.
- Environment/helper: `f454b4d02f99ca7a2aa6d26e82cf93a20dd3c251` in `wp-rescue`.
- Plugin CI: Plugin Check `33363738151`, Media Inventory `33363738141`,
  Media Upload `33363738113`, Backup Job `33363738165`: all successful.
  New batching regression passed on both PHP 8.1 and 8.3 in CI.
- Environment CI: Compose `33363744738`, AWS ZIP test `33363744698`: successful.
- ZIP built from a clean `git archive` of the plugin commit, with locked
  dependencies, PHP-Scoper, syntax/autoload/collision checks.
- Local artifact: `build/aws-batches-5d8dd7a/ozeki-database-backup-for-s3-0.1.1.zip`.
- SHA-256: `ba3a40e7c70ff139f6dac88aa02cc30fef0b29ab65dcb676a7878b54c6000d18`.
- Size: **8,190,685 bytes**. Transfer finished and SHA-256 matched before update.
  All **3,858 installed files** matched the ZIP, without extra runtime files.
- This is development code retaining the 0.1.1 header, **not a public release**.
  Do not overwrite the published 0.1.1 ZIP, GitHub release or WordPress.org tag.

The controller now permits 1,000 total preparation/upload steps per callback,
while retaining at most 100 upload steps including handoff and the shared,
cooperative 20-second budget. Every preparation step still saves a durable
checkpoint. A blocking final step can overrun the time target.

The fixture helper initializes the normal WordPress `2026/08` directory before
snapshot/enqueue. Runtime backup workers remain read-only on the source; no
path is exempted from change detection. Month rollover or concurrent new uploads
can still fail the job. This does not introduce snapshot isolation.

Local PHP 8.3.6 regression results (32 MiB limit): new batch test 3,052 checks,
preparation 415, upload 45, inventory 93, native hash continuation 12, file hash
step 47, WordPress source 8. New test allocated peak: 4,198,400 bytes. It checks
600 independently restored small files, both step caps, the shared time budget,
and fail-closed behavior for an empty year/month directory added late.

## Isolation and method

Only the existing AWS `odbfs3-media-ziptest` project was targeted: loopback port
8084, WordPress 7.0 / PHP 8.3.31, dedicated HTML/DB/work volumes, no source bind.
Web limit 256 MiB / 0.5 CPU; DB 192 MiB / 0.5 CPU. Private work is outside the
web root. Credentials remain the existing EC2 role. No IAM, bucket policy,
lifecycle, production configuration or public release changes.

Destination: `ceri-secure-s3-storage-test`, `ap-northeast-1`,
`wordpress-test/media-cron-ziptest/`, with a distinct random subprefix per job.
Database automatic backup and retention are disabled. No S3 cleanup is run.

The helper independently validates original fixture hashes, copies a fresh
synthetic upload tree, initializes year/month directories, and enqueues the
shipped preparation API. Enqueue does not enumerate or hash the media or create
a ready marker. All worker batches run under actual HTTP WP-Cron and the real
WordPress DB store, at the ordinary one-minute interval. No direct `tick`/`run`,
edited event timestamps or accelerated schedule is used. A passive observer
records checkpoint continuity, PHP PID, Cron context and allocated memory.

## Small fixture — passed

- Job: `532a4ec3c4b8db2599cad695556a17e0`.
- 31 files / 9,349,072 bytes. Enqueue 0.058 seconds, excluding setup/checking.
- One HTTP Cron callback, `06:26:55Z`–`06:27:01Z`: **6 seconds**.
- Preparation, upload, verified completion and independent S3 restore passed.
  One PID; this small run does not prove cross-process resumption.
- Worker allocated peak: 20,975,616 bytes; restore helper: 59,244,544 bytes.
- Restore: 1.183 seconds, `/var/lib/odbfs3-work/restore-smoke-7de8301bdd35f97e`.
- Final status succeeded; scheduled media events zero after callback cleanup.
  A sample taken immediately after final state CAS still saw the event, which
  the completing callback then removed; no manual intervention was needed.

## Large fixture — preparation observations

- Job: `67cae9db3bf81d1b4fd9afad260f45e6`.
- 2,006 files / 1,107,367,888 bytes, including one 1 GiB file.
- Enqueue 0.024 seconds, excluding fixture copy and independent validation.
- First HTTP callback started `06:28:26Z`. The callback `06:38:26Z`–`06:38:46Z`
  passed final directory validation and switched to upload. Preparation thus
  finished within **10 minutes 20 seconds**, without weakening validation.
  The ready marker mtime is `06:38:34.367191180Z`: approximately **10 minutes
  8 seconds** from the first callback start (start timestamps have 1 s resolution).
- Small-file preparation advanced **250 files per callback**, observed spans
  10–15 seconds, versus about 25 files per callback in the previous run.
- Whole-file and part-hash cursors were preserved between HTTP requests.
- During preparation: `autoload=no`, private root 0700, 155 entries inspected
  with no permission violations; ready marker absent, zero S3 objects.
- After handoff: 8,324 private entries inspected, all directories 0700 and files
  0600, no links. Ready marker present, 5 uploaded objects, completion marker
  absent while the large file was still being uploaded.
- Private plan: `/var/lib/odbfs3-work/plans/odbfs3-preparation-37958567ab84ed112b3cef09e7a83690`.

## Large fixture — final upload and independent restoration passed

- 33 HTTP callbacks, 5 distinct PHP PIDs, `06:28:26Z`–`07:00:28Z`:
  **1,922 seconds (32 minutes 2 seconds)** from first callback to upload success.
- Observer assertions passed: actual Apache HTTP Cron context, checkpoint
  continuity, no overlapping observed callbacks, zero attempts at callback
  boundaries, preparation and multipart upload resumed in different PHP PIDs.
- Sum of observed active callback spans: 320 seconds; maximum span 21 seconds.
  These use one-second timestamps, not high-resolution profiling. No callback
  uploaded more than 100 files. Ordinary small-file upload batches took 6–8 s.
- Worker peak PHP allocated memory: **10,489,856 bytes**, not process/container RSS.
- Final status `succeeded`; scheduled media events zero. S3 contains exactly
  2,008 objects: 2,006 files, inventory and verified completion marker.
- Final private audit: 8,324 entries, no links or permission violations;
  root 0700, files 0600, job option `autoload=no`.
- Downloaded the completion marker and inventory, checked inventory digest,
  compared every path/size/SHA-256 against the independently recorded original
  fixture, downloaded every object, and rescanned the restored tree: **passed**.
- Restored files/bytes: **2,006 / 1,107,367,888**. Restore/check took 125.397 s;
  helper peak PHP allocated memory 59,244,544 bytes.
- Fresh restore directory: `/var/lib/odbfs3-work/restore-large-555954a1da1e0a18`.
- No OOM kills/restarts. Production start times stayed unchanged:
  `wp-rescue` `2026-08-23T10:17:52.190929068Z`,
  `wp-rescue-db` `2026-08-23T10:17:51.866225961Z`.
  Isolated web/DB start times also stayed
  `2026-08-31T01:49:52.51124454Z` / `2026-08-31T01:49:36.242264813Z`.
  Approximately 25 GiB of host disk space remained during restoration.

## Scope

The prior failed job and all old artifacts, fixtures, plans, S3 objects and
restore evidence are preserved. New test data also remains billable until a
separately scoped cleanup is agreed. No source or failed state was deleted.

This is not a DB/attachment or full-site restoration test, forced-crash test on
AWS, complete compatibility matrix, admin UI test, media retention test or
authorization for publication. A huge single directory can still require the
documented explicit CLI preparation path. Source writes should be quiesced.
