# tcg-vault

A personal Pokémon TCG collection tracker. A collector adds the cards they
own (manually, or by importing a TCGplayer export), and gets a public
gallery with daily-synced market pricing (tcgplayer USD + cardmarket EUR),
set completion tracking, and a price/activity history — all sourced from
[tcgdex](https://tcgdex.dev/).

Live at **https://tcgvault.cativo.dev**.

## Stack

Laravel 13 + Livewire 3 (Volt-free, plain class components) on Sail,
Postgres, Redis, Horizon for the queue. No frontend framework — Tailwind +
a small hand-rolled CSS design system (`design.md`, `resources/css/app.css`)
plus a few lines of vanilla JS (`resources/js/app.js`).

## Getting started

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install && ./vendor/bin/sail npm run build
```

Runs on whatever `APP_PORT` is set to in `.env` (`docker-compose.yml`
defaults to 80) — this machine uses 8090 since 80/8080 are taken by other
local services.

## Try it

Import an entire set (cards + current pricing) from tcgdex:

```bash
./vendor/bin/sail artisan catalog:import-set me05
```

Or sync pricing for every card already in the catalog (the same command
the daily schedule runs):

```bash
./vendor/bin/sail artisan catalog:refresh-prices
```

A single card failing (not found, transient network error, or a malformed
API response) is reported and skipped — it never aborts the rest of an
import or refresh.

## Tests

```bash
./vendor/bin/sail artisan test
```

TDD-first (red → green → refactor) on every change in this repo — see the
project's `tdd-first.md` convention.

## Where to look next

- **`design.md`** — the visual design system (tokens, rules, what's locked
  vs. amendable). Read before touching any color or layout primitive.
- **`deploy/README.md`** — how the production deploy actually works
  (manual build/push/deploy to `polaris2`, no CI yet) and the architecture
  decisions behind it.
