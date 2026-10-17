#!/usr/bin/env bash
set -euo pipefail

# Disposable MariaDB only. Never connects to an existing container/database or AWS.
root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
image="mariadb:11.8"
docker image inspect "$image" >/dev/null
run_id="$(php -r 'echo bin2hex(random_bytes(6));')"
container_name="odbfs3-recovery-${run_id}"
db_password="$(php -r 'echo bin2hex(random_bytes(24));')"
container_id=""
cleanup() {
    if [[ -n "$container_id" ]]; then
        docker rm -f "$container_id" >/dev/null
    fi
}
trap cleanup EXIT
container_id="$(docker run -d --rm --pull=never --name "$container_name" \
    --label odbfs3.test=database-recovery \
    --memory=512m --cpus=1 \
    --tmpfs /var/lib/mysql --tmpfs /run/mysqld \
    --publish 127.0.0.1::3306 \
    -e MARIADB_ROOT_PASSWORD="$db_password" -e MARIADB_ROOT_HOST=% "$image")"
ready=false
for attempt in {1..60}; do
    if docker exec -e MYSQL_PWD="$db_password" "$container_id" mariadb -h127.0.0.1 -uroot -e 'SELECT 1' >/dev/null 2>&1; then
        ready=true
        break
    fi
    sleep 1
done
if [[ "$ready" != true ]]; then
    echo 'Disposable database did not become ready.' >&2
    exit 1
fi
db_port="$(docker port "$container_id" 3306/tcp)"
[[ "$db_port" =~ ^127\.0\.0\.1:([0-9]+)$ ]]
db_port="${BASH_REMATCH[1]}"
cd "$root_dir"
for backend in native php; do
    suffix="$(php -r 'echo bin2hex(random_bytes(6));')"
    source_db="secure_s3_restore_source_${suffix}"
    restore_db="secure_s3_restore_test_${suffix}"
    docker exec -e MYSQL_PWD="$db_password" "$container_id" mariadb -h127.0.0.1 -uroot \
        -e "CREATE DATABASE ${source_db} CHARACTER SET utf8mb4; CREATE DATABASE ${restore_db} CHARACTER SET utf8mb4;"
    WORDPRESS_DB_HOST="127.0.0.1:${db_port}" WORDPRESS_DB_NAME="$source_db" \
        WORDPRESS_DB_USER=root WORDPRESS_DB_PASSWORD="$db_password" \
        RESTORE_TEST_DB_NAME="$restore_db" RESTORE_TEST_BACKEND="$backend" \
        RESTORE_TEST_FIXTURE=1 RESTORE_TEST_TRANSPORT=offline-s3 \
        php tests/manual/test-database-restore.php
    WORDPRESS_DB_HOST="127.0.0.1:${db_port}" WORDPRESS_DB_NAME="$source_db" \
        WORDPRESS_DB_USER=root WORDPRESS_DB_PASSWORD="$db_password" \
        RESTORE_TEST_DB_NAME="$restore_db" RESTORE_TEST_BACKEND="$backend" \
        RESTORE_TEST_FIXTURE=1 \
        php tests/manual/test-recovery-pipeline.php
done
echo 'PASS isolated database recovery (native + php; offline S3 transport)'
