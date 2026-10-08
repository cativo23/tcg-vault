# tcg-vault — Deploy to polaris2

Single-origin Docker deploy at **https://tcgvault.cativo.dev**. Automated
via GitHub Actions: CI on every push/PR, then a `release/vX.Y.Z` branch
merge to `master` builds/pushes the image and deploys. Manual steps below
still apply for the one-time server setup and for anything the workflows
deliberately don't automate (migrations, first-time secrets).

## Cutting a release
```bash
git checkout -b release/v0.2.0 master
# update CHANGELOG.md: move the "## [Unreleased]" entries under "## [0.2.0]"
git commit -am "chore(release): v0.2.0"
git push -u origin release/v0.2.0
gh pr create --base master --title "release: v0.2.0" --body "See CHANGELOG.md"
# merging that PR:
#   1. auto-release.yml creates GitHub Release v0.2.0 from the CHANGELOG section
#   2. deploy.yml builds+pushes cativo23/tcg-vault:v0.2.0 / :latest, then
#      SSHes into polaris2 and runs `docker compose pull && up -d`
# migrations are NEVER run automatically — see the manual step below if
# this release includes any.
```

## Required GitHub Actions secrets/variables (repo settings → Secrets and variables)
| Name | Type | Purpose |
|---|---|---|
| `RELEASE_PAT` | secret | Creates the GitHub Release in `auto-release.yml`. Must be a PAT (classic `repo` scope, or fine-grained `contents: write`), **not** `GITHUB_TOKEN` — releases created by the default token don't trigger `deploy.yml`'s `release: published` event (GitHub's recursion guard), so using it here would create a Release that never deploys, with no error anywhere. |
| `DOCKER_USERNAME` | variable | Docker Hub login + image namespace — not secret, kept as a variable so it isn't masked as `***` in logs |
| `DOCKER_PASSWORD` | secret | Docker Hub access token |
| `SSH_USERNAME` | secret | polaris2 SSH user |
| `SSH_PRIVATE_KEY` | secret | polaris2 SSH private key |
| `DEPLOY_HOST` | variable | polaris2 hostname/IP |
| `DEPLOY_PORT` | variable | polaris2 SSH port |

The `prod` environment gates `deploy.yml`'s two jobs — create a `prod`
environment in repo settings (with these secrets/vars scoped to it, or
inherited from repo-level) if you want an extra manual-approval gate before
the deploy job runs.

The app's own secrets (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`,
`RESEND_KEY`) are **not** GitHub secrets — they stay in
`~/deploy/tcg-vault/.env` on the server itself (one-time setup below) and
are never touched by the deploy workflow, which only re-syncs
`compose.prod.yml` and re-pulls the image.

