# Recovery pipeline interruption and resume verification

DB retransmission, media child-job connections and conditional manifest publication are implemented in `RecoveryPipeline`, `RecoveryCollector` and `RecoveryPublisher`. Local acceptance passed using PHP 8.3, disposable MariaDB 11.8 and disk-backed mock S3. Both real database dump backends and the real media preparation/upload workers were exercised. This is not an AWS service test, a WordPress site restoration certification or a published feature.

## Results

`bash tools/test-database-recovery-local.sh` creates one disposable, resource-limited MariaDB container with new source/restore databases. It never selects an existing container or production database. Its EXIT handler removes the container it created.

For each backend, `native` and `php`, the runner first verifies the existing database recovery fixture and then runs `tests/manual/test-recovery-pipeline.php`. The pipeline test passed 94 checks for each backend, including:

- A real SQL dump, gzip preparation and durable private receipt before any remote operation.
- Actual SIGKILL at six boundaries: before DB upload, after remote DB acceptance, after a multipart media part is accepted, after the media completion marker is accepted, after manifest PUT acceptance, and during independent manifest GET.
- A new PHP process and reconstructed collector/workspace for each subsequent tick. No in-memory cursor is needed for recovery.
- A changed source database after preparation, with exactly one dump invocation and unchanged gzip SHA-256 throughout retries. Restoration includes the original rows, not the later source change.
- The same persisted media job ID and one multipart upload session after restart. The media fixture includes a 17 MiB multipart file, a Unicode filename and an empty file.
- Independent SQL import and row fingerprint comparison, plus independent media reconstruction and inventory/full-file SHA-256 verification.
- A separate process holding the workspace flock while another tick returns `busy` without changing collector state. Killing the lock holder releases the OS lock.
- Frozen manifest bytes across publication retries and a durable receipt only after independent HEAD and GET verification. The PHP run additionally exercised the updated restoration lookup using component keys read from the remotely stored manifest; the native run used the equivalent known fixture keys.

`php tests/manual/test-recovery-publication.php` passed 39 additional checks. These cover transient failure before PUT, lost PUT acknowledgement, failed GET, conflicting objects, a race between HEAD and PUT, wrong HEAD checksum, altered/truncated/oversized downloaded bytes, wrong VersionId, claim contention, lock contention, final CAS loss, forged receipt digest, bounded retry exhaustion and lease expiry. The mock verifies conditional PUT, pinned GET and bounded range arguments. Restarting after accepted PUT or lost local commit does not issue another PUT.

`php tests/manual/test-recovery-workspace.php` passed 17 negative and replay checks: private permissions, web-root exclusion, unchanged intent, lock contention, no network call before preparation, no repeated dump/PUT when the object already matches, local/remote checksum mismatch, symlink rejection and refusal to reset committed artifacts.

The contract and collector suites passed 127 and 34 checks respectively. Media upload regression passed 60 checks, including separate recovery destinations and full versus composite inventory checksums.

The publication change reran contract (127), collector (34), workspace (17), media upload (60), and database integrity (46) checks successfully. The preceding DB/media work also passed media preparation (415), preparation batches (3,048), media cleanup (26), and backup job runner (49) checks. Recovery PHP lint and `git diff --check` passed. These are local PHP 8.3 results; the remote CI matrix has not been run for these uncommitted changes.

## Runtime boundaries

The caller supplies a persistent private POSIX work parent outside both the document root and uploads. A set-specific workspace binds the original intent. Database credentials remain constructor/runtime inputs, not checkpoint fields. `RecoveryCollector` can use `WordPressJobStore::forRecovery` for its parent state; the child media state resides in that workspace and is distinct from the existing standalone media slot.

Collection ticks stop at `ready`: verified component evidence and assembled manifest bytes. Explicit `RecoveryCollector::publish` persists `publishing` before any network call and advances to `complete` only after exact remote verification and a successful final CAS. The separate receipt records bucket, key, size, SHA-256, optional VersionId, verification time and method. It does not rewrite the manifest or establish current component availability or WordPress restore success. No new admin button, CLI command, Cron registration or production deployment is enabled here.

`RecoveryPublisher` uses conditional creation, validates HEAD size/SHA-256 and downloads at most the expected length plus one detection byte. GET is pinned by ETag and optional VersionId. The AWS adapter streams GET responses, retries conditional-write 409 responses and reports insufficient request time as retryable. These adapter changes still need the real-S3 release gate below. Conditional creation is not S3 Object Lock: authorized external writes and lifecycle deletion remain possible.

Prepared SQL/gzip, child state and preparation scratch files remain private and retained. This permits recovery after failure but requires disk-capacity planning. No broad cleanup or retention deletion is performed. Restart before a prepared DB receipt exists may repeat local preparation; restarting an upload uses the already prepared bytes. Losing the workspace prevents safe automatic continuation and must fail rather than silently recapture.

## Remaining release gates

The WordPress-backed state-store and synthetic WordPress content/attachment restoration gate now passes locally for both dump backends. See [WordPress recovery verification](wordpress-recovery-verification.md) for its separate scope and reproducible runner.

- Run the exact candidate ZIP against an explicitly approved real-S3 test prefix, including IAM and encryption behavior.
- Define operator setup/cleanup guidance, check packaging and Plugin Check, then perform an explicitly requested release/deployment.

Production data, AWS resources, the published 0.2.1 package, Git commits and pushes were not changed by this local acceptance work.
