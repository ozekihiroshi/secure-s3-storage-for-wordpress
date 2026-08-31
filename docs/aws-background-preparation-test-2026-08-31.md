# Distribution ZIP / background preparation / AWS — 2026-08-31

Result: **small end-to-end test passed; large end-to-end acceptance did not pass**.
The large job failed closed at final directory validation. No large-run S3
upload or restoration was performed. Do not report this as a full acceptance pass.

## Exact source and artifact

- Plugin commit: `9deffc6079615db7e55c0c949628816e661d5b61`.
- GitHub Actions: Plugin Check `33356697426`, Media Inventory Tests
  `33356697409`, Media Upload Tests `33356697371`, Backup Job Tests
  `33356697375`: all successful for that exact commit.
- Built from a clean `git archive` with locked production dependencies and
  PHP-Scoper; syntax, scoping and collision checks passed.
- Local ZIP: `build/aws-preparation-9deffc6/ozeki-database-backup-for-s3-0.1.1.zip`.
- SHA-256: `4e8d7e1d16e53cb617044739e2d42aa80df0654edce4c1b2e33e3783e2e52cad`.
- ZIP size: 8,190,536 bytes. All **3,858** installed files matched it exactly,
  with no additional files, after installation using WordPress's ZIP upgrader.
- An initial install attempt during the still-running transfer was rejected by
  the SHA guard before any plugin update. The completed transfer was verified
  before the successful update. No installed runtime file was manually patched.
- Header remains development 0.1.1. This is **not** the public 0.1.1 release and
  must not overwrite that release or be uploaded to WordPress.org.
- Test helper commit in `wp-rescue`: `0bd51d747e032c4e4e2466e467bb3bcfe2dda9ce`.
  Compose CI `33357316637` and AWS ZIP-test CI `33357316725`: successful.

## Isolation and method

The existing `odbfs3-media-ziptest` AWS Compose project is the sole target:
WordPress 7.0 / PHP 8.3.31, loopback port 8084, dedicated DB and named volumes,
web container 256 MiB / 0.5 CPU, DB 192 MiB / 0.5 CPU. Private working storage
is `/var/lib/odbfs3-work`, outside the web root. No development source mount.

S3 uses the existing EC2 role and `ceri-secure-s3-storage-test`, region
`ap-northeast-1`, prefix `wordpress-test/media-cron-ziptest/`. Each job has its
own random run subprefix. DB schedules and retention are disabled. Credentials,
IAM, lifecycle and bucket policies are not changed.

Unlike the earlier synchronous preparation acceptance test, a guarded CLI helper
only copies/independently validates synthetic inputs and calls the shipped
`enqueuePreparation()`. At submission, phase is `enumerate`; neither `paths.jsonl`
nor `ready.json` exists. No helper calls worker `run()`/`tick()` or changes event
timestamps. Actual work runs through HTTP `wp-cron.php` and real WordPress job
storage, with the ordinary one-minute schedule and normal bounded batches.

The passive MU observer records callback phase, PHP PID, Cron context, counters,
preparation cursors and allocated PHP memory. It does not replace any store or
client, mutate progress or expose private serialized hashing contexts.

## Small fixture — passed

- Job `5b6fabd7807933da730fd76771e1fe85`.
- 31 files / 9,349,072 bytes; enqueue 0.020 seconds (excludes fixture copy/check).
- Two HTTP Cron callbacks, `2026-08-31T04:31:34Z`–`04:32:40Z` (66 seconds).
- First callback saved preparation progress at 23 files. Second resumed the
  saved state and completed preparation/upload. Both callbacks happened in the
  same Apache PID; this small run does not demonstrate cross-PID resumption.
- Final `succeeded`; no scheduled media event remained. Callback-end attempts 0.
- Observed peak PHP allocated memory: 20,975,616 bytes (not process RSS).
- Downloaded completion marker and manifest, compared with independent original
  fixture hashes, restored all files and rescanned the new tree: passed.
- Restore/check: 1.117 seconds; helper peak 59,244,544 bytes.
- Fresh restore path: `/var/lib/odbfs3-work/restore-smoke-a36acea4ad93ae71`.

## Large fixture — safe stop at final directory validation

