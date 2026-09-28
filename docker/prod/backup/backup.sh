#!/bin/sh
# Nightly backup of the database and the collection photos, run by the
# `backup` service in compose.prod.yml (postgres:17-alpine, so pg_dump
# always matches the server's major version).
#
# Once a day, at or after BACKUP_HOUR (UTC), it writes to /backups:
#   db-YYYY-MM-DD.dump     pg_dump custom format, checked with pg_restore --list
#   photos-YYYY-MM-DD.tar  the whole collection-photos volume (JPEG/PNG/WebP
#                          are already compressed, so it isn't gzipped)
# Each file is written under a hidden .partial name and renamed only once
# complete, so a half-written file never looks like a backup. The database
# dump is renamed last and doubles as the "today is done" marker. A failed
# or interrupted run leaves only .partial files, which the next attempt
# removes before it starts.
#
# Files are 0600 (they hold every member's email and password hash); the
# directory stays 0755 so the scheduler's backups:check-freshness can see
# their ages without reading them. A backup is deleted just before it is
# BACKUP_KEEP_DAYS days old; the privacy policy states that period.
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
    photos_tmp="$DIR/.photos-$day.tar.partial"

    rm -f "$DIR"/.*.partial
    pg_dump --format=custom --file="$db_tmp" || return 1
    pg_restore --list "$db_tmp" > /dev/null || return 1
    tar -C "$PHOTOS" -cf "$photos_tmp" . || return 1

    mv "$photos_tmp" "$DIR/photos-$day.tar" || return 1
    mv "$db_tmp" "$DIR/db-$day.dump" || return 1
}

# Runs on every pass, not only after a successful backup, so old files still
# go when backups are failing. The cutoff sits two hours short of KEEP_DAYS
# so that a pass landing just before the mark can't carry a file past it.
prune() {
    find "$DIR" -maxdepth 1 -type f \( -name 'db-*.dump' -o -name 'photos-*.tar' \) \
        -mmin +"$((KEEP_DAYS * 1440 - 120))" -delete
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
            log "wrote db-$day.dump and photos-$day.tar"
        else
            log "backup for $day failed; retrying in ${CHECK_EVERY_SECONDS}s"
            rm -f "$DIR"/.*.partial
        fi
    fi

    prune || log "pruning old backups failed"

    sleep "$CHECK_EVERY_SECONDS" &
    wait $!
done
