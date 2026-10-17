# Ozeki Backup Core 0.1.0

Canonical source: packages/backup-core in ozeki-wordpress-resilience. This first extraction contains PHP MySQL dumping and private temporary-file / complete-write primitives. S3, compression, scheduling and WordPress UI are not extracted yet.

Require autoload.php and use Ozeki\BackupCore\V0_1_0\Database\Php\PhpMySqlDumper. PHP >=8.1 with mysqli is required. No WordPress bootstrap or companion plugin is loaded. Successful dump ownership passes to the caller: consume and unlink it in finally. Failures remove the incomplete file. Exceptions may chain driver errors; do not log credentials or full exception chains.

Namespace includes the component version to avoid accidental cross-version selection. A product bundles a verified copy; users do not install a separate plugin. Updates are generated from the canonical source using tools/vendor-backup-core.py. Never patch the generated copy by hand. GPL-2.0-or-later; provenance in UPSTREAM.json.
