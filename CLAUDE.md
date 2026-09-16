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

## Deploy
Manual, no CI: build/push the Docker image, then `ssh` to the production
host and `docker compose pull && up -d`. Full steps and the architecture
decisions behind them are in `deploy/README.md` — read it before touching
anything under `docker/prod/`.

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
