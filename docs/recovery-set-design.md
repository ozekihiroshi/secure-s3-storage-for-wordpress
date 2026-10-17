# Recovery set design

Status: domain contract, replay-safe DB/media adapters, collection and conditional manifest publication implemented; local tests use mock S3, not AWS. Not a feature of published 0.2.1. No production entry point is enabled.

The next milestone links a database backup and an uploads backup into an identifiable recovery set. A completed manifest identifies the material and verification evidence; it does not promise an atomic snapshot or automatic full-site restoration. This design implements the direction of the Recovery Integrity Baseline upgrade plan, sections 4.3 through 8 and 14.3.

## Existing integration points

- `DatabaseBackupService` produces independently verified database artifacts. `BackupHistoryEntry` records their key, size, SHA-256 and verification label. The bounded history is not a permanent recovery catalog.
- `MediaManifest` is the existing per-file inventory, not the proposed unified manifest. Its footer digest covers the header and entry lines; the full inventory object digest is a separate value.
- `MediaUploadStep` writes `complete.json` after verifying the upload plan and objects. That marker binds the job ID, full inventory digest, counts and path-to-object mapping.
- `BackupJob` has its own strict execution states and record schema. Do not add a recovery job type or new fields without explicit migration and regression tests.
- `S3BackupRetentionManager` currently calculates retention for database objects alone. It does not protect references from recovery sets.

## Identity and capture scope

Generate a recovery set ID with `bin2hex(random_bytes(16))` before either component starts. Persist the set intent before external work, and carry its ID through component records. Do not derive it from timestamps, URLs, database names or existing media job IDs.

Declare one profile at creation:

- `database`: database required; uploads explicitly excluded.
- `database_uploads`: database and uploads required, including a valid empty uploads inventory when there are no files.

Never downgrade the profile when media fails. Never pair objects by newest timestamp. The first implementation creates new components for a set; attaching historical backups and sharing components between sets are out of scope.

Use a random installation `source_id`, persisted locally, and bind the bucket, region and normalized prefix at creation. A cloned installation must establish a new identity before creating new sets. Identity helps prevent accidental mixing; it is not authentication. A source URL may be recorded without user information, query or fragment, but is not an identity key.

Record UTC start and end times for database capture and media inventory preparation separately from transfer completion. Default consistency is `uncoordinated`. Operator-confirmed write quiescence may be recorded with its interval and method, but must be labeled as an operator assertion. Neither matching IDs nor maintenance mode proves a transactionally consistent WordPress snapshot. Unknown capture intervals cannot support a quiescence claim.

## Manifest contract

Use a small UTF-8 JSON object with `format: odbfs3-recovery-set` and `schema_version: 1`. The following fields are the proposed contract, to be fixed by positive and negative fixtures before integration:

| Field | Required content |
| --- | --- |
| `set_id`, `source_id`, `profile` | Validated identities and declared requirements |
| `created_at`, `completed_at` | UTC intent creation and component verification completion; manifest publication completion belongs in a separate receipt |
| `environment` | WordPress, PHP and backup plugin versions; database server version and table prefix needed for the restore procedure |
| `capture` | Component capture intervals, transfer intervals and consistency assertion |
| `database` | Bucket, region, exact immutable key, optional S3 version ID, compressed byte count and full SHA-256, dump backend, raw SQL byte count and SHA-256, verification method and time |
| `media` | Null for database-only; otherwise job ID, bucket, region, exact prefix, inventory and marker object keys, their full byte counts and SHA-256 values, optional version IDs, file count and total file bytes |
| `verification` | Component evidence and timestamps; explicitly distinguish full-object and multipart composite checksums |
| `scope` | Included database/uploads and excluded WordPress core, themes, plugins, configuration, other files and server resources |
| `requirements` | Separately supplied software/configuration and consistency limitations |

Clarification for timestamps: `completed_at` in immutable manifest bytes means component verification completed, before manifest upload. A separate publication receipt records successful upload and verification of those exact bytes. Do not rewrite the manifest to add its own upload completion time.

Validate types, counts, SHA-256 encoding (64 lowercase hexadecimal characters), UTC dates and interval ordering. Bound the JSON to 64 KiB and depth 16; reject duplicate keys, unsupported schema versions and unknown structural fields in version 1. Large inventories remain external. Do not serialize arbitrary WordPress options or raw job checkpoints.

The inventory object SHA-256 is not its internal footer digest. A multipart composite checksum is not a full-file SHA-256. Restore verifies each downloaded file against the full-file digest in the inventory. ETags are not integrity evidence.

