#!/usr/bin/env bash
set -euo pipefail
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[[ $# == 1 && -f "$1" ]]
zip=$(realpath "$1")
work=$(mktemp -d /tmp/odbfs3-candidate.XXXXXXXX)
# Copy the archive to Linux first; avoid repeated cross-filesystem ZIP reads.
cp "$zip" "$work/candidate.zip"
unzip -q "$work/candidate.zip" -d "$work"
runtime="$work/ozeki-database-backup-for-s3"
test -f "$runtime/vendor/autoload.php"
cp -a "$repo/tests" "$repo/tools" "$runtime/"
echo "Candidate runtime: $runtime"
sha256sum "$work/candidate.zip"
bash "$runtime/tools/test-wordpress-recovery-local.sh"
