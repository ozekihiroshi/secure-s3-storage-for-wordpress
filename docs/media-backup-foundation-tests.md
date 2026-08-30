# Background-job foundation verification

Date: 2026-08-31. This covers implementation slice 1 only, not media backup readiness.

## Results

| Check | Result |
| --- | --- |
| Standalone job tests, PHP 8.1 container, network disabled | 49 checks passed |
| Standalone job tests, PHP 8.3 container, network disabled | 49 checks passed |
| WordPress option-store integration, isolated 8082 DB temporary table | 16 checks passed |
| New runtime PHP syntax, local PHP 8.3 | Passed |
| PluginCheck PHPCS standard, new runtime files only | No findings |
| SQL preparation/direct-query and exception-output sniffs | No findings |
| New GitHub workflow, actionlint 1.7.12 | Passed |
| Existing complete-stream-writer test | Passed |
| Existing secure-temporary-file test | Passed |

The competing-worker tests deterministically interleave workers; they are not
multi-process load tests. SQL integration verifies exact-value CAS, including
case and trailing-space comparisons, against the real WordPress/MariaDB schema.
It uses a separate DB connection and a session-local temporary table. Object
cache invalidations are intercepted in the test; distributed-cache behavior
and multi-host execution remain to be tested before enabling dispatch.

## Re-run

No dependencies or WordPress are needed for the standalone test:

```sh
php tests/manual/test-backup-job-runner.php
```

For Docker integration, first verify that the target is the isolated 8082
container. Copy the new `src/Backup/Job/`, `src/WordPress/WordPressJobStore.php`,
integration test and bootstrap into a fresh temporary directory, preserving
the repository-relative layout. Make that code directory readable by www-data.
Run PHP as www-data with `-d auto_prepend_file=<temporary-directory>/tests/manual/wordpress-job-test-bootstrap.php`
and `<temporary-directory>/tests/manual/test-wordpress-job-store.php` as its script.
The bootstrap disables WP-Cron dispatch. Never run these fixture tests on production.

No real settings rows are used as test fixtures. The temporary table disappears
when its connection closes. The test bootstrap and all tests are excluded from
release ZIPs by the existing build script.

## Not yet verified / not enabled

- Scheduler registration, browser-independent dispatch, progress UI, history
  archiving and retirement of completed job slots.
- Media enumeration, S3 transfers, multipart recovery/cleanup, manifests,
  concurrent file modifications, restored file checksums and media references.
- Lifecycle handling of job metadata and scheduled events; independent retention.
- Actual process-kill recovery and multi-process/multi-host concurrency.
- Full Plugin Check of a rebuilt release ZIP and GitHub Actions execution for
  these changes. No commit, push, release rebuild or publication was performed.
