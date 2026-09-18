# tcg-vault — agent notes

Laravel 13 + Livewire 3 on Sail/Postgres/Redis/Horizon. See `README.md` for
the full picture; this file is what an agent needs before making changes.

## Before you start
- Read `design.md` before touching any color, spacing, or layout primitive
  — it's a locked design system, amend it explicitly rather than working
  around it.
- TDD-first: red → green → refactor. Write the failing test before the
  implementation on every change.
- Conventional commits (`type(scope): description`), one concern per commit.

## CI/CD
- **CI** (`.github/workflows/ci.yml`): Pint + Pest (Postgres service
  container) + `npm run build`, on every push/PR to `master`.
- **Release** (`.github/workflows/auto-release.yml`): merging a
  `release/vX.Y.Z` branch into `master` creates a GitHub Release from the
  matching `CHANGELOG.md` section.
- **Deploy** (`.github/workflows/deploy.yml`): that Release publish builds
  and pushes the Docker image, then SSHes into polaris2 and runs
  `docker compose pull && up -d`. Migrations are never auto-run — the job
  prints the manual `artisan migrate --force` / `db:seed` reminder instead.
- Full steps, required secrets, and the architecture decisions behind them
  are in `deploy/README.md` — read it before touching anything under
  `docker/prod/` or the deploy workflow.

## Gotchas worth knowing up front
- `horizon` (the queue container) needs its own internet egress on a
  dedicated network — see `deploy/README.md`'s Architecture notes. This has
  broken silently before (queued tcgdex calls failing DNS resolution with
  no user-facing error) and is easy to reintroduce with an innocent-looking
  compose change.
- tcgdex's `/cards` search has no built-in result cap — always pass
  `pagination:page`/`pagination:itemsPerPage`, never fetch unbounded.
- Comments and docblocks describe current behavior and reasoning only — no
  dates, no "found live on X," no attribution to who flagged something.
