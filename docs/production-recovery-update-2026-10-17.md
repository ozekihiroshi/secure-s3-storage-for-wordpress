# Recovery foundation production update

On 2026-10-17, wp.ceri.link was updated from Ozeki Database Backup for S3 0.2.1 to 0.3.0. The existing active-plugin list, settings, backup history, standalone media job state and plugin Cron entries matched before and after the update. The company WordPress installation was not changed.

## Applied package and validation

The final dependency-scoped package is `build/ozeki-database-backup-for-s3-0.3.0.zip`, SHA-256 `9a9853b84397e579201c88c25e075e2f4805958ff43d6d4925ce7b35e28a0508`, built from runtime commit `d9406fac340ef1e4400af67364f165fbfd5f1587`. Build syntax, dependency isolation and scoped-release collision checks passed. Installed files match this extracted ZIP by recursive directory comparison.

The homepage and login page returned HTTP 200 after deployment, and the maintenance marker was absent. Disk utilization is 97% with approximately 3.6 GiB available; capacity maintenance remains necessary.

The initial ZIP (`da920874b17217c430efe02d671c2d22550715b9991d08b3d38a152b2e94a7e2`) passed synthetic WordPress recovery with native and PHP dump backends: 40 source checks, 22 WordPress job-store checks and 46 restore checks per backend. Its packaged S3 adapter passed caller-owned stream tests on success and exception paths. Source tests also passed database integrity (46), media upload (60), SDK behavior (11), recovery publication (39), collector (34), contract (127), workspace (17) and job runner (49).

CI first identified a missing native dump client in its WordPress worker and Plugin Check findings about direct filesystem operations and list-function compatibility. The test image now explicitly installs `mariadb-client`. The final runtime change documents why private POSIX operations require direct streams, locking and atomic replacement, and uses the existing `array_values($value) === $value` list check. Contract/publication/workspace tests passed again. All seven workflows passed for `d9406fa`, including Plugin Check, database integrity and isolated WordPress recovery. The final rebuilt ZIP was then reapplied and compared against production; the full local exact-ZIP restore run refers to the initial artifact, not this rebuilt archive.

This ZIP test uses local simulated S3. The preceding source-tree test used real S3 as described in [the real S3 verification record](aws-recovery-set-verification-2026-10-17.md). It is not an exact-ZIP real-S3 test or a comprehensive Plugin Check result. No production backup was manually sent to S3, and no production database restore was performed during deployment.

Version 0.3.0 includes the internal recovery-set implementation but does not add an admin, CLI or Cron orchestration entry point. Existing backups remain standalone. No recovery workspace or new scheduled job was enabled in production.

## Backups and rollback

Private host backup directory: `/home/ubuntu/docker/wp-rescue/backups/odbfs3-030-17c030a4b815/` (0700). It contains `before.sql`, `options.serialized`, `old-plugin.tar.gz`, the candidate ZIP and comparison artifacts. Database and option snapshots are private and must not be committed or displayed.

The DB snapshot is 375,966 bytes, SHA-256 `643b4b48598c336cb8b10922b3b98ff9238c5891bb1a3bf16cc1ccc82cef6632`. The selected state serialization hash before and after is `1389d2daf0fe8ef688075f74b6844e903c528e7aa1c85ab2c5c7e757ec449da6`.

The target container is `wp-rescue`. Previous plugin code is retained at `/tmp/odbfs3-update-17c030a4b815/previous` inside that container. The persistent host archive remains the rollback source if container-local temporary files disappear. A short maintenance window covered directory replacement. Neither deactivation nor uninstall hooks ran. Verification confirmed version 0.3.0 and recovery-publisher autoloading.

The final correction has a separate private backup directory, `/home/ubuntu/docker/wp-rescue/backups/odbfs3-030-17c030b4b815/`, and container workspace `/tmp/odbfs3-update-17c030b4b815/`. Its `before.sql` is 375,966 bytes with SHA-256 `5734c2718766c4c3cf242a27f0488ad0b18048d98f221a5d1c8d8fd509921a30`; the selected state hash remains identical to the first snapshot. The `previous` code in this second workspace is the initial 0.3.0 build. Use the first backup's archive when specifically rolling back to 0.2.1.

To roll back, first preserve any later state, briefly enter maintenance, and replace only the plugin code with the saved 0.2.1 archive. Do not restore the whole database for a code rollback: doing so would discard later site changes. Do not activate the legacy `secure-s3-storage` directory alongside this plugin.

## Publication boundary

This deployment does not create a GitHub Release or publish to WordPress.org SVN. The directory release remains 0.2.1. Operator workflow, cleanup policy, bucket configuration audit, SSE-KMS and large-site real-S3 acceptance remain separate work.
