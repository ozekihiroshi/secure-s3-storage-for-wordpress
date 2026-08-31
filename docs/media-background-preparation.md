# Background preparation: bounded file hashing

Status: private file-hash primitives are now used by the
[background preparation and Cron dispatcher](media-preparation-worker.md).
That document describes the current `media enqueue` command, folder timeout,
locked scratch-file protocol and test results. The notes below retain the
single-file primitive's scope and design history; there is still no admin UI.
The public plugin and existing inventory/upload-plan formats are unchanged.

## Established baseline

The [AWS distribution-ZIP/Cron report](aws-media-cron-zip-test-2026-08-31.md)
records successful upload and independent restore of 2,006 synthetic files,
including a 1 GiB file. Every path, size and full SHA-256 matched. This proves
the prepared-plan transport, not a combined WordPress DB/media site restore.
Large-fixture preparation still took 55.159 seconds synchronously.

## Native SHA-256 state can be persisted

PHP 8 provides `HashContext::__serialize` and `__unserialize`. Ordinary SHA-256
contexts can be serialized and resumed in a new PHP process. The former working
assumption that native hash contexts cannot be persisted was incorrect.

`tests/manual/test-media-hash-checkpoint.php` checks padding/block boundaries,
binary data, multipart-size boundaries, replay from an unchanged checkpoint and
multiple successive processes. The result is the **ordinary full-file SHA-256**,
not a hash of part hashes. It uses synthetic bytes only, no WordPress or AWS.
The PHP 8.1/8.3 inventory CI runs this feasibility test with a 32 MiB limit.

## Implemented internal API

`MediaHashCheckpointStore` accepts an existing canonical, owned 0700 directory,
the media source and the actual document root. It rejects paths inside uploads
or the document root, symlink components, shared directories and replacement of
the selected directory. The caller must supply the real document root: this is
an internal API, not a request-supplied work-directory option.

Files are randomly named, exclusively created, made 0600 before data is written,
flushed and fsynced. Loading checks regular-file type, owner, permissions,
single-link count, bounded size and identity. The exact file bytes must match
the SHA-256 in the trusted job-store reference **before** JSON/native hash
decoding. That digest protects against corruption/substitution, not a compromised
WordPress database plus filesystem. Runtime metadata and run/root/path/file
identity bindings must match before resuming the native hash.

Only local SHA-256 `HashContext` objects are deserialized, with an exact class
allowlist, depth and length bounds. No request/import/S3 payload is accepted.
The native context still contains partial source bytes, so it never goes in the
WordPress job option, logs or UI. No new cryptographic dependency is introduced.

`MediaFileHashStep` consumes a claimed `media` job with:

```php
['phase' => 'file_hash', 'path' => '2026/08/example.mp4']
```

It reads at most 8 MiB per invocation in buffers of at most 1 MiB, stopping
cooperatively after 2 seconds or before the lease deadline. A blocking filesystem
syscall cannot be interrupted by this cooperative budget. Subsequent job state
contains `hash_checkpoint` (random ID + digest) and `hash_offset`, not the native
context. Reopened input is checked against saved dev/inode/mode/link-count/size/
mtime/ctime before and after reads; the path is safely resolved again afterward.

Each worker writes a separate immutable file. Only `JobRunner`'s lease-fenced
CAS selects the next reference/offset. A crash or CAS loss leaves an unselected
private file; no automatic deletion is introduced. Old selected checkpoints are
also retained for now. Future cleanup must account for live/stale readers and
be explicit. Disk exhaustion fails the job rather than discarding old backups.
File fsync is not a guarantee of directory-entry durability after a host power
failure; a missing referenced file fails closed.

At EOF the phase becomes `file_hashed` with `file_size` and ordinary full-file
`file_sha256`. Upload counters are unchanged, `complete` remains false, and no
manifest/ready marker is published. The future preparation dispatcher must handle
this phase; repeatedly sending it to the hash-only step is an invalid transition.

This slice requires POSIX permissions and fails closed on Windows (use WSL).
Existing backup paths are unaffected. Saved states require the same PHP version,
integer size, OS family, architecture, ZTS and debug flags; portability across
different builds is not promised. Runtime changes fail, without silently starting
a new hash at the saved offset. Filesystem timestamps are not an atomic snapshot:
same-size edits hidden by timestamp granularity can evade metadata checks. Keep
the existing preparation/upload checksum validation and quiescent-write policy.

## Required implementation safeguards

- Keep existing inventory version 1 and full-file SHA-256 semantics. Do not
  substitute a multipart composite checksum for a full-file digest.
- Persist only local, run-bound state. Never deserialize request, manifest,
  S3, administrator-imported or arbitrary path-supplied payloads. Restrict the
  class and algorithm, validate bounded envelopes, and fail closed on damage.
- A native hash checkpoint contains a partial block of **source bytes**.
  Treat it as sensitive backup data: private 0700 directories, exclusive 0600
  files before writing, outside the web root. Never log it or expose it in UI.
  Publish immutable checkpoint files and put only identifiers/cursors in the
  existing small CAS-controlled job state. Integrity must bind run, source
  identity, offset and state together; validate before native deserialization.
- Record runtime compatibility. A runtime change must fail safely or restart
  preparation explicitly; do not silently use an incompatible hash state.
- Reopen through the existing symlink/hardlink-safe source resolver and verify
  file identity/metadata around every bounded read. State and offset advance
  together only after the job lease/CAS succeeds. Stale workers cannot publish
  readiness or overwrite the selected checkpoint.
- Replaying an uncommitted read may recompute bytes but must not append duplicate
  inventory/part records. Avoid mutable shared scratch files between workers.
- Preserve part-checksum validation during S3 upload. Hash continuation alone
  does not create an atomic filesystem snapshot or detect every concurrent edit.
  Retain explicit quiescent-write guidance for matching DB/media backups.

## Implementation sequence (historical)

Steps 2-4 below are now implemented in the background preparation worker.
See its linked documentation for current verification scope and remaining gates.

1. Implemented: private hash checkpoint storage and bounded file reads, with
   `tests/manual/test-media-file-hash-step.php`. PHP 8.1/8.3 each pass 47 checks
   at a 32 MiB limit (observed PHP allocated peak 4 MiB), including independent
   processes, exit after save/before CAS, replay, lease expiry/competing workers,
   corrupt state, runtime/binding mismatch, source changes, permissions and links.
   These are isolated local tests, not an AWS Cron integration run or a hard-kill
   test during a filesystem write.
2. Durable file enumeration and external-sort/merge cursors. Directory iterators
   cannot simply be serialized; restarting and skipping an ever-growing prefix
   is not bounded work. Preserve unique globally ordered inventory paths and
   source-change failure behavior without loading the whole tree into memory.
3. Incremental inventory and upload-plan generation, including the inventory's
   own full-file hash and multipart description. Publish `ready.json` last.
4. Connect preparation and transfer as phases of one job. Preparation completion
   is not backup success. Preserve existing prepared-plan CLI compatibility.
5. Admin start/status UI, lifecycle/failure tests, then the isolated AWS fixture
   run again from an **unprepared** source, plus matching DB/media site restore.

Do not wrap the whole existing `MediaUploadPlan::prepare()` in one short-lease
Cron step or claim that this feasibility test completes these release gates.

References:
- https://www.php.net/manual/en/class.hashcontext.php
- https://www.php.net/manual/en/hashcontext.serialize.php
- https://www.php.net/manual/en/function.unserialize.php