Manifest references must stay inside the frozen destination and component namespaces and match the recorded source/set bindings. Validate marker job ID, inventory hash, mapping rule and counts against the intended media job, not merely the existence of `complete.json`.

No credentials, signed URLs, raw exceptions, private temporary paths or database rows belong in this metadata. Treat manifests as private backup metadata. Hashes detect corruption, not malicious replacement of both metadata and data by an authorized attacker; they are not digital signatures.

## Execution and evidence states

Keep these dimensions separate:

| Dimension | Values and meaning |
| --- | --- |
| Execution | `collecting`, `publishing`, `complete`, `failed`; retries resume durable checkpoints, not a terminal failed set |
| Component integrity | `unverified`, `verified`, `failed`, with method and time |
| Current availability | `unknown`, `available`, `missing`, `inaccessible`, checked at a stated time |
| Restore evidence | `not_tested`, `passed`, `failed`, with exact manifest digest and test scope |

Only a verified, published manifest permits execution `complete`. A component may have succeeded while the set failed. Report partial material explicitly without publishing a completed manifest. An access-denied response is `inaccessible`, not proof of deletion. A past restore pass does not prove artifacts still exist today.

Store later availability observations and restore reports separately, keyed by set ID and manifest digest. Do not mutate the original completion evidence. Reports must identify test environment versions, separately supplied dependencies and failed checks without embedding credentials or restored content.

## Publication and crash recovery

Persist set intent and the exact manifest bytes/digest in durable private storage, independent of the last-20 history display. Do not expose a production set command until checkpoint storage, concurrent-worker fencing and restart tests exist.

Write `<prefix>/backups/recovery-sets/<set-id>/manifest.json` only after all required component evidence validates. Use a conditional create and verify remote byte count and checksum before recording success. A lost upload acknowledgement must reconcile the existing object against the persisted expected bytes/digest; a mismatch is a conflict, never permission to overwrite. Do not recreate timestamps on a retry.

`RecoveryCollector::publish` explicitly advances a prepared `ready` record to `publishing` before network work. `RecoveryPublisher` performs HEAD, conditional PUT if missing, independent HEAD and bounded GET. GET is pinned to the observed ETag and, when returned, VersionId; the ETag is a concurrency guard, not a checksum. Completion requires exact downloaded bytes, schema validation, SHA-256 and size agreement, a valid lease, and successful final CAS. Its local receipt contains bucket, key, size, SHA-256, optional VersionId, verification time and method. This is a publication-time observation, not a later availability check or restore certification.

Conditional creation prevents this implementation from overwriting an existing manifest; it is not S3 Object Lock and does not prevent another authorized actor or lifecycle rule from replacing/deleting it. A failed or interrupted local publication may leave a remotely accepted manifest. Reconciliation never deletes it. Terminal failed records do not silently retry.

An uploaded media marker without a finalized local job record also needs exact reconciliation, not automatic failure or blind trust. An interruption never causes deletion of successfully uploaded components. Discovery must work from S3 manifests when the source WordPress database is lost; local history is a convenience, not the sole index.

## Retention boundary

The initial set-managed artifacts will use a separate namespace under `<prefix>/backups/recovery-sets/<set-id>/`, including database and media subdirectories. Parameterize destination generation without changing legacy standalone destinations. The existing database retention scanner must not discover these objects; prove this with regression tests before enabling set creation.

This boundary avoids introducing a race between a new manifest reference and the old independent DB retention routine. Initial set-managed objects have **no automatic deletion**. Display this storage implication when enabling the feature. Existing standalone retention remains unchanged. No existing backup is moved, renamed or silently adopted.

Set-aware retention is a later gated change: select whole sets, protect in-progress sets, persist an expiration intent, fence publication against deletion, and resume partial deletion safely. A set must not be presented as currently available after expiration begins. Failed/incomplete-set cleanup also needs an exact ID and explicit policy; it is not covered by legacy DB retention.

Bucket lifecycle rules and external deletions can still remove objects. Review their prefix scope before deployment, without modifying bucket policy automatically. Availability checks must report resulting gaps honestly.

## Acceptance tests and implementation order

