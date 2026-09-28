#!/bin/sh
# Nightly backup of the database and the collection photos, run by the
# `backup` service in compose.prod.yml (postgres:17-alpine, so pg_dump
# always matches the server's major version).
#
# Once a day, at or after BACKUP_HOUR (UTC), it writes to /backups:
#   db-YYYY-MM-DD.dump        pg_dump custom format, checked with pg_restore --list
#   photos-YYYY-MM-DD.tar.gz  the whole collection-photos volume
# Each file is written under a hidden .partial name and renamed only once
# complete, so a half-written file never looks like a backup. The database
# dump is renamed last and doubles as the "today is done" marker. A failed
# run is cleaned up and retried on the next pass.
#
# Files are 0600 (they hold every member's email and password hash); the
# directory stays 0755 so the scheduler's backups:check-freshness can see
# their ages without reading them. Files older than BACKUP_KEEP_DAYS are
# deleted.
#
# These copies live on the same host as the data: they cover a bad
# migration, a wrong delete or a corrupt volume, not losing the server.

set -u
umask 077

DIR=/backups
PHOTOS=/photos
HOUR=${BACKUP_HOUR:-3}
KEEP_DAYS=${BACKUP_KEEP_DAYS:-14}
CHECK_EVERY_SECONDS=600

log() { echo "backup: $*" >&2; }

backup() {
    day=$1
    db_tmp="$DIR/.db-$day.dump.partial"
    photos_tmp="$DIR/.photos-$day.tar.gz.partial"

    pg_dump --format=custom --file="$db_tmp" || return 1
    pg_restore --list "$db_tmp" > /dev/null || return 1
    tar -C "$PHOTOS" -czf "$photos_tmp" . || return 1

    mv "$photos_tmp" "$DIR/photos-$day.tar.gz" || return 1
    mv "$db_tmp" "$DIR/db-$day.dump" || return 1
}

prune() {
    find "$DIR" -maxdepth 1 -type f \( -name 'db-*.dump' -o -name 'photos-*.tar.gz' \) \
        -mtime +"$KEEP_DAYS" -delete
}

mkdir -p "$DIR" && chmod 755 "$DIR" || exit 1

# The shell is PID 1, which ignores SIGTERM unless trapped; sleeping in the
# background and waiting on it lets `docker stop` end the loop at once.
trap 'exit 0' TERM INT

while :; do
    day=$(date -u +%F)
    hour=$(date -u +%H)
    hour=${hour#0}

    if [ ! -f "$DIR/db-$day.dump" ] && [ "$hour" -ge "$HOUR" ]; then
        if backup "$day"; then
            log "wrote db-$day.dump and photos-$day.tar.gz"
            prune || log "pruning old backups failed"
        else
            log "backup for $day failed; retrying in ${CHECK_EVERY_SECONDS}s"
            rm -f "$DIR/.db-$day.dump.partial" "$DIR/.photos-$day.tar.gz.partial"
        fi
    fi

    sleep "$CHECK_EVERY_SECONDS" &
    wait $!
done