## One-time server setup
```bash
ssh polaris2
mkdir -p ~/deploy/tcg-vault
docker network ls | grep space-server_web   # confirm the shared Traefik network exists
```
Copy `docker/prod/compose.prod.yml` → `~/deploy/tcg-vault/compose.prod.yml`,
`docker/prod/backup/backup.sh` → `~/deploy/tcg-vault/backup/backup.sh` and
`docker/prod/.env.production.example` → `~/deploy/tcg-vault/.env`, then fill real secrets:
```bash
# locally, generate an app key:
php artisan key:generate --show            # copy the base64:... value into .env APP_KEY
# on the server:
chmod 600 ~/deploy/tcg-vault/.env
```
Required `.env` values to fill: `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `RESEND_KEY`,
and the key in `SENTRY_LARAVEL_DSN` (from the `tcg-vault` project's settings in the
Bugsink dashboard at `errors.cativo.dev` — VPN required)
(the rest are pre-filled in the template).

Optional: `DISCORD_ALERT_WEBHOOK_URL` — a Discord webhook URL for
operational alerts (see `App\Support\DiscordAlerter`). Left unset,
alerting is silently off rather than erroring; nothing else depends on
it. Not the same webhook as `alertmanager-discord`'s — that one is
host-level infra alerting (CPU, disk), this one is app-level (a stalled
daily job).

## Build & push (local, repo root)
```bash
docker login                                # Docker Hub, user cativo23
docker build -f docker/prod/Dockerfile -t cativo23/tcg-vault:latest .
docker push cativo23/tcg-vault:latest
```

## Deploy / redeploy (server)

**One-time step for the release that adds `--appendonly yes` to Redis's
command — run it before merging that release branch into `master`, not
after.** `deploy.yml` triggers fully automatically on `release: published`
with no manual approval gate by default, so once the release branch is
merged there is no reliable point left to intervene before `up -d`
recreates the container. Enabling AOF via the command line does *not*
fall back to loading the existing `dump.rdb` when no `appendonlydir` is
present yet — Redis just starts empty, silently dropping whatever the
running instance still holds. Run this against the *currently running*
`redis` container first:
```bash
ssh polaris2
cd ~/deploy/tcg-vault
REDIS_PASSWORD=$(grep '^REDIS_PASSWORD=' .env | cut -d= -f2-)
docker compose -f compose.prod.yml exec redis redis-cli -a "$REDIS_PASSWORD" CONFIG SET appendonly yes
# Confirm the rewrite finished before merging — mid-rewrite, Redis refuses
# to shut down cleanly (Docker then force-kills it on its stop grace period):
docker compose -f compose.prod.yml exec redis redis-cli -a "$REDIS_PASSWORD" INFO persistence | grep -E "aof_rewrite_in_progress|aof_last_bgrewrite_status"
# expect aof_rewrite_in_progress:0 and aof_last_bgrewrite_status:ok
```

```bash
ssh polaris2
cd ~/deploy/tcg-vault
docker compose -f compose.prod.yml pull
docker compose -f compose.prod.yml up -d
# Migrations are a deliberate manual step (never auto-run):
docker compose -f compose.prod.yml exec app php artisan migrate --force
# Every deploy, not just the first: idempotent (firstOrCreate/
# assignRole no-op if already done), and now also the only thing that
# backfills roles/permissions onto accounts created before a given
# deploy — skipping this after the roles/permissions/invites migration
# lands locks the existing admin out of Horizon, Telescope, and their
# own /admin, since those now gate on a permission nothing else grants.
docker compose -f compose.prod.yml exec app php artisan db:seed
# Creates or tops up the public example collection the landing page links
# to (config tcgvault.demo). Idempotent; only needed when that card list
# changes. Calls tcgdex for any card not already in the Catalog.
docker compose -f compose.prod.yml exec app php artisan demo:seed-gallery
# Removes location and other metadata from photos stored before uploads
# were stripped on the way in. Idempotent; a non-zero exit lists any file
# exiftool couldn't process.
docker compose -f compose.prod.yml exec app php artisan photos:strip-metadata
# Fills in images tcgdex's API leaves out (e.g. MEP and SVP promos) for
# cards synced before the fallback existed. Idempotent; checks each file
# exists before saving it.
docker compose -f compose.prod.yml exec app php artisan catalog:backfill-images
```

## tcgcsv sync (map groups once, after the release that adds it)

`SyncTcgcsvPricesJob` runs daily at 20:30 UTC on Horizon's `supervisor-tcgcsv`
(connection `redis-long`, queue `tcgcsv`). It links prints to TCGplayer
products and compares tcgcsv's price with tcgdex's. With `TCGCSV_MODE=fill`
(the default) it also writes tcgcsv's price as the day's TCGplayer price for
linked prints tcgdex has no TCGplayer price for in the last 3 days (rows with
`origin = tcgcsv`); it never touches a tcgdex price or one entered by hand.
A gap price more than 3x off the print's tcgcsv price from the last 3 days,
or 4x off its cardmarket price (moves under $2 excepted), is held back, named
in the day's log (`gaps_implausible`) and sent to Discord — check that
print's link. A link only tcgdex's third-party ids vouch for is not filled
until a price exists to check it against (`gaps_unverified`). Nothing is
filled when the build is over 36h old. Any `TCGCSV_MODE` other than exactly
`fill` runs as `shadow`.

To roll back, set `TCGCSV_MODE=shadow` in `.env` and recreate the containers
that read it (a plain `restart` keeps the old environment):

```bash
docker compose -f compose.prod.yml up -d --force-recreate horizon scheduler
# Optional: drop the prices tcgcsv already wrote (they otherwise stay current
# for up to 3 days, and in the price history):
docker compose -f compose.prod.yml exec app php artisan tinker --execute="dump(App\Modules\Catalog\Models\CardPriceSnapshot::where('origin', 'tcgcsv')->delete());"
```

`catalog:check-pricing-freshness` alerts Discord when the last complete tcgcsv
run (or, if that record is lost, the last pulled build or tcgcsv price) is
over 30h old, or none is on record, in either mode, once a TCGplayer group is
mapped.

With no set mapped to a TCGplayer group it compares nothing, without error — so
map the groups after deploying:

```bash
# 1. Review the proposals (prints only; nothing is written):
docker compose -f compose.prod.yml exec app php artisan catalog:propose-tcgplayer-groups
# 2. Store the unambiguous ones:
docker compose -f compose.prod.yml exec app php artisan catalog:propose-tcgplayer-groups --write
# 3. Map the rest explicitly — ambiguous abbreviations (30C is two groups:
#    30th → 24722, its Classic Collection → 24837), SWSH sets whose
#    abbreviations differ between tcgcsv and tcgdex, and groups no set owns
#    (Prize Pack 22880, Trainer Galleries), e.g.:
docker compose -f compose.prod.yml exec app php artisan catalog:propose-tcgplayer-groups --set=30th --group=24722
```

The day's run (comparison and `gaps_filled`) is logged on the `tcgcsv`
channel (stderr of the `horizon` container) and kept in the cache under
`tcgcsv:shadow:<date>` for 30 days:

```bash
docker compose -f compose.prod.yml exec app php artisan tinker --execute="dump(cache('tcgcsv:shadow:'.now()->toDateString()));"
```

## Acceptance checklist (first deploy)
- [ ] `.env` on the server has `APP_DEBUG=false` and `SESSION_SECURE_COOKIE=true`
      — both are correct in `docker/prod/.env.production.example`, but that's a
      template, not what's actually loaded; confirm the real `.env`.
- [ ] `https://tcgvault.cativo.dev` loads over HTTPS with a valid Let's Encrypt cert.
- [ ] `/up` → 200.
- [ ] Login works as the seeded admin (`TCGVAULT_ADMIN_USERNAME`); session cookie is
      scoped to `tcgvault.cativo.dev`.
