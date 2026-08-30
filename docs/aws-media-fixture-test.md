# AWS media-inventory test preparation

This development revision does NOT add media upload to the WordPress Backup Now
button. Media S3 transfer, durable background scanning and the UI are not yet
connected. Do not install an intermediate 0.1.1 CI ZIP over the public release
and expect media backup to run. No WordPress.org/SVN release is part of this step.

## Synthetic data

`tools/create-media-fixtures.php` needs CLI PHP 8.1+ with zlib, not WordPress,
Composer, AWS credentials or a network connection. It creates a NEW private
directory; it refuses existing output and never deletes anything automatically.
Inside this repository, output must be under Git-ignored `build/`.

The suggested AWS workload is:

- One real, allocated 1 GiB file with fresh random bytes per chunk (not sparse,
  zero-filled or trivially compressible).
- 2,000 small files, 16 KiB each.
- Three valid synthetic PNGs: original, thumbnail-sized and a Unicode filename.
- An empty file and a hidden file.
- Expected per-file SHA-256 values computed while generating the data, plus a
  completion record outside the synthetic uploads tree.

Total: 2,006 files, approximately 1.03 GiB. These contain no site/user data. The
large file is deliberately named `.bin`; it is a filesystem/transfer-load fixture,
NOT an audio/video file or a file intended for the WordPress media upload form.
The PNGs are usable image fixtures, but nothing is registered in the media library.

Plan at least 2 GiB of free disk for creation/inventory and at least 3 GiB if also
making a separate restored copy. Generating and hashing random data consumes CPU
and disk I/O: schedule it away from busy production hours. Start with 8 MiB before
the 1 GiB test. A failed/interrupted generation may leave partial files; inspect
the exact fixture directory before arranging cleanup. Missing/invalid
`fixture-info.json` must not be treated as complete data.

## First AWS step: read-only checks

Run on the AWS host, not local WSL:

```sh
hostname
docker inspect wp-rescue \
  --format '{{range .Mounts}}{{println .Type .Source "->" .Destination}}{{end}}'
docker exec wp-rescue php -v
docker exec wp-rescue df -h /tmp /var/www/html
```

Review these results before choosing the staging directory or copying code.
Do not remove/reinstall a plugin or change a bind-mounted source checkout to
prepare this test. Do not dump container environment variables or AWS credentials.

## Isolated execution after the target/path is confirmed

Stage the required source and two tools in a fresh private test directory,
separate from the active plugin and its uploads. Run the tools using the site's
PHP runtime, but WITHOUT loading WordPress. Keep test data outside the live
uploads tree in this phase. No S3 permissions are needed yet.

The following are templates; substitute the reviewed test paths before running:

```sh
php -d memory_limit=32M tools/create-media-fixtures.php NEW_PRIVATE_FIXTURE_DIRECTORY \
  --large-mib=8 --small-files=25 --small-bytes=4096
php -d memory_limit=32M tools/check-media-fixtures.php NEW_PRIVATE_FIXTURE_DIRECTORY
```

For the larger workload use another NEW directory:

```sh
php -d memory_limit=32M tools/create-media-fixtures.php NEW_LARGE_FIXTURE_DIRECTORY \
  --large-mib=1024 --small-files=2000 --small-bytes=16384
php -d memory_limit=32M tools/check-media-fixtures.php NEW_LARGE_FIXTURE_DIRECTORY
```

The checker independently inventories the synthetic uploads and compares every
record with the generator's expected checksums. It also validates the inventory
footer and totals. It records elapsed time and peak PHP allocation, and creates
a new randomly named inventory file on each run without modifying payload files.
Expected result: `result=inventory_matches_fixture`.

This proves local enumeration/hash/inventory behavior in the AWS site's runtime.
It does NOT prove S3 upload, background resumption, attachment registration,
restoration or database consistency. Those require later end-to-end tests after
the remaining implementation is connected. Preserve this fixture for those tests;
no large binaries or generated inventories should be committed to Git.
