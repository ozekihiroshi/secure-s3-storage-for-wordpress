# Real S3 media transfer and restore verification — 2026-08-31

Result: **passed after fixing REST-XML multipart size parsing**.

## Scope and isolation

- Runtime source commit: `5cc770b187767785940861203b4a258196dc75ac`.
- Fixed Composer dependencies installed with `--no-dev --no-scripts --no-plugins`.
- Source and dependencies copied into a private test directory in the existing
  AWS `wp-rescue` container, executed as `www-data` with PHP memory limit 128 MiB.
- Unscoped development dependencies: **not a scoped release-ZIP runtime test**.
- Test harness: `tools/test-media-s3-fixtures.php`.
- No WordPress bootstrap during transfer/restore, no plugin replacement, no DB
  restore, no media registration, no container restart, no production upload edits.
- AWS Default Credential Provider reused the existing EC2 role. No credentials
  were supplied on command lines or stored in test state/reports.
- IAM permissions for ListMultipartUploadParts and AbortMultipartUpload were
  added by the operator. No bucket policy or lifecycle rule was changed by the test.

## Fixture and result

| Measurement | Result |
|---|---|
| Files | 2,006 |
| Payload bytes | 1,107,367,888 |
| Large single file | 1 GiB, 128 parts of 8 MiB |
| Preparation | 38.136 seconds |
| S3 transfer operation window | 01:33:24–01:36:39 UTC (195 seconds) |
| Download + full restore verification | 90.342 seconds |
| Observed peak PHP allocated memory during upload | 27,267,072 bytes |
| Observed peak PHP allocated memory during restore | 25,165,824 bytes |
| Final status | `s3_restore_matches_original_fixture` |

The preparation step compared the generated inventory against the fixture's
independent `expected.jsonl`, whose SHA-256 was checked against fixture-info.json.
The S3 completion marker and downloaded manifest were validated before writing
any restored media. Every downloaded file's size and full SHA-256 matched. An
additional complete scan of the new restore directory matched the manifest with
no missing, changed or unexpected files.

The upload used multiple independent PHP processes and a private disk-backed
compare-and-swap test store. The large file resumed from its saved part cursor.
This proves ordinary cross-process continuation, **not forced-kill recovery,
concurrent-worker integration, or real WordPress option-store/Cron dispatch**.

### S3 request counts for the successful upload

| Operation | Count |
|---|---:|
| PutObject | 2,007 |
| HeadObject | 2,009 |
| CreateMultipartUpload | 1 |
| UploadPart | 128 |
| ListParts | 1 |
| CompleteMultipartUpload | 1 |

Payload totals exclude the inventory and completion marker. Timing and memory
are observations on this host, not general performance guarantees. Many small
files produce proportionally more S3 requests than a single archive.

## Defect found by the real service test

The first run used `d0a2401` and uploaded all 128 large-file parts, then safely
failed before completion. The SDK's REST-XML parser returns `ListParts.Size`
as a decimal string (for example, `"8388608"`), whereas the mock returned an
integer. The strict size comparison incorrectly rejected the real response.

Fix `5cc770b` accepts either the exact integer or its canonical decimal string,
without accepting arbitrary numeric coercion. The mock now reproduces the SDK
type, and an actual SDK/XML-handler regression test asserts the response types.
Local media tests: 45 checks; actual SDK offline tests: 11 checks. All four
workflows, including release-ZIP Plugin Check, passed for `5cc770b`.

The failed job was **not reset to running**. Its recorded multipart upload was
aborted, and ListParts returned `NoSuchUpload`. A fresh plan and random job ID
were used for the successful full rerun.

## Retained test artifacts

These are diagnostic fixtures, not production backups. Nothing below is a
cleanup instruction; do not recursively delete broad paths or bucket prefixes.

- Original container fixture:
  `/tmp/odbfs3-media-test.1tl1sbb4/large`
- Host source/dependencies:
  `/tmp/odbfs3-aws-transfer.KRQDIILw/source`
- Failed container run (terminal job/log retained):
  `/tmp/odbfs3-aws-run.9D3whkib`
- Failed S3 run:
  `s3://ceri-secure-s3-storage-test/wordpress-test/media-transfer-test/backups/media/171305a2a5e465f28735a3016b5baef5/`
  Its incomplete 1 GiB multipart upload was aborted. Five completed small fixture
  objects remain; this run has no success marker.
- Successful container run, plan, job state and operation journal:
  `/tmp/odbfs3-aws-run.YIyVpbP4`
- Restored files:
  `/tmp/odbfs3-aws-run.YIyVpbP4/restore-6a4109603dca4a73`
- Successful S3 run:
  `s3://ceri-secure-s3-storage-test/wordpress-test/media-transfer-test/backups/media/f1bbb692bd810e66699a3d220bc67de3/`

Container `/tmp` artifacts disappear if the container is recreated. S3 test
objects remain billable until explicitly deleted. No lifecycle policy was
installed; the one failed recorded multipart upload was explicitly cleaned up.

## Reproduction notes

The helper is intentionally restricted to this test bucket/prefix and private
`/tmp/odbfs3-aws-run.*` work directories containing synthetic fixtures under
`/tmp/odbfs3-media-test.*`. It is excluded from the release ZIP. Review its
fixed target before using it in another environment; it is not a general backup
or production restore command.

```text
php tools/test-media-s3-fixtures.php SOURCE_DIRECTORY prepare WORK_DIRECTORY FIXTURE_DIRECTORY
php tools/test-media-s3-fixtures.php SOURCE_DIRECTORY tick WORK_DIRECTORY
php tools/test-media-s3-fixtures.php SOURCE_DIRECTORY restore WORK_DIRECTORY
```

`tick` exits 2 when more work remains, 0 on success, and 1 on failure. Do not
continue to restore after failure. Restore creates a new empty directory each
time and retains partial output if interrupted. All state and diagnostic output
exclude credentials and signed requests. Operation journals include upload IDs
for precise manual failure cleanup and must stay in the private test directory.

## Remaining publication gates

- Scoped distribution ZIP against real S3.
- Actual WordPress DB-backed job store and Cron/CLI lifecycle integration.
- Forced process termination and real concurrent-worker tests.
- Background preparation and administration UI.
- Restore matching WordPress DB/media references in a separate test site.
- KMS-specific permissions/checksums, very large part counts, media scheduling,
  retention and completed-object cleanup policy.

This result validates the real S3 transport and byte-for-byte recovery of the
fixture. It does not declare the full media feature ready for public release.
