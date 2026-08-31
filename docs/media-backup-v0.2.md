# Media backup: v0.2 implementation plan

Status: in development; not a published feature. Existing database behavior is unchanged.
Prepared media plans can now be submitted explicitly through CLI and processed by
WP-Cron. See [prepared media upload worker](media-upload-worker.md) for commands,
failure policy, validation and the remaining publication gates.

## Agreed requirements

- No arbitrary cap on the total number or size of media files. Execution time,
  memory, disk space, AWS limits and permissions still apply; do not promise
  unlimited capacity on every host.
- Start work independently of the browser. Show queued, running, succeeded or
  failed, with processed file/byte counts rather than a guessed percentage.
- Bound individual work steps and persist checkpoints. Manual pause/resume is
  not part of the first version. Bound crash recovery and retry attempts.
- Reuse AWS's default credential provider. Never persist credentials, signed
  requests, raw exception messages or serialized SDK objects in job state.
- Preserve existing database backups and schedules during implementation.

## Implementation slices

1. Persistent single-job slot, atomic state transitions, worker leases and tests.
2. Media enumeration, immutable run-specific storage and a completion manifest.
3. Bounded S3 transfers, multipart cleanup and restore verification.
4. WP-Cron/CLI dispatch, administration actions/status, lifecycle cleanup.
5. Separate media schedule/retention, regression tests, release ZIP and Plugin Check.

The job/inventory libraries and prepared-plan S3 transfer with Cron/CLI dispatch
are implemented. Explicit `media enqueue` now runs background preparation and
hands off to the same Cron uploader; see [preparation worker](media-preparation-worker.md)
for per-directory time limits and the approved CLI fallback. No job starts
without explicit submission. The [development admin panel](media-admin.md) now
supports explicit start and current-job status; media retention remains unimplemented.
See [inventory format and limits](media-inventory-format.md).
Do not change the public version or declare media support until all required slices pass.

## Job state contract

Use one non-autoloaded WordPress option per site and compare-and-swap the exact
stored bytes. Do not use read-then-update transients as a mutex. A random run ID
isolates output; a random lease token plus compare-and-swap fences stale workers.
Checkpoints are small cursors/identifiers, not an in-memory list of every file.
The persistent option is read directly from the database, not an object cache.

A successful step checkpoints progress and releases its lease. A process crash
leaves a lease that can expire; repeated crashes eventually mark the job failed.
An expired worker cannot commit progress. A lease cannot stop a paused PHP
process from later sending an external request: every future handler MUST use
idempotent, run-specific object keys and must not delete earlier backups.
The runner is cooperative, not a process watchdog. Each handler must bound CPU,
file reads and HTTP timeouts within its lease. The prepared-plan handler is connected.

Only the final verified manifest may declare a media backup complete. A failed
or interrupted run must never trigger retention of earlier completed backups.
The single slot is not history: before enabling new runs, archive terminal
results durably in the history repository. Storage errors must fail closed.

## Media format and consistency (to implement)

Prefer individual objects under a unique run prefix, with a manifest of relative
paths, sizes and checksums. This avoids creating a second full copy of uploads
on local disk and permits bounded work. It costs more S3 requests than an archive
and requires a manifest-aware restore tool; validate that trade-off with tests.
Do not implement deduplication/incremental retention in the first release.

Resolve the upload root through WordPress. Do not follow symlinks out of the
approved root or accept arbitrary request-supplied filesystem paths. Do not
silently omit unreadable/changed files and then report success. Decide and test
the policy for files changing during enumeration/transfer. A live filesystem
copy and a DB dump are not an atomic site snapshot; document the consistency
window and test restoration during quiescent writes.

Database dump generation cannot resume a lost transaction snapshot. Restart
that phase on failure; uploading an already completed immutable dump can resume.

## Acceptance gates before enabling the feature

- Empty uploads, Unicode paths, zero-byte files, thumbnails, large single files,
  many files, unreadable files, symlinks and concurrent changes.
- Two workers, stale worker, crash before/after checkpoint, lease expiration,
  bounded retries, storage failure and scheduler failure.
- Multipart failure/abort, temporary artifacts and least-privilege IAM policy.
- Restore to a separate uploads directory; compare paths, sizes and checksums;
  restore the matching DB and verify media references.
- No long-lived key fields, secret-bearing error output, public backup objects,
  or deletion of another run/site/type's backups.
- Deactivation stops dispatch; uninstall removes local job metadata only, not
  S3 backups. Explicit policy for incomplete-run cleanup is required.
- Test only in isolated local fixtures/8082 after verifying its mounts. Never
  uninstall on source-bind-mounted 8081. Production/SVN/GitHub release changes
  require a separate publication step.

## References

- https://developer.wordpress.org/reference/functions/wp_upload_dir/
- https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/
- https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/s3-multipart-upload.html
