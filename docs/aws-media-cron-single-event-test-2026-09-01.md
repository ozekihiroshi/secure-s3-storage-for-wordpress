# Completion-paced media Cron / real AWS regression — 2026-09-01

Result: **passed** for the scoped development ZIP, real WordPress HTTP Cron,
the 2,006-file / 1.03 GiB fixture, and an independent full-file restore.

## Artifact and isolation

- Plugin commit: `adc1a7627b8b442278676402cd18646a2ed8a2b7`.
- ZIP SHA-256:
  `b6d1fdebcbf73924e31bbc94194213880567f7106878fe627fffe36597ab725c`.
- All 3,861 installed files matched the ZIP; no additional plugin files existed.
- The test used only the isolated `odbfs3-media-ziptest` WordPress/MariaDB
  stack, database `odbfs3_ziptest`, table prefix `ziptest_`, and loopback port
  18084. Production WordPress, WordPress.org SVN, and the public 0.1.1 artifact
  were not changed.
- Destination: `ceri-secure-s3-storage-test`, prefix
  `wordpress-test/media-cron-ziptest/`. Existing EC2 default-provider
  credentials were used without printing or copying them.

## Dispatch and completion

Job `5810eb44b5883b0b5cc42aded474cce4` was enqueued from an independently
verified fixture containing 2,006 files and 1,107,367,888 bytes. The helper did
not prepare the inventory or invoke `run()` / `tick()`. Subsequent work used
ordinary HTTP `wp-cron.php`; event timestamps were not edited. A 15-second
container health request and the unchanged 30-second observer request supplied
normal site traffic.

The passive journal verified 21 non-overlapping callbacks across two PHP PIDs,
with `DOING_CRON` true, no failed retry, monotonic counters, and continuous
preparation/upload checkpoints. Background preparation resumed in a different
PID. The final state was `succeeded`, 2,006 files / 1,107,367,888 bytes,
attempts zero, an empty error code, and no remaining media Cron event.

| Metric | Former 60-second recurrence | Completion-paced single events |
| --- | ---: | ---: |
| HTTP Cron callbacks | 32 | 21 |
| First/last callback span | 1,858 s | 536 s |
| Approximate time inside callbacks | 305 s | 315 s |
| Approximate time between callbacks | 1,553 s | 221 s |

The comparable callback span fell by about 71%, while measured idle time fell
by about 86%. This result depends on traffic frequency and does not promise a
fixed wall-clock duration on another WordPress site.

## Independent restoration

The test downloaded the new completion marker and inventory, verified the
inventory SHA-256 against the trusted job state and fixture expectations, then
downloaded every media object into a new private directory. Every relative
path, size, and complete-file SHA-256 matched, and a final independent tree scan
found exactly 2,006 regular files and no symbolic links.

- Restore directory:
  `/var/lib/odbfs3-work/restore-large-bf077253b9970865` (`0700`, www-data).
- Result record: `result.json` (`0600`, www-data).
- Restored bytes: 1,107,367,888.
- Download and verification: 115.994 seconds.
- Peak allocated PHP memory: 59,244,544 bytes.
- S3 run prefix:
  `wordpress-test/media-cron-ziptest/backups/media/5810eb44b5883b0b5cc42aded474cce4/`.

Old/new ZIPs, fixture roots, job metadata, S3 objects, and restored evidence
were retained. No cleanup or deletion was part of this acceptance test. This is
a media-file restore verification, not a database/attachment/full-site restore.
