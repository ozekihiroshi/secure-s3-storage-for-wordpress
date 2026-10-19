#!/usr/bin/env bash
set -euo pipefail
# Called only after the synthetic source has stopped. No site config or credentials exported.
[[ "${ODBFS3_ALLOW_SYNTHETIC_S3:-0}" == 1 && $# == 4 ]]
remote_volume=$1 expected_volume=$2 backend=$3 run=$4
[[ "$run" =~ ^[a-f0-9]{12}$ && "$backend" =~ ^(native|php)$ ]]
[[ "$remote_volume" == "odbfs3-wp-$run-$backend-objects" && "$expected_volume" == "odbfs3-wp-$run-$backend-expectations" ]]
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
local_work=$(mktemp -d /tmp/odbfs3-wp-s3.XXXXXXXX)
chmod 700 "$local_work"
docker run --rm --pull=never --network none -v "$remote_volume:/objects:ro" -v "$expected_volume:/expected:ro" \
    -v "$local_work:/export" -e FIXTURE_OWNER="$(id -u):$(id -g)" --entrypoint sh wordpress:php8.3-apache \
    -c 'mkdir /export/objects; cp -a /objects/. /export/objects/; cp /expected/locator.json /export/locator.json; chown -R "$FIXTURE_OWNER" /export/objects /export/locator.json'
remote_work=$(ssh -o BatchMode=yes -o ConnectTimeout=10 community 'mktemp -d /tmp/odbfs3-wp-s3.XXXXXXXX')
[[ "$remote_work" =~ ^/tmp/odbfs3-wp-s3\.[A-Za-z0-9]{8}$ ]]
(cd "$repo"; find src vendor -type f -print0 | sort -z | xargs -0 sha256sum) > "$local_work/runtime-sha256.txt"
tar -C "$repo" -cf "$local_work/code.tar" src vendor tools/test-recovery-s3-roundtrip.php
tar -rf "$local_work/code.tar" -C "$local_work" runtime-sha256.txt
tar -C "$local_work" -cf "$local_work/input.tar" objects locator.json
scp -q "$local_work/code.tar" "$local_work/input.tar" "community:$remote_work/"
ssh -o BatchMode=yes community "cd '$remote_work' && tar -xf code.tar && sha256sum --quiet -c runtime-sha256.txt && mkdir fixture && tar -xf input.tar -C fixture && php tools/test-recovery-s3-roundtrip.php '$remote_work/fixture' '$run' '$backend' && sha256sum --quiet -c runtime-sha256.txt"
scp -q "community:$remote_work/output.tar" "$local_work/output.tar"
# Use NEW volumes, never overwrite the pre-transfer fixture or use it as a fallback.
download_volume="odbfs3-wp-$run-$backend-s3-download"
download_expected="odbfs3-wp-$run-$backend-s3-expectations"
docker volume create --label odbfs3.test=wordpress-recovery "$download_volume" >/dev/null
docker volume create --label odbfs3.test=wordpress-recovery "$download_expected" >/dev/null
docker run --rm --pull=never --network none -v "$download_volume:/download" -v "$download_expected:/expectations" \
    -v "$expected_volume:/original:ro" -v "$local_work:/input:ro" --entrypoint sh wordpress:php8.3-apache \
    -c 'tar -xf /input/output.tar -C /download; cp /original/content.json /expectations/content.json; cp /download/locator.json /expectations/locator.json; cp /download/collector.json /expectations/collector.json'
echo "S3 evidence retained: $local_work and community:$remote_work"
