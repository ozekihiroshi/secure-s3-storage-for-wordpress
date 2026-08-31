# Media inventory: implementation slice 2

Status: internal implementation for v0.2, now used by prepared-plan uploads and
the [background preparation/Cron worker](media-preparation-worker.md).
No admin UI is available yet. The CLI/library details below describe the original scanner.
No public version, existing DB backup, settings or release ZIP is changed.

## Scope

The WordPress adapter uses `wp_get_upload_dir()['basedir']`, not a fixed uploads
path or an HTTP-request parameter. The adapter currently rejects multisite:
the main site's uploads tree may also contain other sites, so network-wide
backup/restore scope must not be implicit. This follows the project's initial
single-site scope; file size/count are not capped.

All regular files below the root are inventoried: media-library originals,
thumbnails, hidden files, zero-byte files, and other plugin-created files within
uploads. There is no extension filter or silent exclusion. Empty directories,
ownership and Unix mode bits are not restoration data in this version. A missing
root is an error, not a successful empty backup. An existing empty root is valid.

Symlinks (including internal and dangling links), hard-linked files, FIFOs and
other special entries fail the inventory. Unsafe/ambiguous relative paths fail:
absolute paths, empty components, `.`/`..`, NUL, colon, backslash and invalid UTF-8.
Unicode, spaces, quotes and newlines are represented without lossy sanitization.
This is a conservative portability/security policy, not a capacity restriction.

## JSON-lines format

The inventory is an exclusively created 0600 local file outside uploads. Each
line ends with LF. The first line is exactly this schema:

```json
{"format":"odbfs3-media-inventory","version":1,"algorithm":"sha256"}
```

File records contain `path` (relative to uploads, slash-separated), `size`
(integer bytes), and `sha256` (standard SHA-256 of the complete file bytes).
Records are strictly ordered by bytewise `strcmp(path)`. No duplicates are valid.
Records contain no absolute server paths, AWS credentials or source file contents.

The final line has `type: inventory_end`, `files`, `bytes`, and `sha256`. Its hash
covers the exact bytes of the header and file lines, including LF, excluding the
footer. Counts must match. Readers reject missing footers, extra trailing data,
unknown versions, invalid entries and corrupt hashes. The parser caps one record
at 64 KiB, not a media file or the whole inventory.

This hash detects corruption, not malicious rewriting of both data and hashes.
The inventory must come from a trusted backup source. A future S3 completion
record must bind the inventory/object identities to the backup run. Merely
finding this local inventory is NOT proof of a successful remote backup.

## Memory and disk use

- Files are hashed with 1 MiB reads; file contents never accumulate in memory.
- Directory enumeration uses streamed directory handles, not `scandir()` arrays.
- Inventory sorting uses bounded batches (default 2 MiB of encoded records),
  private 0700 work directories and 0600 sort files. PHP object/array overhead
  adds memory beyond that encoded budget.
- Binary merging opens two input streams at a time and keeps logarithmically
  many run names. No global list/set of every media path is retained in RAM.
- Work disk use scales with inventory size, not media content size. Sorting
  requires extra temporary copies of inventory records. Disk exhaustion is an
  error, not a partial success.
- Output and work parents must resolve outside the source tree. Existing output
  files are never overwritten. Handled failures remove the newly created partial
  output and owned sort artifacts, never source media. Process termination may
  leave private artifacts; durable run-owned recovery/cleanup is a later
  integration gate, not something PHP finally blocks can guarantee.

## Restore verification

`MediaManifest::verify()` is read-only with respect to restored files. It fully
validates the expected inventory, independently enumerates/hashes the restored
tree and compares sorted streams. Its result counts matched, missing, changed
and unexpected files. Matching size alone is insufficient: SHA-256 must match.
Unexpected files make exact-restore verification fail; the verifier never deletes
them. Invalid manifests and unreadable/changed files throw safe errors instead
of returning success. No extraction, copying or DB restoration is implemented here.

`MediaManifest::entries()` yields records before reaching the footer; a caller
must consume it to EOF before trusting the inventory. Never use this incremental
reader for destructive actions or announce success before complete validation.

## Live-file consistency and future background integration

The scanner checks path components and compares device/inode/type/link count,
size, mtime and ctime before/open/after reading. Directory metadata is rechecked
after traversal. Observable replacement, growth, disappearance and permission
changes fail the scan. File reads exclude atime from comparisons.

These checks are not a filesystem snapshot. Same-size writes within timestamp
resolution or deliberate metadata-preserving changes may escape stat checks;
files may also change after their scan finishes. PHP portable streams do not
offer an atomic `openat(O_NOFOLLOW)` walk, so a hostile process racing filesystem
changes is outside this implementation's guarantee. Use a trusted upload root
and quiescent writes/snapshot when a point-in-time backup is needed.

The future uploader MUST checksum the bytes actually sent and compare against
the inventory, failing/retrying the run if they differ. DB and media are not an
atomic site snapshot merely because both operations finish successfully.

An optional per-chunk callback allows cancellation/heartbeat without coupling
this original synchronous scanner to WordPress. The background route now uses
separate directory, sort and hash steps, including private native SHA-256 state
checkpoints. Directory handles are not serialized: a folder must finish within
its time budget or the job explicitly fails with CLI preparation guidance.
See [background preparation](media-preparation-worker.md) for the lock/CAS
protocol, source checks and remaining real-AWS integration gate.

## Local verification

```sh
php -d memory_limit=32M tests/manual/test-media-inventory.php
php tests/manual/test-wordpress-media-source.php
```

Run as a non-root user so unreadable-directory fixtures are meaningful. The
tests use generated private OS-temp directories only. They include a 42 MiB
file, 25,000 synthetic inventory records, multi-level/default-budget merging,
empty files, Unicode, unsafe paths, links/FIFO, file growth/replacement,
directory changes, cancellation, corrupted/truncated manifests and exact-restore
differences. WordPress adapter tests use stubs, not a real site.

### Verified on 2026-08-31

- PHP 8.1 and 8.3: 93 inventory assertions each, with 32 MiB PHP memory limit;
  measured peak allocation 14 MiB including the default-budget sort test.
- PHP 8.1 and 8.3: 8 WordPress adapter assertions and 49 existing job-runner
  assertions each.
- New runtime files: PHP 8.1 syntax and PluginCheck PHPCS standard passed.
- New CI definition: actionlint 1.7.12 passed. Remote GitHub Actions not run.
- Tests ran as an unprivileged user in disposable, network-disabled containers;
  source mounts were read-only, and all generated files were in container tmpfs.
- No S3 operations, production writes, real DB changes, release builds,
  full release-ZIP Plugin Check, commits, pushes or publication in this slice.

Related design: [media-backup-v0.2.md](media-backup-v0.2.md).
