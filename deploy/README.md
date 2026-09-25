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
Copy `docker/prod/compose.prod.yml` → `~/deploy/tcg-vault/compose.prod.yml` and
`docker/prod/.env.production.example` → `~/deploy/tcg-vault/.env`, then fill real secrets:
```bash
# locally, generate an app key:
php artisan key:generate --show            # copy the base64:... value into .env APP_KEY
# on the server:
chmod 600 ~/deploy/tcg-vault/.env
```
Required `.env` values to fill: `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `RESEND_KEY`
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
