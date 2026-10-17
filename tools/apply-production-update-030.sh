#!/usr/bin/env bash
# One explicitly authorized wp.ceri.link deployment; execute on community only.
set -euo pipefail
backup=/home/ubuntu/docker/wp-rescue/backups/odbfs3-030-17c030a4b815
work=/tmp/odbfs3-update-17c030a4b815
test "$(realpath "$backup")" = "$backup"
test -s "$backup/before.sql"
test -s "$backup/options.serialized"
test -s "$backup/old-plugin.tar.gz"
echo 'da920874b17217c430efe02d671c2d22550715b9991d08b3d38a152b2e94a7e2  candidate.zip' | (cd "$backup" && sha256sum -c -)
mkdir "$backup/candidate"
unzip -q "$backup/candidate.zip" -d "$backup/candidate"
docker cp "$backup/candidate/ozeki-database-backup-for-s3" "wp-rescue:$work/candidate"
docker exec wp-rescue chown -R www-data:www-data "$work/candidate"
# Reject an unrelated maintenance window and never deactivate/uninstall the plugin.
docker exec wp-rescue test ! -e /var/www/html/.maintenance
docker exec wp-rescue test ! -e "$work/previous"
maintenance=0
swapped=0
finish() {
    result=$?
    if (( result != 0 && swapped == 1 )); then
        if docker exec wp-rescue test -d /var/www/html/wp-content/plugins/ozeki-database-backup-for-s3; then
            docker exec wp-rescue mv /var/www/html/wp-content/plugins/ozeki-database-backup-for-s3 "$work/failed-candidate"
        fi
        docker exec wp-rescue mv "$work/previous" /var/www/html/wp-content/plugins/ozeki-database-backup-for-s3
        echo 'Code rolled back; database was not restored.' >&2
    fi
    if (( maintenance == 1 )); then docker exec wp-rescue rm /var/www/html/.maintenance; fi
    exit "$result"
}
trap finish EXIT
docker exec wp-rescue php -r 'if (!file_put_contents("/var/www/html/.maintenance", "<?php \$upgrading = " . time() . ";\n")) { exit(1); }'
maintenance=1
sleep 5
docker exec wp-rescue mv /var/www/html/wp-content/plugins/ozeki-database-backup-for-s3 "$work/previous"
swapped=1
docker exec wp-rescue mv "$work/candidate" /var/www/html/wp-content/plugins/ozeki-database-backup-for-s3
docker exec wp-rescue rm /var/www/html/.maintenance
maintenance=0
docker exec -e ODBFS3_UPDATE_WORK="$work" wp-rescue php "$work/check.php" verify
docker exec wp-rescue tar -cf "$work/installed.tar" -C /var/www/html/wp-content/plugins ozeki-database-backup-for-s3
docker cp "wp-rescue:$work/installed.tar" "$backup/installed.tar"
mkdir "$backup/installed"
tar -xf "$backup/installed.tar" -C "$backup/installed"
diff -qr "$backup/candidate/ozeki-database-backup-for-s3" "$backup/installed/ozeki-database-backup-for-s3"
echo 'Production code and settings verified; prior code retained for rollback.'
