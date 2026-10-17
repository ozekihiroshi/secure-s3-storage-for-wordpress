# WordPress recovery from a manifest

The synthetic WordPress recovery fixture restores a working site from the published recovery manifest, with its source database unavailable. Local tests use WordPress 7.0.2, PHP 8.3, MariaDB 11.8 and disk-backed mock S3. This verifies the database/uploads recovery set, not a self-contained server backup or a production restore command.

## Verified scope

Both native and PHP dump backends passed 46 restore assertions each. The real WordPress job-store integration passed 22 assertions in each source environment; collection and manifest publication also completed through `WordPressJobStore::forRecovery`, rather than a memory-only parent store.

The final runner completed both backends successfully. No resources with the test's `odbfs3.test=wordpress-recovery` label remained after cleanup.

The restore assertions cover:

- Published pages and posts, Japanese text and emoji, saved block content, nested serialized options and post metadata, and front-page settings.
- User password hashes, serialized roles, post authors, taxonomy and comment references.
- Attachment IDs, parent and featured-image references, alternative text, image metadata and generated image variants.
- All four uploaded files, including a Japanese filename and an empty file, compared against the inventory and full-file SHA-256 values.
- Actual HTTP delivery of the restored front page and image from a loopback-only PHP server. This is an automated rendering/content check, not visual browser approval.
- A due scheduled fixture job retained without dispatch, blocked WordPress HTTP calls, and blocked mail.

Corrupt DB, inventory, marker and media objects, a missing manifest, unsafe relative paths and a nonempty staging destination are rejected before SQL import or placement of uploads. The target database and uploads remain empty after these negative preflights.

## Isolation and restoration sequence

The runner creates dedicated internal Docker networks, unexposed MariaDB containers and new site/private/object volumes. It uses synthetic accounts and data only. The source DB container and network are removed before the target DB starts. The target receives neither the source site volume, source private workspace nor source DB credentials. Remote fixture objects are mounted read-only during restoration.

The locator supplies the manifest bucket/key, byte count and trusted expected SHA-256. The staging helper validates the downloaded manifest, obtains DB/inventory/marker locations from it, verifies their bytes, fully validates the inventory, and verifies every media file before import. It then imports into an empty, separately credentialed database and boots WordPress with the table prefix from the manifest. Test expectations are used for assertions, not to choose backup component keys.

WordPress core and its bundled theme are copied separately from the local WordPress image. The target configuration and test-only safety MU plugin are also supplied separately. Custom plugin compatibility is not covered. Original site URLs are preserved using the fixture host name; domain migration/search-and-replace is not tested.

A `RecoveryObservation` with `kind: restore`, `state: passed`, the exact manifest SHA-256 and the four checks `sql_import`, `wordpress_content`, `media_hashes` and `attachment_references` is written in the target's private test volume. This volume is ephemeral, not a persistent production recovery catalog. Console output records each backend's result, assertion count, WordPress version and manifest digest.

## Reproduce

Run from the repository in Linux/WSL with Composer dependencies installed and local images `wordpress:php8.3-apache` and `mariadb:11.8` available:

```sh
bash tools/test-wordpress-recovery-local.sh
```

The runner does not pull images or touch existing containers. It needs one MariaDB container limited to 512 MiB and one WordPress worker limited to 768 MiB at a time. It creates no host-facing port. Cleanup targets only resources created by this run.

The default internal subnet is `10.203.104.0/28`, checked against this host's Docker networks and routes for the local run. On another host, verify that it is unused or set `ODBFS3_FIXTURE_SUBNET` to an unused subnet. Existing networks must not be pruned to make room. `ODBFS3_WP_IMAGE` can select a separately prepared WordPress image; the actual core version is printed by the fixture.

The worker image must contain `mysqldump` or `mariadb-dump` for the native branch. The runner checks this prerequisite before creating fixture state. CI explicitly builds `tests/manual/wordpress-recovery.Dockerfile` with `mariadb-client`; installing the client only on the host is insufficient. To use the same setup locally, build it with `docker build -f tests/manual/wordpress-recovery.Dockerfile -t odbfs3-wordpress-recovery:local .` and set `ODBFS3_WP_IMAGE=odbfs3-wordpress-recovery:local` when running the fixture.

The CI definition is `.github/workflows/wordpress-recovery-tests.yml`. PHP lint, Bash syntax, ShellCheck and actionlint passed locally. Remote CI still requires a commit/push.

## Boundaries before release

The package staging helper is test-only and deliberately limited to small, trusted synthetic fixtures. It is not a safe general-purpose importer for arbitrary SQL, a production media restore workflow, or a one-click restore feature. There is no new production admin/CLI/Cron entry point. Restored operational job records require a separate policy before enabling automatic workers on a cloned site.

Real-S3 transfer and restoration of the synthetic fixture passed for both backends on 2026-10-17; see [the S3 verification record](aws-recovery-set-verification-2026-10-17.md) for destinations, checksums and reproduction instructions. Object AES256 encryption and the required transfer operations were verified; bucket configuration reads were denied. Versioning/lifecycle behavior, SSE-KMS, large-site restore capacity and a release ZIP remain separate acceptance work. The earlier interruption tests cover multipart media and process loss; this WordPress fixture covers small files and application-level references. Neither test proves atomic DB/uploads capture or power-loss durability.

Production installations and published version 0.2.1 are unchanged; no commits or pushes were made. AWS changes are limited to new synthetic objects in the dedicated S3 test prefix and temporary test files on the bridge host.