- Job `821f843f1a07dd2811a66a56d7afe6a3`.
- 2,006 files / 1,107,367,888 bytes, including one 1 GiB file.
- Enqueue 0.028 seconds (excludes fixture copy and independent hash check).
- During preparation: job option `autoload=no`, work root 0700, no ready marker,
  and zero remote objects under this run's S3 prefix.
- Interim audit: 251 private filesystem entries, no incorrect permissions
  (directories 0700, files 0600, no links).
- Whole-file and per-part hashing of the 1 GiB input progressed across callbacks.
  Whole-file cursor 738,197,504 bytes was saved across HTTP requests; part-hash
  cursor 494,927,872 bytes was resumed in a different PHP PID (3848 -> 3686).
- All 2,006 file records and the completed inventory footer were generated. A
  separate read-only comparison matched every prepared path, size and SHA-256
  against the independently recorded original fixture. This is not an S3 restore.
- At `2026-08-31T05:57:11Z`, the job failed with `step_failed`, phase
  `validate_directories`, validation cursor 0. The root's link count changed
  from 5 to 6 and its mtime/ctime changed from 1788150824 to 1788151823.
  Existing `images`, `large` and `many` directory identities still matched.
- A new, empty `2026/08` directory tree appeared under the test upload root at
  `2026-08-31T04:50:23Z`. This is WordPress's normal year/month folder layout;
  the exact creating callback was not instrumented and is not established.
  The helper had selected a fresh synthetic upload root without first creating
  the normal WordPress year/month directory there.
- Final `ready.json` absent, zero objects under this job's S3 prefix, and no
  scheduled media event. The guard did not silently publish an incomplete backup.
- 84 HTTP callbacks, 8 distinct PHP PIDs, `04:34:08Z` through `05:57:11Z`:
  **4,983 seconds (83 min 3 s)** before the safe stop. Every observed callback
  began with the previous callback's saved counters and preparation cursors.
- Observed maximum callback span 20 seconds; sum of callback spans 180 seconds
  (timestamps have one-second resolution). Peak PHP allocated memory 8,388,608
  bytes, not process RSS. Small-file callbacks were typically 1–2 seconds.
- Final private-permission audit: 8,323 entries; no links or mode violations
  (directories 0700, files 0600).
- Failed work is preserved at
  `/var/lib/odbfs3-work/plans/odbfs3-preparation-855c683c5acccc930b2b75a4572d6da5`.
  Synthetic source is
  `/var/www/html/wp-content/uploads/ziptest-preparation-large-bb5ee3ae`.

## Decisions needed before another large acceptance run

Do not weaken directory-change validation just to obtain a pass. Complete normal
WordPress upload-directory initialization before submitting an immutable test
fixture, and separately decide the supported behavior when a real site's uploads
change during preparation. The failure demonstrates that this is operationally
relevant, even when the new directories contain no media files.

The 100-step callback cap also limits small-file preparation to approximately
25 files per minute, despite only 1–2 seconds of active work per small-file
callback. Batching efficiency should be improved while preserving short time
budgets, bounded memory and durable checkpoints. No runtime limit or validation
semantics were changed during this acceptance run; those changes need their own
implementation and tests before another full ZIP/S3 restoration acceptance run.

## Scope and retained evidence

The original fixtures, previous test results, new plans, S3 objects and fresh
restore directories are retained. Storage remains billable; no cleanup is
authorized by this report. Never delete a broad bucket prefix or workspace.

Production start times before the run:

- `wp-rescue`: `2026-08-23T10:17:52.190929068Z`.
- `wp-rescue-db`: `2026-08-23T10:17:51.866225961Z`.

Final inspection showed no OOM kills or container restarts. Production start
times remained unchanged; isolated web/DB start times remained
`2026-08-31T01:49:52.51124454Z` / `2026-08-31T01:49:36.242264813Z`.
The test work volume had 27 GiB free. Containers and evidence are retained;
the failed job is no longer scheduled. No source, S3 object or failed state was deleted.

This acceptance test does
not cover a forced kill/concurrent-worker test on AWS, admin UI, full DB/site
restoration, media retention or the complete compatibility matrix. It does not
authorize a public release. Huge single-directory enumeration may still require
the documented CLI preparation path.