1. Add pure domain models and contract fixtures for IDs, profiles, metadata validation and state transitions. Tests must reject missing media, cross-set/source references, unknown schemas, duplicate keys, malformed hashes, invalid intervals and oversized metadata. Database-only and empty uploads are valid distinct cases.
2. Add destination isolation and persistent orchestration. Test concurrent starts, restart at every boundary, late workers, changed destination settings and legacy retention candidate selection. Preserve existing standalone DB/media behavior and old history decoding.
3. Add immutable publication using fake storage first. Test lost responses, conditional-write conflicts, wrong size/hash, stale media markers and inventory count mismatches. No failure may publish completion or delete earlier artifacts.
4. Add independent download and restore tooling for a synthetic WordPress fixture in an empty isolated environment. Read component locations from the manifest rather than separately supplied keys. Verify SQL/gzip and inventory/file hashes before restoration; reject traversal, links and destinations outside the designated restore root.
5. Restore posts, pages, options, Unicode/serialized values and attachments; check actual media references and image files, not just row counts. Supply core/themes/plugins/configuration separately, disable outgoing mail, external calls and scheduled jobs, and keep the source fixture out of reach of the restore target. Exercise native and PHP dump paths.
6. Repeat the scoped release ZIP test against an explicitly approved test S3 prefix, including interruption and missing-object cases. Record checks and limitations in a separate restore report. Production data transfer or restoration requires separate authorization.

After these gates, expose a small user workflow and a versioned machine-readable status contract. Cron health, common failure categories across all existing operations, automated set retention and a general production media restore workflow remain separate milestones. Do not advertise them as consequences of manifest support.

## Handoff

The domain contract now has executable validation tests. `RecoverySet` provides fixed database/media destinations. The existing one-shot database service and media upload step accept an optional frozen intent; callers without it retain their existing destinations. Set-managed objects are excluded by the existing database retention discovery and deletion guards.

`RecoveryCollector` persists intent, environment, component evidence and progress through `JobStore`. It uses exact compare-and-swap claims, expiring worker leases and bounded retries. Its phases are `database`, `media`, `assemble`, `ready`, `publishing`, `complete` and `failed`. Ordinary collection ticks stop at `ready`; a caller must explicitly invoke publication. `complete` requires remote manifest verification and a durable publication receipt. `WordPressJobStore::forRecovery` provides an independent, non-autoloaded option per set. No retention or retirement of these records is enabled.

Local contract tests reconstruct collectors and their test file store between operations, simulate lost responses and lease expiry, reject stale workers, and verify that partial failure preserves earlier evidence. The real-pipeline test additionally kills PHP processes at six DB/media/manifest transfer boundaries and resumes in new processes, using disposable MariaDB and disk-backed mock S3. The WordPress temporary-table integration test now passes 22 assertions with real WordPress loaded. A separate synthetic WordPress fixture uses the real parent state store, publishes a manifest, removes the source DB and restores into a new isolated target; both dump backends pass 46 restore assertions, including HTTP page/image delivery.

`RecoveryPipeline` now connects real dumpers, gzip preparation, conditional DB upload, and the existing media preparation/upload workers. `RecoveryWorkspace` retains prepared SQL/gzip and their evidence outside the web/uploads roots. A saved prepared receipt is the boundary: retries after that point verify and reuse the same gzip, never recapture the database. A crash before prepared evidence exists may repeat local preparation, but no upload is allowed before that evidence is durable. The legacy `DatabaseBackupService::backup` remains a separate one-shot API.

Media binding is saved before child execution. `RecoveryChildStore` persists the same child ID and checkpoints under the private workspace lock. The parent collector acquires that lock before reading or claiming its state and holds it through the final compare-and-swap, so a paused process cannot be bypassed merely because its lease expired. Child preparation keeps its own existing workspace lock. Child busy waits do not consume transfer failure retries. Full inventory hashes and S3 multipart composite checksums remain distinct.

This implementation requires POSIX private permissions and persistent local storage. SQL, gzip, job metadata and abandoned initialization scratch files are retained; automatic cleanup is not implemented. Operators must provision capacity and a persistent mount before enabling it. Files are flushed and synced before atomic receipt replacement; the acceptance test covers process loss, not power-loss durability. A crash after multipart creation but before persisting its upload ID can still leave an orphan, as in the existing uploader.

See [the pipeline report](recovery-pipeline-verification.md), [WordPress recovery report](wordpress-recovery-verification.md), [real-S3 acceptance](aws-recovery-set-verification-2026-10-17.md) and [0.3.0 operator deployment](production-recovery-update-2026-10-17.md) for commands and exact scope. Source-tree real-S3 acceptance and exact-ZIP local recovery passed. No new CLI/admin entry point or Cron registration is enabled. Published WordPress.org 0.2.1 claims remain distinct from this internal foundation.
