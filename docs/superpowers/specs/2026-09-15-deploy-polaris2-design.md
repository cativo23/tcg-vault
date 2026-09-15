# tcg-vault — Production Deploy to polaris2 Design

**Date:** 2026-09-15
**Status:** Approved (brainstorming) → ready for implementation plan

## Goal

Ship tcg-vault live at **`https://tcgvault.cativo.dev`** on the polaris2 Hetzner VPS,
reusing the same Traefik-fronted Docker Compose pattern already proven twice on that
box (`portfolio-api`, and especially `tacoview` — the closest analog: also Laravel +
Postgres + Redis). First deploy is **manual build → push → deploy**; an automated
release pipeline is an explicit non-goal for this slice.

## Architecture

Server-rendered Blade + Livewire — no SPA, no separate API origin, no CORS/Sanctum
concerns. Simpler than tacoview in that respect: one Laravel app, three processes.

```
           Cloudflare DNS (DNS-only, *.cativo.dev → 167.235.52.161)
                          │
                    Traefik (websecure :443, Let's Encrypt)
                          │  Host(`tcgvault.cativo.dev`)
                          ▼
              ┌─────────────────────────────┐
              │  app  (cativo23/tcg-vault)  │  serversideup/php:8.4-fpm-nginx
              │  nginx → php-fpm (Laravel)  │  Blade + Livewire, server-rendered
              └─────────────────────────────┘
                 │ (same image, no web port)
        ┌────────┴─────────┐
        ▼                  ▼
   horizon (queues)   scheduler (schedule:work)
        │                  │
        └──────┬───────────┘
               ▼
   postgres   +   redis (requirepass)      ← tcgvault-internal network (internal: true)
```

## Components

### 1. App image — `cativo23/tcg-vault` (multi-stage `docker/prod/Dockerfile`)

No frontend-framework build stage (no React/Vue) — just Vite bundling the project's
own CSS/JS (Tailwind + the small amount of Alpine/JS this app ships).

- **Stage 1 (assets):** `node:24-alpine`, `npm ci && npm run build` → `public/build/`.
- **Stage 2 (vendor):** `serversideup/php:8.4-fpm-nginx` base, `composer install
  --no-dev --optimize-autoloader --no-scripts` (matches the established
  `CatalogSyncService`/`final`-class-heavy codebase fine — no dev-only providers
  should leak into prod either way, but `--no-scripts` + a `bootstrap/cache`
  purge, same as tacoview, guards against it).
- **Stage 3 (runtime):** copy app + vendor + built assets into place. Document root
  = Laravel `public/`.
