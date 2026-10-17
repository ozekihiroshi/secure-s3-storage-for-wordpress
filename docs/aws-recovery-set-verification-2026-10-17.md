# Real S3 recovery set verification

The dedicated S3 test uses synthetic WordPress data, not a production database or uploads. It transfers a database/uploads recovery set to S3, publishes and verifies its manifest, downloads every object independently, and restores WordPress in an isolated local environment.

## Scope and destination

The bucket is `ceri-secure-s3-storage-test` in `ap-northeast-1`. Run `36d7990998df` uses `wordpress-test/recovery-integrity/36d7990998df/`, with separate `native/` and `php/` destinations. Existing backup locations and production installations are unchanged. No objects are deleted or overwritten.

The source and target use WordPress 7.0.2, PHP 8.3 and MariaDB 11.8 in dedicated Docker resources. The source database is removed before transfer. The S3 bridge runs over `ssh community` with PHP 8.1.2 and the existing host credential chain. Only synthetic backup artifacts and the locator are exported; source site configuration and database credentials are not transferred.

The bridge uploads artifacts captured by the local recovery pipeline and reconstructs their destination references. It uses the production `MediaS3Client`, `RecoveryCollector` and `RecoveryPublisher` for transport/publication, with a fixture evidence step for the already captured artifacts. This is not an uninterrupted production WordPress-to-S3 worker execution or a release-ZIP test.

## Verified behavior

- Conditional writes create DB, four media objects, inventory, completion marker and manifest: eight objects per backend.
- HEAD verifies artifact byte counts and full SHA-256 checksums. Manifest publication also compares GET bytes exactly.
- A simulated lost acknowledgement after a real manifest PUT is reconciled by a reconstructed collector, without a second PUT.
- A conflicting conditional PUT receives HTTP 412 and preserves the original manifest.
- Independent GETs verify the size and full SHA-256 of every object. Newly created download volumes feed restoration; there is no fallback to pre-transfer fixture objects.
- Native and PHP restoration each passed all 46 checks, including content, serialized data, user and attachment references, media hashes, and loopback HTTP delivery. Mail, outbound WordPress HTTP and Cron dispatch remain blocked. The complete runner exited successfully with `real_s3=1`.

Native manifest SHA-256: `7cf2e3c9553f4dfecb57cc92e6bd18c9e9e26ca348f269f5fb2feb1575ad7769`.

PHP manifest SHA-256: `a49bcafb76aa1ac68bbe55cf48f6cb52c1d17d1094f15769ff44cd04b0518d93`.

## IAM and encryption boundaries

The exercised identity permits bucket reachability, listing the dedicated prefix, conditional PUT, HEAD with checksum retrieval and GET. All 16 objects report `ServerSideEncryption: AES256`. Version IDs are absent in these responses; this does not establish the bucket's versioning configuration.

`GetBucketVersioning`, `GetBucketEncryption` and `GetPublicAccessBlock` return `AccessDenied`. No IAM or bucket policy was changed. Bucket-wide public-access controls, lifecycle policy, versioned retrieval and SSE-KMS permissions remain separate checks. This run does not certify the bucket configuration.

## Defect found and corrected

The first export attempt stopped before S3 because root-owned private fixture files could not be archived by the local user. Export now assigns those newly copied test files to the invoking UID/GID without widening their permissions.

The next attempt uploaded one DB object, then failed with `TypeError`: destruction of the SDK's PSR-7 upload wrapper closed the caller-owned resource before its owner called `fclose`. The adapter now retains and detaches its wrapper in `finally`. A network-free SDK regression test reproduces the former failure and verifies resource ownership, exact range content and unchanged source data on both success and exception paths.

The incomplete destination `wordpress-test/recovery-integrity/c5bf3fac0316/native/` contains one 9,090-byte DB object and no published recovery manifest. It is retained, not a usable recovery set. A fresh run ID was used for the retry.

## Evidence and reproduction

Native evidence is retained in WSL `/tmp/odbfs3-wp-s3.XN5lxHyr/output.tar` and on `community` at `/tmp/odbfs3-wp-s3.rysKlTmt/fixture/download/s3-report.json`. Reports contain object keys, checksums, publication receipts, encryption observations and permission outcomes, not credentials. Temporary paths may expire; S3 artifacts remain until explicitly cleaned up.

PHP evidence is retained in WSL `/tmp/odbfs3-wp-s3.wP2Cr0Ju/output.tar` and on `community` at `/tmp/odbfs3-wp-s3.Fprzu78u/fixture/download/s3-report.json`. Native artifacts total 14,637 bytes and PHP artifacts 14,053 bytes, excluding S3 metadata and retained local code archives. Manifest receipts were verified at 07:53:08 UTC and 07:54:42 UTC on 2026-10-17 respectively.

The network-free stream regression, media upload checks (60), recovery contract (127), collector (34), publication (39), and workspace (17) passed. ShellCheck and actionlint passed for the updated runner/bridge and workflow; remote CI has not run.

From Linux/WSL with the repository dependencies and local Docker images available:

```sh
php tests/manual/test-media-s3-stream.php
ODBFS3_REAL_S3=1 ODBFS3_ALLOW_SYNTHETIC_S3=1 bash tools/test-wordpress-recovery-local.sh
```

The opt-in command requires `ssh community` and authorization to upload synthetic artifacts to the named test bucket. Every invocation uses a new prefix. The default runner and CI remain offline with respect to S3. The runner cleans only Docker resources created by its own run; remote temporary directories and S3 objects are retained.

The AWS host was already at 96% disk utilization before this test and reached 97% during it. After verification, the newly transferred `code.tar`, `vendor`, `src` and `tools` copies were removed only from `/tmp/odbfs3-wp-s3.IQbVEseP`, `/tmp/odbfs3-wp-s3.rysKlTmt` and `/tmp/odbfs3-wp-s3.Fprzu78u`, after exact-path checks. Approximately 360 MiB of reproducible code copies were removed; fixture data, output archives, reports and S3 objects were retained. Broader capacity cleanup remains separate; do not delete unrelated directories to repeat this test.

No containers, volumes or networks carrying the dedicated test label remained after the successful runner cleanup.

Large-file multipart recovery against real S3, atomic DB/uploads capture, operational clone policy, production restore tooling and release packaging remain outside this acceptance run. No production deployment, commit, push or published plugin update is performed here.
