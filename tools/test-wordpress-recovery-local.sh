#!/usr/bin/env bash
set -euo pipefail

# Synthetic data only. Each restore runs after its source DB container is destroyed.
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
wp_image="${ODBFS3_WP_IMAGE:-wordpress:php8.3-apache}"
db_image=mariadb:11.8
docker image inspect "$wp_image" "$db_image" >/dev/null
docker run --rm --pull=never --network none --entrypoint sh "$wp_image" \
    -c 'command -v mysqldump >/dev/null || command -v mariadb-dump >/dev/null' || {
    echo 'The fixture worker needs mariadb-client; build tests/manual/wordpress-recovery.Dockerfile.' >&2
    exit 1
}
run_id="$(php -r 'echo bin2hex(random_bytes(6));')"
if [[ "${ODBFS3_REAL_S3:-0}" == 1 && "${ODBFS3_ALLOW_SYNTHETIC_S3:-0}" != 1 ]]; then
    echo 'Explicit synthetic S3 transfer authorization is required.' >&2; exit 1
fi
containers=() networks=() volumes=()
cleanup() {
    for id in "${containers[@]}"; do docker rm -f "$id" >/dev/null 2>&1 || true; done
    for id in "${networks[@]}"; do docker network rm "$id" >/dev/null 2>&1 || true; done
    for id in "${volumes[@]}"; do docker volume rm "$id" >/dev/null 2>&1 || true; done
}
trap cleanup EXIT
new_volume() {
    volume="odbfs3-wp-${run_id}-$1"
    docker volume create --label odbfs3.test=wordpress-recovery "$volume" >/dev/null
    volumes+=("$volume")
}
for backend in native php; do
    new_volume "$backend-objects"; remote=$volume
    new_volume "$backend-expectations"; expected=$volume
    for stage in source restore; do
        network=$(docker network create --internal --subnet "${ODBFS3_FIXTURE_SUBNET:-10.203.104.0/28}" --label odbfs3.test=wordpress-recovery "odbfs3-wp-${run_id}-${backend}-${stage}")
        networks+=("$network")
        [[ "$(docker network inspect --format '{{.Internal}}' "$network")" == true ]]
        new_volume "$backend-$stage-site"; site=$volume
        new_volume "$backend-$stage-private"; private=$volume
        password="$(php -r 'echo bin2hex(random_bytes(24));')"
        db=$(docker run -d --rm --pull=never --network "$network" --network-alias fixture-db \
            --label odbfs3.test=wordpress-recovery --memory=512m --cpus=1 \
            --tmpfs /var/lib/mysql --tmpfs /run/mysqld \
            -e MARIADB_RANDOM_ROOT_PASSWORD=1 -e MARIADB_DATABASE=fixture \
            -e MARIADB_USER=fixture -e MARIADB_PASSWORD="$password" "$db_image")
        containers+=("$db")
        ready=false
        for _attempt in {1..60}; do
            if docker exec -e MYSQL_PWD="$password" "$db" mariadb -h127.0.0.1 -ufixture fixture -e 'SELECT 1' >/dev/null 2>&1; then ready=true; break; fi
            sleep 1
        done
        [[ "$ready" == true ]]
        access=rw
        [[ "$stage" != restore ]] || access=ro
        worker="odbfs3-wp-${run_id}-${backend}-${stage}-worker"
        containers+=("$worker")
        docker run --rm --pull=never --name "$worker" --network "$network" \
            --label odbfs3.test=wordpress-recovery --memory=768m --cpus=1 \
            -e ODBFS3_WP_FIXTURE=1 -e FIXTURE_PASSWORD="$password" -e FIXTURE_BACKEND="$backend" \
            -e ODBFS3_EXECUTION_RECORD_TEST=1 \
            -v "$repo:/repo:ro" -v "$site:/site" -v "$private:/private" \
            -v "$remote:/remote:$access" -v "$expected:/expected:$access" \
            --entrypoint sh "$wp_image" -c \
            'cp -a /usr/src/wordpress/. /site/; exec php /repo/tests/manual/test-wordpress-recovery.php "$1"' -- "$stage"
        # Source is genuinely unavailable before restore; no credentials/volumes shared.
        docker rm -f "$db" >/dev/null
        docker network rm "$network" >/dev/null
        if [[ "$stage" == source && "${ODBFS3_REAL_S3:-0}" == 1 ]]; then
            volumes+=("odbfs3-wp-$run_id-$backend-s3-download" "odbfs3-wp-$run_id-$backend-s3-expectations")
            bash "$repo/tools/test-wordpress-recovery-s3-bridge.sh" "$remote" "$expected" "$backend" "$run_id"
            remote="odbfs3-wp-$run_id-$backend-s3-download"
            expected="odbfs3-wp-$run_id-$backend-s3-expectations"
        fi
    done
done
echo "PASS isolated WordPress recovery (native + php; internal networks; real_s3=${ODBFS3_REAL_S3:-0}; source removed)"
