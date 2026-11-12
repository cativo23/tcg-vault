# tcg-vault — agent notes

Laravel 13 + Livewire 4 (plain class components, no Volt) on PHP 8.4, with
Postgres 17, Redis and Horizon; Sail locally. `README.md` has the overview;
this file is what an agent needs before making changes.

## Where things live
- `ROADMAP.md` — the pending work, quickest high-value items first. Update it
  in the same PR that closes or changes an item.
- `CHANGELOG.md` — add an `[Unreleased]` entry in the same PR as any
  user-visible or operational change; a release PR only moves the heading.
- `design.md` — the locked design system. Read it before touching any color,
  spacing or layout primitive, and amend it explicitly rather than working
  around it.
- `deploy/README.md` — every production procedure and the architecture
  decisions behind the deploy. Read it before touching `docker/prod/` or a
  workflow.

## Before you start
- TDD-first: red → green → refactor. Write the failing test before the
  implementation on every change.
- Conventional commits (`type(scope): description`), one concern per commit.

## CI/CD
- **CI** (`.github/workflows/ci.yml`), on every push and PR to `master`:
  Composer and npm audit (fail on high/critical), Pint, Larastan level 8,
  `npm run build`, the JS tests, Pest against a Postgres service, and a
  production image build.
- **Release** (`auto-release.yml`): merging a `release/vX.Y.Z` branch into
  `master` creates the GitHub Release from the matching `CHANGELOG.md`
  section.
- **Deploy** (`deploy.yml`): the published Release builds and pushes the
  image, then deploys that exact tag to polaris2. Migrations are never
  auto-run: the job refuses to start an image with pending migrations, and
  the manual step is in `deploy/README.md`.

## Gotchas worth knowing up front
- `horizon` needs its own internet egress on a dedicated network (see
  `deploy/README.md`, Architecture notes). Losing it breaks every queued
  tcgdex call silently (DNS fails, no user-facing error), and an
  innocent-looking compose change can do it.
- tcgdex's `/cards` search has no result cap: always pass
  `pagination:page` / `pagination:itemsPerPage`.
- Price reads are the hot path. A change to `CardPriceResolver` or to how a
  page loads price rows must be timed against production-sized history —
  local data has a single price day, and a comparator that read model
  attributes once made every public page take 12–21 s. Keep the guards green
  (`tests/Support/PriceRowReads.php`, the parity test against
  `tests/Support/LegacyCardPriceResolver.php`).
- Any write to `card_price_snapshots` outside the app (psql, a data
  migration) bypasses the price cache: run `prices:bump-version`, then
  `prices:refresh-week-baselines`.
- Livewire's update endpoint is a hashed per-install path: use
  `EndpointResolver::updatePath()`, never a literal; its route name is
  `default-livewire.update`.
- Comments and docblocks describe current behavior and reasoning only — no
  dates, no "found live on X," no attribution to who flagged something.
