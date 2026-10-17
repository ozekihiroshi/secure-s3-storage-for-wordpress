# Ozeki Database Backup for S3 0.2.1

Release notes prepared for publication. This version strengthens database backup
integrity checks; it does not add a one-click restore feature.

## Changes

- Verify gzip expansion against the original SQL size and SHA-256.
- Send a SHA-256 checksum to S3 and independently check the stored object's size
  and checksum before reporting success.
- Use unique database backup keys and conditional writes to prevent overwrites.
- Record database integrity evidence in new history entries. Older entries
  without evidence remain unverified.
- Add bounded download and expansion helpers and isolated recovery tests for
  native and PHP dump backends.

## Before upgrading

Confirm that the WordPress server identity can read object metadata and checksums
as well as upload backups. Check the additional encryption permissions when
using KMS. The real-S3 tests for this change used SSE-S3, not SSE-KMS.

A verification failure is reported as a backup failure and prevents the
success-dependent retention step. An object uploaded before verification failed
may remain in S3 for inspection.

## Recovery scope

Integrity verification is not a restore test of every backup. Synthetic fixtures
passed real-S3 download, decompression, and isolated import tests, but database
and media backups remain independent. Full-site recovery still needs other site
files and configuration, and an independently tested recovery procedure.

See [verification details](database-integrity-verification.md) and
[real-S3 test results](aws-database-integrity-test-2026-10-17.md).
