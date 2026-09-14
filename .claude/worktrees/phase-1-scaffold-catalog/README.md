# tcg-vault

A personal portfolio project for tracking Pokémon TCG card values. **Phase 1** builds the
foundation: a Laravel 13 app on Sail/Postgres with a self-contained `Catalog` module that syncs
card and set data — plus current market pricing — from the [tcgdex](https://tcgdex.dev/) API into
the local database. There is no UI yet; this phase is API-and-console only.

## Getting started

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
```

## Try it

Import an entire set (cards + current pricing) from tcgdex:

```bash
./vendor/bin/sail artisan catalog:import-set me05
```

This fetches every card in set `me05`, upserts the `sets`/`cards` rows, and stores a daily price
snapshot per card/source/variant. A single card failing (not found, transient network error, or a
malformed API response) is reported and skipped — it does not abort the rest of the import.

## Tests

```bash
./vendor/bin/sail artisan test
```