- [ ] `/{username}` (public gallery) loads for the admin's username.
- [ ] `/horizon` loads while authenticated, 403s for a guest.
- [ ] Upload a collection photo via `/admin/add`, then `docker compose down && up -d`
      — the photo is still there (proves the volume mount, not the writable layer).
- [ ] `docker compose exec horizon getent hosts api.tcgdex.net` resolves — confirms
      `horizon` itself has internet egress (see Architecture notes below; this is a
      real, previously-hit failure mode, not a hypothetical one).
- [ ] A manual `docker compose exec app php artisan catalog:refresh-prices` actually
      reaches tcgdex and writes real `CardPriceSnapshot` rows.
- [ ] `docker compose ps` → `app`, `horizon`, `scheduler`, `postgres`, `redis` all
      healthy/up.
- [ ] `~/deploy/tcg-vault/.env` is mode `600`; no secrets in the image or git.

## Backups
The `backup` service (`docker/prod/backup/backup.sh`) writes, once a day
after 03:00 UTC, into `~/deploy/tcg-vault/backups/`:

- `db-YYYY-MM-DD.dump`: `pg_dump` in custom format
- `photos-YYYY-MM-DD.tar`: the whole `collection-photos-data` volume

Each backup is deleted once it is 14 days old; the privacy policy states
that period, so change both together. The files are root-owned `0600`
because they hold every member's email and password hash, so read them
through a container. The scheduler's `backups:check-freshness` (06:00 UTC)
posts to Discord if either kind is missing or older than 26 h, or if the
disk has less than 5 GB free.

**These copies live on the same server.** They cover a bad migration, a
wrong delete or a corrupt volume, not losing polaris2. Copying them off the
host is still open in `ROADMAP.md`.

Every block below starts from:
```bash
cd ~/deploy/tcg-vault
dcp() { docker compose -f compose.prod.yml "$@"; }
DAY=YYYY-MM-DD   # the backup to use
```

### Check a backup
Restores the dump into a scratch database, leaving the live one alone:
```bash
dcp exec -T postgres sh -c 'createdb -U "$POSTGRES_USER" restore_check'
dcp run --rm --no-deps -T --entrypoint sh backup -c "pg_restore --no-owner --exit-on-error -d restore_check /backups/db-$DAY.dump"
dcp exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d restore_check -Atc "select count(*) from users"'
dcp exec -T postgres sh -c 'dropdb -U "$POSTGRES_USER" restore_check'
dcp run --rm --no-deps -T --entrypoint sh backup -c "tar -tf /backups/photos-$DAY.tar | wc -l"
```

### Replace the live database and photos
The dump is restored into a new database and swapped in by renaming, so a
failed restore leaves the live one untouched. Restore the database and the
photos from the **same day**: the photo volume is emptied first, so it ends
up matching the restored rows exactly. The app stays stopped until accounts
deleted since the backup are deleted again, so none of them can sign in.

1. Stop everything that reads or writes, and save the current state to
   compare against and to roll back to. Its `db-`/`photos-` names mean the
   nightly prune deletes it with the other backups:
   ```bash
   dcp stop app horizon scheduler backup
   NOW=$(date -u +%F)
   dcp run --rm --no-deps -T --entrypoint sh backup -c "umask 077 && pg_dump --format=custom --file=/backups/db-$NOW-pre-restore.dump && tar -C /photos -cf /backups/photos-$NOW-pre-restore.tar ."
   ```
