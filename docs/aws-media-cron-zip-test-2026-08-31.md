# Scoped distribution ZIP / real WordPress Cron — 2026-08-31

Result: **passed after correcting the WordPress-owned `wpdb` type during scoping**.
Scope note: this report records the tested build's former 60-second recurring
worker. It is not evidence for the later completion-paced single-event
scheduling change, which requires its own scoped-ZIP real-AWS regression.


## Artifact and defect

Base source commit: `5e706f733e3a0805ed690b3064787079c87700c5`.
The successful build includes two additional local changes:

- `scoper.inc.php`: exclude WordPress's `wpdb` class from dependency prefixing.
- `tests/manual/test-scoped-release.php`: assert that the job store constructor
  still expects the global `wpdb` type after building the distribution.

The first ZIP installed and activated, but creating the controller's real DB
store threw TypeError: expected `SecureS3StorageForWordpressVendor\wpdb`, received
`wpdb`. No job or S3 upload was created from that defective ZIP. The new regression
test fails against it, and passes against the fixed ZIP.

Original test ZIP SHA-256:
`fb56c049a1715e7e0f51bfc2544ec9694eb9b0c894c7671346ca102c47447b79`.

Successful fixed ZIP SHA-256:
`087366cad0358791dcc81ef7961eb67366d34700f320fba316ccfb637ab7ea1e`.

Local fixed artifact:
`build/aws-cron-wpdbfix-5e706f7/ozeki-database-backup-for-s3-0.1.1.zip`.

The development header still reads 0.1.1. **These are not the public 0.1.1 release
artifact; do not publish or overwrite that release.** The old public ZIP is intact.
At the time of this test the two fixes and this report are not committed/pushed.

## Environment and method

- Separate AWS Compose project `odbfs3-media-ziptest` on `ip-172-31-2-103`.
- Containers `odbfs3-media-ziptest-web` / `odbfs3-media-ziptest-db`.
- WordPress 7.0, PHP 8.3.31; PHP memory limit 128 MiB; web container 256 MiB,
  DB container 192 MiB; each container limited to 0.5 CPU.
- Loopback-only HTTP port 8084; no Traefik exposure or production networks/volumes.
- DB `odbfs3_ziptest`, table prefix `ziptest_`; persistent private work volume
  `/var/lib/odbfs3-work` outside the web root.
- Installed using WordPress's ZIP upgrader; updated only the isolated test plugin
  from the corrected ZIP. All **3,852 installed files** matched the fixed ZIP.
- No source-code bind mount or manual patch to installed runtime files.
- Default credential provider used the existing EC2 role. No AWS keys were copied.
- Test destination: `ceri-secure-s3-storage-test`, `ap-northeast-1`, prefix
  `wordpress-test/media-cron-ziptest/`. DB automatic backup and retention disabled.
- Submission used the shipped controller from a guarded CLI test helper, with
  real `WordPressJobStore` persistence. The helper never called run()/tick().
- Worker dispatch used real HTTP `wp-cron.php`, with ordinary 60-second events.
  Test health-check page requests also supplied natural WP-Cron loopback traffic.
  Event timestamps/intervals were not accelerated for the test.
- Test-only passive MU observer recorded before/after callback context and counters.
  It did not replace the store/client or modify progress/scheduling.

## Results

| Measurement | Small fixture | Large fixture |
|---|---:|---:|
| Files | 31 | 2,006 |
| Payload bytes | 9,349,072 | 1,107,367,888 |
| Largest file | 8 MiB | 1 GiB / 128 parts |
| Plan preparation | 0.316 s | 55.159 s |
| HTTP Cron batches | 1 | 22 |
| Distinct PHP worker PIDs | 1 | 4 |
| First/last worker span | 4 s | 1,261 s (21 min 1 s) |
| Download + restoration verification | 1.214 s | 126.448 s |
| Final status | succeeded | succeeded |
| Final scheduled media events | 0 | 0 |

Large run: `2026-08-31T02:07:08Z` to `2026-08-31T02:28:09Z`.
The first batch saved part cursor 89; the next batch in a different PHP process
started at that same cursor. Every later callback started from the prior saved
file/byte/part counters. All observed callbacks had `DOING_CRON=true` and
`PHP_SAPI=apache2handler`, with no overlap or retries.

The job option remained `autoload=no`. An attempted duplicate submission was
rejected without replacing the active job. The first successful job was archived
before the second submission. The S3 completion marker was absent during the
incomplete large run, then present after success. Terminal jobs did not reschedule.

Both restores required a validated completion marker and manifest SHA-256. The
downloaded manifest was compared against independent original fixture hashes;
every restored file's size and SHA-256 matched. A separate scan of each fresh
restore directory found no missing, changed or unexpected files.

Observed large-worker peak PHP allocated bytes: 8,392,704 (warm OPcache; this is
not process RSS). Restore helper peak: 59,244,544 bytes. No container OOM kills or
restarts occurred. Container limits/observations are not general performance guarantees.

## Other checks

- Media upload regression suite: 45 checks passed.
- Real SDK offline regression suite: 11 checks passed.
- Build-time scoping/collision/date/exception checks passed, including new `wpdb` check.
- Fixed ZIP first-party code passed local Plugin Check PHPCS rules (vendor excluded).
  This is **not** a new full Plugin Check CLI / GitHub Actions run. CI must be rerun
  after committing/pushing the fix.
- Test helper PHP syntax and watcher shell syntax passed.

## Retained artifacts

Small job: `c107b2f7920189204ccbf8892152f80e`.
Large job: `76eb030e4bb9df7eb5779bd3c7b7f3b1`.

S3 run prefixes (retained; storage remains billable):

- `s3://ceri-secure-s3-storage-test/wordpress-test/media-cron-ziptest/backups/media/c107b2f7920189204ccbf8892152f80e/`
- `s3://ceri-secure-s3-storage-test/wordpress-test/media-cron-ziptest/backups/media/76eb030e4bb9df7eb5779bd3c7b7f3b1/`

Inside the **test** web container:

- `/var/lib/odbfs3-work/fixtures/`: private copies and independent expected hashes.
- `/var/lib/odbfs3-work/plans/`: durable inventories and multipart plans.
- `/var/lib/odbfs3-work/cron-observations.jsonl`: passive callback journal.
- `/var/lib/odbfs3-work/restore-smoke-81a200e0aedc0c8d/`: restored small fixture/result.
- `/var/lib/odbfs3-work/restore-large-f9157f5cc955028f/`: restored large fixture/result.
- `/var/www/html/wp-content/uploads/ziptest-smoke/` and `ziptest-large/`: synthetic
  source copies only; the test site's current upload root is `ziptest-large`.

Configuration and helpers are in the sibling `wp-rescue/aws-media-ziptest/`
directory; the server deployment is `/home/ubuntu/docker/wp-rescue-media-ziptest`.
See its `MEDIA-TEST.md` for guarded reproduction commands and safety boundaries.

No production plugin, DB, settings, media, IAM policy, lifecycle, or Traefik change.
The production container start times remained unchanged. No S3 objects or source
fixtures were deleted. The isolated test containers and persistent volumes are
left available for follow-up; select exact artifacts before any later cleanup.

## Still outside this result

Preparation remains synchronous CLI work. This did not test the WP-CLI argument
parser, administration UI, forced process kills/concurrent workers, container
recreation, KMS, media retention, or matching DB/attachment restoration. It is not
the full PHP/WordPress compatibility matrix or authorization to publish a release.
