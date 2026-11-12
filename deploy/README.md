# tcg-vault — Deploy to polaris2

Single-origin Docker deploy at **https://tcgvault.cativo.dev**. Automated
via GitHub Actions: CI on every push/PR, then a `release/vX.Y.Z` branch
merge to `master` builds/pushes the image and deploys. Manual steps below
still apply for the one-time server setup and for anything the workflows
deliberately don't automate (migrations, first-time secrets).

## Cutting a release
Each PR adds its own `[Unreleased]` entry to `CHANGELOG.md`, so a release
only moves that heading:
```bash
git checkout -b release/vX.Y.Z master
# CHANGELOG.md: rename "## [Unreleased]" to "## [X.Y.Z] - YYYY-MM-DD" and add
# a new empty "## [Unreleased]" above it; list any manual step under "Deploy notes"
git commit -am "chore(release): vX.Y.Z"
git push -u origin release/vX.Y.Z
gh pr create --base master --title "release: vX.Y.Z" --body "See CHANGELOG.md"
# merging that PR:
#   1. auto-release.yml creates GitHub Release vX.Y.Z from the CHANGELOG section
#   2. deploy.yml builds and pushes cativo23/tcg-vault:vX.Y.Z (and :latest),
#      then deploys that tag on polaris2 — see "Deploying a release" below
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
`compose.prod.yml` sets `REDIS_CLIENT=phpredis` on app, horizon and scheduler, overriding any `REDIS_CLIENT` in `.env`.

Optional: `DISCORD_ALERT_WEBHOOK_URL` — a Discord webhook URL for
operational alerts (see `App\Support\DiscordAlerter`). Left unset,
alerting is silently off rather than erroring; nothing else depends on
it. Not the same webhook as `alertmanager-discord`'s — that one is
host-level infra alerting (CPU, disk), this one is app-level (a stalled
daily job).

## Deploying a release
`deploy.yml` does this on its own when a Release is published: it copies
`compose.prod.yml` and the backup script to the server, pulls the release's
image, refuses to go on if that image has a pending migration, runs `up -d`
with `IMAGE_TAG` set to the release tag, restarts `backup` (its script is a
mounted file), waits for `app` to report healthy, and prints the running tags.

Every manual command below runs on the server, from `~/deploy/tcg-vault`:
```bash
ssh polaris2
cd ~/deploy/tcg-vault
```

**Always name the tag when recreating by hand.** `compose.prod.yml` uses
`${IMAGE_TAG:-latest}` and only the deploy job sets it, so an `up -d` or
`--force-recreate` without `IMAGE_TAG=vX.Y.Z` starts the local `:latest`,
which can be an older release, and the container silently runs old code.
Afterwards, `docker compose -f compose.prod.yml ps --format '{{.Service}} {{.Image}}'`
must show the same tag on `app`, `horizon` and `scheduler`.

### A release with a migration
Migrations are never run automatically, so the deploy job fails on purpose
(`pending migration(s) on the new image — refusing to start it`). The old
release keeps serving. Run the migration from the **new** image — `exec`
would reach the old container, which doesn't have the migration file — plus
any step the release's "Deploy notes" list, then re-run the failed job:
```bash
IMAGE_TAG=vX.Y.Z docker compose -f compose.prod.yml run --rm --no-deps app php artisan migrate --force
# e.g. v1.7.0's note: fill the new 7-day-move table before the page uses it
IMAGE_TAG=vX.Y.Z docker compose -f compose.prod.yml run --rm --no-deps app php artisan prices:refresh-week-baselines
```
Then, from the repo on your machine: `gh run rerun <deploy run id> --failed`.

### Commands a release may call for
Each is idempotent. Run them with `exec` once the release is up, only when
its CHANGELOG "Deploy notes" or the situation calls for it:

| Command | When |
| --- | --- |
| `php artisan db:seed` | After a release that adds a role or permission. It is the only thing that grants new permissions to existing accounts; skipping it can lock the admin out of `/admin` or Horizon. Safe on any deploy. |
| `php artisan prices:refresh-week-baselines` | After the migration that creates `card_price_week_baselines`, a restore, or any write outside the app to past price rows. It also runs nightly at 04:15 UTC. |
| `php artisan prices:bump-version` | After any write to a `card_price_snapshots` row dated before today made outside the app's own price writes (psql, tinker, a data migration, a hand-added price): the gallery and the Activity chart would keep serving the cached prices. Exits non-zero if the cache didn't take it; run it again once the cache is back. |
| `php artisan gallery:warm-value-series --sync` | After a release that changes the Activity chart's cache key (the key prefix starts cold); the hourly schedule would get there on its own. |
| `php artisan demo:seed-gallery` | When the demo collection's card list (`config/tcgvault.php`, `demo`) changes. Calls tcgdex for any card not yet in the catalog. |
| `php artisan catalog:backfill-images` | After new cards of sets tcgdex has no images for (MEP, MEE, 30th Classic Collection) are synced and linked: takes the image of the card's TCGplayer product. `--dry-run` lists first. |
| `php artisan photos:strip-metadata` | Only for photos stored before uploads were stripped on the way in (already run in production). |

```bash
docker compose -f compose.prod.yml exec app php artisan <command>
```

Telescope is off in production through the compose environment; turning it
on means editing `compose.prod.yml`, not `.env`.

### Building the image by hand
Only if GitHub Actions is unavailable (repo root, then deploy with
`IMAGE_TAG` set as above):
```bash
docker login                                # Docker Hub, user cativo23
docker build -f docker/prod/Dockerfile -t cativo23/tcg-vault:vX.Y.Z .
docker push cativo23/tcg-vault:vX.Y.Z
```

## Manual price refresh

The `scheduler` runs `catalog:refresh-prices` daily; to run it by hand:
```bash
docker compose -f compose.prod.yml exec app php artisan catalog:refresh-prices
```
Each card has at most one pending sync. A re-run skips cards whose sync is
still queued, running or retrying (until that job finishes or fails, at most
about 3 h; the lock ceiling is 4 h) and reports them as
`Dispatched X, skipped Y (already queued).`

## tcgcsv sync

`SyncTcgcsvPricesJob` runs daily at 20:30 UTC on Horizon's `supervisor-tcgcsv`
(connection `redis-long`, queue `tcgcsv`). It links prints to TCGplayer
products and compares tcgcsv's price with tcgdex's. With `TCGCSV_MODE=fill`
(the default) it also writes tcgcsv's price as the day's TCGplayer price for
linked prints tcgdex sent no TCGplayer price for that day, or whose
TCGplayer price was last updated over 3 days ago (tcgdex keeps re-sending a
frozen price; rows with `origin = tcgcsv`); it never touches a tcgdex price
that is current or one entered by hand. Yesterday's tcgdex row doesn't make a
print current: the midnight sync leaves a print tcgdex stopped pricing to this
run, which keeps it from aging into cardmarket's EUR.
A gap price more than 3x off the print's tcgcsv price from the last 3 days
(15x off its last one from the last 90 days when none is that recent), or 3x
off tcgdex's last TCGplayer price updated within 14 days (moves under $2
excepted), is held back, named in the day's log (`gaps_implausible`) and sent
to Discord — check that print's link. Cardmarket plays no part in these checks.
If the link is right, confirm it as an admin link: it is never `unverified` and a
real jump from an old tcgcsv price is written, not held (the 3x bound against
a recent tcgcsv or tcgdex price still applies).
It prints the rows updated — expect `1`:

```bash
docker compose -f compose.prod.yml exec app php artisan tinker --execute="dump(App\Modules\Catalog\Models\CardTcgplayerLink::where('card_id', App\Modules\Catalog\Models\Card::where('tcgdex_id', 'mep-004')->value('id'))->where('variant', 'holofoil+set-logo+staff')->update(['method' => 'admin', 'verified_at' => now()]));"
```

A hold is not permanent: once the old tcgcsv price is over 3
days old, the bound against it widens to 15x (an admin or verified link has no
bound against an old price), so a real jump is written, and Discord gets a note
naming it when no recent price was left to check it against
(`gaps_accepted_after_hold`). A link only tcgdex's
third-party ids vouch for (`tcgdex-thirdparty`) is not filled
until the print has a price to check it against: a tcgcsv one from the last 90
days or a tcgdex one from the last 14 (`gaps_unverified`, the first ten named in
`gaps_unverified_prints`). An admin link, or a verified link into an extra group,
is never `unverified`. Nothing is
filled or kept when the build is from an earlier UTC day than the run (one
published after the last retry, or a retry that crosses midnight): a row is
dated with the day it was read, so that build would show yesterday's prices as
today's. The run logs a warning, leaves the build unrecorded so today's is still
pulled when it appears, and retries hourly like an unpublished build. Any
`TCGCSV_MODE` other than exactly `fill` runs as `shadow`, which compares an old
build as usual.

The evening run also keeps every price that passes its checks, for the tcgdex
sync (`fallback_kept` in the day's log counts them): the cache holds one entry
per print (`tcgcsv:fallback:<card id>|<variant>`) for 36h after tcgcsv's build.
Any tcgdex sync (the midnight one above all, but also adding a card or
importing a set) writes no TCGplayer row for a linked print when tcgdex sent
no TCGplayer price for it, a zero one, or one TCGplayer last updated over 3
days ago, and a price is kept for it. The day's row appears when the evening
run reads that day's build, so a new day never opens with yesterday's price
dated today; until then the app keeps showing the previous day's row and its
movement. A price with no update date counts as current, a kept price whose
product or subtype no longer matches the print's link is ignored, and `shadow`
mode leaves the sync as it was. tcgdex's price for a print tcgcsv has nothing
kept for is still written as before.

Two things to know when reading the day's log:

- A print tcgdex already wrote for today can't be held back or written by the
  evening run, so it is skipped before any check: it adds to neither
  `gaps_unverified` nor `gaps_implausible_count`. If its tcgcsv price fails
  the checks, the price is not kept and the print is counted in
  `fallback_rejected_count` (the first ten named in `fallback_rejected_prints`).
  A rising count means tcgdex's frozen price keeps being written; check those
  prints' links.
- A kept price lives its 36h even when the next evening's price for that print
  fails its checks, so the midnight sync still leaves that print's row to the
  evening run (the previous day's row keeps standing until it is written).
  When the evening run fails for several days in a row, a print that only
  tcgcsv prices (a new set, a deck exclusive) has no row from the last good day
  on and turns to cardmarket's EUR three days later; before, the midnight copy
  held it for longer, with a price a few days old.

**Extra groups.** A deck's non-holo print is a TCGplayer product of its own in
the "Deck Exclusives" group (1840), which no set maps, while tcgdex prices the
card's `normal` print from the main product's cardmarket figure. The groups in
`config/tcgcsv.php` `extra_groups` are fetched with their products' names and
numbers on each build, and a card links to one of their products only when it
is that card's plain `normal` print on a product of its own (its cardmarket
product differs from the card's top-level one) and the product's printed number,
total (when the card says what the set's printed total is) and name (as whole
words, accents and punctuation ignored) are the card's. The check is repeated on every run
that fetches the group; a link that passes is marked verified (`verified_at`),
and one whose product stops matching loses it.

A verified link is vouched for like an admin's: it is never `unverified`. Its
first price has nothing to be bounded by; every later price is bounded by its
last tcgcsv price. A group whose products can't be read counts as failed, so
the build is retried. Removing a group from the config does not roll anything
back: its links keep their group fetched, but they stop counting as verified
(the next run clears `verified_at`), so they are judged like any third-party
link (named in the run's log as `gaps_unverified` while they have no price to
check against, but not alerted), until the links
are deleted (`card_tcgplayer_links.group_id = 1840`). The resolver prefers a
`normal` TCGplayer price to a `holofoil` one, so a copy saved without a
variant on such a card shows the non-holo's price.

**Stamped prints (30th Celebration).** tcgdex lists every 30th Celebration print
with a 30th-anniversary stamp, mostly as `normal`, while TCGplayer sells each as
a Holofoil-only product. Two rules let such a card price from tcgcsv:

- *Subtype.* A card whose only standard `variants_detailed` entry is stamped or
  foil, on the card's own cardmarket product, as a `normal` or `holofoil` print,
  links to the only subtype its product has (instead of demanding `Normal` and
  being counted in `skipped_no_matching_subtype`). Cards with several entries,
  plain prints and reverse prints are unchanged.
- *First price.* Such a link is `tcgdex-thirdparty`, so it stays `unverified`
  (counted in `gaps_unverified`) until it has a tcgcsv or tcgdex price of the
  print to check against; confirming it as an admin link fills it.

Known limits: once tcgdex prices these sets itself, it files the price under
TCGplayer's subtype label, which can differ from the link's variant key (a
`normal` link on a Holofoil product). A card never holds the same product and
subtype on two prints: discovery refuses the second link, logs it, and names it
in the run summary's `link_conflicts_prints` (count in `link_conflicts`). When
tcgdex flips a card between its `normal` and stamped-`holo` shapes, the old link
stays. Neither gives a wrong price.

In `shadow` mode the comparison sets a print tcgdex sent no TCGplayer price for
today, or whose price was last updated over 3 days ago, among
`linked_without_tcgdex_price`, not `compared`, so the kept `tcgcsv:shadow:<day>`
stats shift when tcgdex freezes or drops prints.

To roll back, set `TCGCSV_MODE=shadow` in `.env` and recreate the containers
that read it (a plain `restart` keeps the old environment), naming the running
release's tag (see "Always name the tag" above):

```bash
IMAGE_TAG=vX.Y.Z docker compose -f compose.prod.yml up -d --force-recreate horizon scheduler
# Optional: drop the prices tcgcsv already wrote (they otherwise stay current
# for up to 3 days, and in the price history):
docker compose -f compose.prod.yml exec app php artisan tinker --execute="dump(App\Modules\Catalog\Models\CardPriceSnapshot::where('origin', 'tcgcsv')->delete());"
```

`catalog:check-pricing-freshness` alerts Discord when the last complete tcgcsv
run is over 30h old, or none is on record. If that record is lost, the last
pulled build, then the newest tcgcsv price, stands in. This applies in either
mode, once a TCGplayer group is mapped.

`catalog:report-price-coverage` runs daily at 21:45 UTC, after the tcgcsv run,
and sends one Discord message naming what it finds: cards of a mapped set that
no TCGplayer link points at, sets with cards and no mapped group, prints whose
newest TCGplayer price is over 3 days old by TCGplayer's own date (owned ones
marked), (how old the newest price rows are is not judged here: the catalog sync is
watched by `catalog:check-pricing-freshness`, through the share of cards synced
in the last 26h, and the tcgcsv sync by its run record). It writes nothing but a cache key per finding it named, so the
same finding is repeated once a week, not nightly; a list longer than ten is
named ten at a time over the following nights. A feed with no rows at all is not reported.
Run it by hand to see the counts: `php artisan catalog:report-price-coverage`.

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

### Linking a print to a TCGplayer product by hand
Discovery and the identity matcher link base prints only. A stamped print
(Prize Pack `+player-rewards-program`, `pokeween-2026`, staff and other
promo stamps), a deck-exclusive print with no TCGplayer id from tcgdex, or a
card whose set has no product ids is never linked on its own; until it is, a
copy is valued at the plain print's price or at Cardmarket's EUR.

1. Find the product in its TCGplayer group: the group's `products` file lists
   name and number, and its `prices` file the subtypes (`subTypeName`). Prize
   Pack prints are in group 22880, Pokéween 2026 in 2374. tcgcsv refuses
   Python's default User-Agent, so use curl with your own:
   ```bash
   curl -s -A "tcg-vault-admin" https://tcgcsv.com/tcgplayer/3/22880/products > products.json
   curl -s -A "tcg-vault-admin" https://tcgcsv.com/tcgplayer/3/22880/prices > prices.json
   ```
2. Store an `admin` link for the print's variant key (the copy's `variant`),
   with that product, subtype and group:
   ```bash
   # Night Stretcher's Prize Pack print (sv06.5-061), as it was linked:
   docker compose -f compose.prod.yml exec app php artisan tinker --execute="dump(App\Modules\Catalog\Models\CardTcgplayerLink::updateOrCreate(['card_id' => App\Modules\Catalog\Models\Card::where('tcgdex_id', 'sv06.5-061')->value('id'), 'variant' => 'normal+player-rewards-program'], ['product_id' => 619147, 'sub_type' => 'Normal', 'group_id' => 22880, 'method' => 'admin', 'verified_at' => now()])->wasRecentlyCreated);"
   ```
3. The price arrives with the next 20:30 UTC run. tcgcsv asks for no re-pull
   of the same file within 24 h, so wait for it rather than forcing a fresh
   build; writing a price row by hand instead needs `prices:bump-version`
   afterwards (see "Commands a release may call for").

### Identity matcher (log only)

`TCGCSV_IDENTITY_LINKS=log` makes the run judge every base print of a card in
a mapped set by its TCGplayer product's own printed number and name, and
report what it would link; any other value leaves it off. It writes nothing:
no link, no price. It asks tcgcsv for each mapped group's products as well
(one more request per group per build), which is not free: a throttle (429) or
a lost connection on a products file fails that group's prices for the run, as
for a price file, and the retry re-reads both. Any other failure there costs
only the matcher that group's cards, and a matcher error is logged and counted
as `matcher-error` without touching the price fill. The run summary's `identity` block
counts the prints that `agree` with their link, would get a `different`
product, or would get a `new` link, plus every refusal by reason (the first
ten of each are named; a retry that re-fetched only some groups says
`partial_retry` there and refuses the cards of the rest, so compare full runs).
A print is refused unless exactly one product in the set's own groups, priced
or not, has the card's number, the set's total and exactly its name.
Turn it on in `.env` and recreate the containers that read it:

```bash
IMAGE_TAG=vX.Y.Z docker compose -f compose.prod.yml up -d --force-recreate horizon scheduler
```

## Acceptance checklist (first deploy)
- [ ] `.env` on the server has `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` and `LOG_CHANNEL=daily` (with `stderr`, `storage/logs` stays empty and the logs are lost when a container is recreated, i.e. at every deploy)
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
   And stop serving cached prices: the gallery caches price rows under a
   price-data version, and the Activity chart caches past days under a
   price-history epoch. Only the app's own price writes move the version,
   and nothing but this command moves the epoch, so the restore wrote
   around both. The same goes for any other change to a
   `card_price_snapshots` row dated before today, by `psql` or by the app
   (a data migration, tinker, a backfill, a hand-added prize-pack price): run the same command after it, and then the baseline rebuild that follows it.
   It exits non-zero if the cache would not take the change; run it again
   once the cache is back:
   ```bash
   dcp run --rm --no-deps -T app php artisan prices:bump-version
   ```
   Then rebuild the 7-day-move baselines from the restored prices (until
   then a card whose newest price changed shows no move):
   ```bash
   dcp run --rm --no-deps -T app php artisan prices:refresh-week-baselines
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
export IMAGE_TAG=vX.Y.Z   # the known-good release tag
docker compose -f compose.prod.yml pull
docker compose -f compose.prod.yml up -d
```
`deploy.yml` itself is release-triggered only (no `workflow_dispatch`), so
there's no "re-run the last deploy" button — roll back by hand as above, or
publish the known-good tag as a new GitHub Release. A rollback does not undo
a migration: if the older code cannot run on the newer schema, restore the
pre-release dump instead (see Backups).

## Architecture notes
- **One app image, three roles, plus three stock services.** From the app image: `app`
  (web, port 8080 behind Traefik), `horizon` (queue supervisor — chosen over a bare
  `queue:work` since Redis is already required) and `scheduler` (`schedule:work`, every
  job in `routes/console.php`). Stock images: `postgres` and `backup` (both
  `postgres:17-alpine`) and `redis`.
- **`horizon` needs its own internet egress, on `tcgvault-egress`, not just
  `tcgvault-internal`.** Queued jobs (`SyncCardPricingJob`, `ImportSetJob`, the daily
  price refresh) call tcgdex's API from inside that container — `tcgvault-internal` is
  `internal: true` and blocks all outbound routing. Missing this silently breaks every
  queued tcgdex call (DNS resolution fails) with no user-facing error — it only shows up
  as `failed_jobs` growing and sets never finishing their card backfill. `tcgvault-egress`
  is a private bridge used by nothing else on the host — not `space-server_web` — since
  `horizon` exposes no port/service and gains nothing from sitting on the same
  ~25-container shared network `app` needs for Traefik ingress.
- **The Activity chart's cache outlives price writes.** Its key carries the day, the
  owned prints (card, variant, summed quantity) and the price-history epoch, not the
  price data: a price written today is stamped today and cannot change a past day, and
  today's point is replaced with the page's live headline at read time. Past history
  corrected by hand needs `prices:bump-version`. A holdings change queues a
  `WarmValueSeriesJob` 60 s later (model observers on `CollectionItem` and `Collection`),
  and the hourly `gallery:warm-value-series` covers the rest, the new day's key at
  00:00 UTC included. Page views that had to build the series log a warning
  (`activity chart built on a page view`).
- **Own Postgres and Redis**, not shared with the host's other projects — decided
  deliberately (the coupling costs more than the RAM it would save). The cache and
  the queue still share this Redis (see `ROADMAP.md`, Polish).
- **Caches** are warmed at container boot via serversideup `AUTORUN_*` flags;
  **migrations are never auto-run** (see "A release with a migration").
- **DNS** (`tcgvault.cativo.dev`) already resolves via Cloudflare (DNS-only, unproxied);
  Traefik obtains the Let's Encrypt cert on first request. No manual DNS step.