2. Restore into `tcg_vault_restored`, then swap it in, both renames in one
   transaction (keeps the old one as `tcg_vault_before_restore`):
   ```bash
   dcp exec -T postgres sh -c 'createdb -U "$POSTGRES_USER" tcg_vault_restored'
   dcp run --rm --no-deps -T --entrypoint sh backup -c "pg_restore --no-owner --single-transaction --exit-on-error -d tcg_vault_restored /backups/db-$DAY.dump"
   dcp exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1 -c "ALTER DATABASE \"$POSTGRES_DB\" RENAME TO tcg_vault_before_restore; ALTER DATABASE tcg_vault_restored RENAME TO \"$POSTGRES_DB\";"'
   ```
3. Replace the photos:
   ```bash
   docker run --rm -v tcg-vault_collection-photos-data:/restore -v "$PWD/backups:/backups:ro" \
     postgres:17-alpine sh -c "find /restore -mindepth 1 -delete && tar -C /restore -xf /backups/photos-$DAY.tar"
   ```
4. **Delete again every account deleted after the backup**, since members
   were promised deletion. These are the ids in the restored database but
   not in `tcg_vault_before_restore`; each goes through the app's own
   account deletion, photos included:
   ```bash
   dcp exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select id from users order by id::text"' > /tmp/after
   dcp exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d tcg_vault_before_restore -Atc "select id from users order by id::text"' > /tmp/before
   for id in $(comm -23 /tmp/after /tmp/before); do
     dcp run --rm --no-deps -T app php artisan tinker --execute="app(\App\Support\AccountDeleter::class)->delete(\App\Models\User::findOrFail($id));"
   done
   rm /tmp/after /tmp/before
   ```
   Then drop what the restore brought back past its promised lifetime
   (debug records, reset links, old invites):
   ```bash
   dcp run --rm --no-deps -T app php artisan telescope:prune --hours=48
   dcp run --rm --no-deps -T app php artisan auth:clear-resets
   dcp run --rm --no-deps -T app php artisan model:prune --model='App\Modules\Invites\Models\Invite' --model='App\Models\ModerationAction'
   ```
5. Start again, then check that `migrate:status` shows nothing pending:
   ```bash
   dcp start app horizon scheduler backup
   dcp exec app php artisan migrate:status
   ```
6. Once everything checks out, and **within 14 days** (the privacy policy's
   backup period), drop the old database:
   `dcp exec -T postgres sh -c 'dropdb -U "$POSTGRES_USER" tcg_vault_before_restore'`.
   The pre-restore files are pruned on their own.

## Rollback
`deploy.yml` deploys the exact release tag it just built (`IMAGE_TAG`
exported before `pull`/`up`) — never the mutable `:latest` — so `docker
compose images` on the server always tells you exactly what's running.
`compose.prod.yml`'s own default (`${IMAGE_TAG:-latest}`) only applies when
`IMAGE_TAG` isn't set, i.e. manual/local deploys.

To roll back:
```bash
ssh polaris2
cd ~/deploy/tcg-vault
export IMAGE_TAG=v0.1.0   # the known-good release tag
docker compose -f compose.prod.yml pull
docker compose -f compose.prod.yml up -d
```
`deploy.yml` itself is release-triggered only (no `workflow_dispatch`), so
there's no "re-run the last deploy" button — roll back by hand as above, or
publish the known-good tag as a new GitHub Release.

## Architecture notes
- **Single image, three services.** `app` (web, port 8080 behind Traefik), `horizon`
  (queue supervisor — chosen over a bare `queue:work` since Redis is already required),
  `scheduler` (`schedule:work` — runs the daily `catalog:refresh-prices` job, Phase 4).
- **`horizon` needs its own internet egress, on `tcgvault-egress`, not just
  `tcgvault-internal`.** Queued jobs (`SyncCardPricingJob`, `ImportSetJob`, the daily
  price refresh) call tcgdex's API from inside that container — `tcgvault-internal` is
  `internal: true` and blocks all outbound routing. Missing this silently breaks every
  queued tcgdex call (DNS resolution fails) with no user-facing error — it only shows up
  as `failed_jobs` growing and sets never finishing their card backfill. `tcgvault-egress`
  is a private bridge used by nothing else on the host — not `space-server_web` — since
  `horizon` exposes no port/service and gains nothing from sitting on the same
  ~25-container shared network `app` needs for Traefik ingress.
- **Own Postgres/Redis**, not shared with tacoview's — decided deliberately (coupling
  cost > RAM saved, especially once tacoview resumes).
- **Caches** are warmed at container boot via serversideup `AUTORUN_*` flags;
  **migrations are never auto-run** (manual step above).
- **DNS** (`tcgvault.cativo.dev`) already resolves via Cloudflare (DNS-only, unproxied);
  Traefik obtains the Let's Encrypt cert on first request. No manual DNS step.