- **Caching at startup, not build** (env vars don't exist at build time): serversideup's
  `AUTORUN_*` startup hooks run `config:cache route:cache view:cache event:cache` on
  every boot. **`AUTORUN_LARAVEL_MIGRATION=false`** — migrations are never auto-run,
  always a deliberate manual step (same reasoning as tacoview: a bad deploy must
  never silently mutate the schema).
- `rm bootstrap/cache/{packages,services}.php` before runtime, so the package
  manifest regenerates from the no-dev vendor at boot (a dev-only provider — Pail,
  Sail, `laravel/pint` as a package, etc. — would otherwise crash `artisan`).
- Same image is reused (different `command:`) by `horizon` and `scheduler`.

### 2. Compose services (`~/deploy/tcg-vault/compose.prod.yml`)

- **`app`** — the image; Traefik labels (below); `env_file: .env`; `depends_on`
  postgres+redis healthy; networks `space-server_web` + `tcgvault-internal`.
  `~192M` limit / `~96M` reservation.
- **`horizon`** — same image, command `php artisan horizon`. Supervises its own
  worker processes and gives a dashboard (`/horizon`, admin-auth-gated same as
  `/admin`) — chosen over a bare `queue:work` specifically because Redis is
  already a dependency here (Horizon requires it) and the marginal cost over
  `queue:work` is ~zero once a supervisor process is running either way.
  `tcgvault-internal` only (no Traefik route — reachable through `app`'s own
  `/horizon` route, same origin). `~128M` limit / `~64M` reservation.
- **`scheduler`** — same image, command `php artisan schedule:work` (runs
  `catalog:refresh-prices` daily — Phase 4's job). `tcgvault-internal` only.
  `~64M` limit / `~32M` reservation.
- **`postgres`** — `postgres:17-alpine` (matches local Sail exactly), env from
  `.env`, `pgdata` volume, healthcheck `pg_isready`, `tcgvault-internal` only.
  `~192M` limit / `~96M` reservation.
- **`redis`** — `redis:7-alpine`, `--requirepass ${REDIS_PASSWORD}`, `redis-data`
  volume, healthcheck, `tcgvault-internal` only. `~64M` limit / `~32M`
  reservation.

Total reserved ≈ 320M, limits ceiling ≈ 640M — comfortably inside the ~4.2 GiB
currently free on polaris2 (tacoview and umami were paused to make room; see the
session's own RAM audit). Deliberately tighter than tacoview's own numbers rather
than copied wholesale — this app has no SPA build, no multi-tenant-scale queue
volume yet.

### 3. Storage — collection photos

`collection-photos` is a **local disk**, not S3 (`config/filesystems.php`,
`storage_path('app/public/collection-photos')`). A named volume
(`collection-photos-data`) mounts onto `storage/app/public` so uploaded photos
survive every redeploy — without it, a `docker compose up` after a new image pull
would silently wipe every photo a user uploaded. The `public/storage` symlink
(`php artisan storage:link`) is baked into the image at **build** time, not
runtime — the symlink itself never changes; only its target's contents do, and
those live on the mounted volume.

### 4. Traefik labels (on `app` only)

```
traefik.enable=true
traefik.http.routers.tcgvault.rule=Host(`tcgvault.cativo.dev`)
traefik.http.routers.tcgvault.entrypoints=websecure
traefik.http.routers.tcgvault.tls.certresolver=letsencryptresolver
traefik.http.routers.tcgvault.middlewares=security-headers@file
traefik.http.services.tcgvault.loadbalancer.server.port=8080
traefik.docker.network=space-server_web
```

### 5. Networks & volumes

- Networks: `space-server_web` (`external: true`, shared Traefik), `tcgvault-internal`
  (`driver: bridge`, `internal: true`) — **not** shared with `tacoview-internal`;
  each project keeps its own Postgres/Redis (decided explicitly this session: the
  RAM saved by sharing wasn't worth coupling the two projects' availability,
  especially once tacoview resumes).
- Volumes: `pgdata`, `redis-data`, `collection-photos-data` (all `local` driver,
  named).

### 6. Secrets — `~/deploy/tcg-vault/.env` (mode 600)

`APP_NAME=tcg-vault`, `APP_ENV=production`, `APP_KEY=` (generated locally via
`php artisan key:generate --show`, filled in **before** first boot — avoids the
config-cache chicken-and-egg), `APP_DEBUG=false`, `APP_URL=https://tcgvault.cativo.dev`,
`DB_CONNECTION=pgsql` + `DB_HOST=postgres` + `DB_*`, `REDIS_HOST=redis` +
`REDIS_PASSWORD=`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`,
`SESSION_DRIVER=redis`, `SESSION_DOMAIN=tcgvault.cativo.dev`,
`TCGVAULT_ADMIN_USERNAME=cativo23`, `MAIL_MAILER=resend` + `RESEND_KEY=` (same
Resend account/verified `cativo.dev` domain tacoview already sends from —
needed for Breeze's password-reset mail even though registration itself stays
off), `MAIL_FROM_ADDRESS=tcgvault@cativo.dev`. `.env` is created on the server,
never committed.

### 7. `TrustProxies` — mandatory, currently missing

Traefik terminates TLS, so without telling Laravel to trust the proxy, every
request looks like plain `http://` to the framework: `url()`/`asset()` helpers
generate `http://` links, and any future signed URL (email verification,
password reset — Breeze ships both) would fail its signature check or render
with the wrong scheme. Tacoview hit this in production. `bootstrap/app.php`
currently has **no** `trustProxies` configuration at all — this must be added
(`$middleware->trustProxies(at: '*')`, standard for a single-hop reverse proxy
like Traefik) as part of the implementation plan, not left implicit.

### 8. DNS / TLS

`*.cativo.dev` already resolves to `167.235.52.161` (Cloudflare, DNS-only,
unproxied — verified for tacoview/portfolio-api already). No manual DNS step;
Traefik obtains the Let's Encrypt cert for `tcgvault.cativo.dev` on first
request via the existing `letsencryptresolver`.

## Deploy runbook (manual, this slice)

1. **Build & push** (local, repo root): `docker build -t cativo23/tcg-vault:latest
   -f docker/prod/Dockerfile .` then `docker push cativo23/tcg-vault:latest`
   (`docker login` first).
2. **Server prep** (first time): `ssh polaris2`, `mkdir -p ~/deploy/tcg-vault`,
   copy `compose.prod.yml` + `.env` (chmod 600) into place, confirm
   `docker network ls | grep space-server_web`.
3. **First boot:** `cd ~/deploy/tcg-vault && docker compose -f compose.prod.yml
   pull && docker compose -f compose.prod.yml up -d`.
4. **Migrate + seed:** `docker compose exec app php artisan migrate --force`,
   then `docker compose exec app php artisan db:seed` (the existing
   `DatabaseSeeder` creates/validates the admin user against
   `TCGVAULT_ADMIN_USERNAME`, same as local — no new prod-only seeder needed).
5. **Verify:** `https://tcgvault.cativo.dev` loads over HTTPS with a valid cert;
   `/up` → 200; log in as the admin; add a card, confirm it round-trips through
   real tcgdex (network from polaris2 is confirmed healthy — verified earlier
   this session against the live outage); `docker compose ps` shows `app`,
   `horizon`, `scheduler`, `postgres`, `redis` all healthy.
6. **Redeploy (future):** `docker compose pull && docker compose up -d &&
   docker compose exec app php artisan migrate --force`.

## Non-Goals (deferred)

- GitHub Actions autorelease (build → push on merge to `master`) — explicit
  follow-up, matches the same "manual first" scope tacoview shipped with.
- DB backups automation — a known gap (shared by every app on this box today),
  not solved in this slice.
- Sharing a Postgres/Redis instance with tacoview — decided against this
  session (coupling cost > RAM saved, now that tacoview is paused anyway).
- A staging environment.

## Testing / acceptance

- `docker build` succeeds locally; the one image runs `app`/`horizon`/`scheduler`
  correctly via three different `command:` overrides.
- On polaris2: site loads over HTTPS with a valid LE cert; login works (session
  cookie scoped to `tcgvault.cativo.dev`); the public gallery
  (`/{username}`) loads for the seeded admin; `/up` returns 200; `horizon`
  dashboard reachable at `/horizon` while authenticated; a manually-dispatched
  `catalog:refresh-prices` run actually reaches tcgdex and writes real
  `CardPriceSnapshot` rows (this sandbox's own egress can't reach tcgdex right
  now, but polaris2's can — confirmed directly this session via `curl` from
  `polaris2` mid-outage).
- No secrets in the image or git; `.env` is mode 600 on the server.
- `APP_DEBUG=false`; `trustProxies` configured so `url()`/`asset()` render
  `https://`.
- Uploading a collection photo survives a full `docker compose down && up`
  cycle (proves the volume mount is real, not relying on the container's
  writable layer).

## Decisions taken (not re-asked)

- Domain: `tcgvault.cativo.dev`.
- Runtime: `serversideup/php:8.4-fpm-nginx`, matching tacoview and local Sail's
  own PHP 8.4 runtime.
- Three services off one image: `app` + `horizon` (not a bare `queue:work` —
  Redis is already required, Horizon's marginal cost is ~zero) + `scheduler`.
- `postgres:17-alpine` + `redis:7-alpine` (requirepass) on an `internal: true`
  network, **not** shared with tacoview's.
- Resource limits sized tight and specific to this app's actual needs, not
  copied from tacoview/portfolio-api wholesale.
- A named volume for `collection-photos` — local disk storage stays local disk
  in production, just persisted; no S3 migration in this slice.
- Manual build/push/deploy this slice; GitHub Actions autorelease deferred.
- DNS already resolves; Traefik handles TLS; no manual DNS step.
- `TrustProxies` must be added to `bootstrap/app.php` — currently missing,
  confirmed via direct inspection, not previously an issue only because local
  dev never sits behind a reverse proxy.
