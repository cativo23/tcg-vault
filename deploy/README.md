# tcg-vault — Deploy to polaris2

Single-origin Docker deploy at **https://tcgvault.cativo.dev**. Manual
build/push/deploy (GitHub Actions autorelease is a later slice).

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

## Build & push (local, repo root)
```bash
docker login                                # Docker Hub, user cativo23
docker build -f docker/prod/Dockerfile -t cativo23/tcg-vault:latest .
docker push cativo23/tcg-vault:latest
```

## Deploy / redeploy (server)
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
Until releases are tagged, rollback = rebuild/push a known-good commit as `:latest` and
redeploy (`docker compose pull && up -d`). Tagging (`:sha` / `:vX`) comes with the GitHub
Actions autorelease slice.

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
