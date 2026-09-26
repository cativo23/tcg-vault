# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.8.1] - 2026-09-26

### Fixed

- Email could not be sent in production: the `resend` mail transport's
  SDK (`resend/resend-php`) was never installed, so the feedback form
  errored on submit and password-reset emails failed. A test now builds
  the Resend mailer so a missing SDK fails CI instead of production.


## [0.8.0] - 2026-09-26

### Added

- A live example collection, linked from the landing page: a dedicated
  `demo` account holding twelve modern chase cards at real, daily-refreshed
  prices, seeded by `php artisan demo:seed-gallery`. The link only shows
  once that collection exists, is public and has cards.
- CSV export of the whole collection from My Collection — every item with
  its variant, condition, grade, quantity, notes and current market price.
- An in-app feedback form for beta members ("Feedback" in the top bar),
  emailed to the owner with the member's username and page attached.
- Privacy policy and terms of use, linked from every public page and from
  signup, where creating an account now means accepting them.
- A neutral age screen at signup: birth month and year (never stored),
  under-13s refused, and a guardian checkbox for 13–17.

### Fixed

- Account deletion failed for anyone who registered through an invite
  (and for staff who had created or revoked one). The accepted invite is
  now deleted with the account; invites someone created or revoked stay.
- Deleting an account now also deletes its card photos from disk; they
  previously stayed reachable by URL.
- The account-deletion copy now says exactly what is removed, and links
  to the CSV export.
- The collection's visibility control is now a labelled Private/Public
  switch with a line saying who can see it; the landing FAQ no longer
  points to the wrong page for it.


## [0.7.0] - 2026-09-26

### Fixed

- Accessibility: the card variant editor is now a real dialog —
  `role="dialog"`, `aria-modal`, a label, focus moved in on open, Tab
  trapped inside, and focus returned to the row's Edit button on close.
- Accessibility: gallery, per-set gallery, collection, and card-add
  searches now announce their result count to screen readers. The
  card-add search is debounced so it doesn't announce every keystroke,
  and a failed search no longer reads as "0 results".
- Accessibility: icon-only buttons have accessible names — the variant
  editor's remove/cancel/confirm buttons (now naming which row), and the
  admin navigation's mobile menu toggle.
- Errors: the 404 page no longer sends visitors to a login form. Guests
  get "Go home"; signed-in collectors get "Back to your collection".
- Import: the TCGplayer import preview runs a fixed number of queries
  instead of two per parsed line.

## [0.6.1] - 2026-09-26

### Fixed

- Collection: two near-simultaneous adds of the same card identity
  (e.g. a double-clicked save button) could both miss an existing row
  and both insert, silently splitting one quantity across two rows. A
  Postgres unique index on the identity tuple now closes the race;
  `CollectionService::addItemForCard()` catches the violation and
  merges into the winning row instead of erroring. Also normalizes `''`
  vs `null` for variant/grade fields (Livewire skips
  `ConvertEmptyStringsToNull`) and guards the save button against a
  double-click.

## [0.6.0] - 2026-09-25

### Added

- Ops: self-hosted error tracking via Bugsink (Sentry-protocol-compatible),
  reached internally over `space-server_web` — an exception never needs
  to leave the host network or clear the dashboard's VPN gate to get
  reported.
- Ops: `storage/logs` persists on a named volume across deploys instead
  of disappearing with the container's writable layer, on a `daily`
  channel with 14-day retention and file locking (three processes write
  the same file).
- Ops: `telescope:prune` runs daily with 48h retention — Telescope's
  `database` driver had none of its own.

### Fixed

- Ops: Horizon's `balance: auto` was starving the `default` queue to a
  single worker every night regardless of Redis health, causing a
  recurring `MaxAttemptsExceededException` backlog independent of the
  2026-09-24/25 Redis incident. Fixed with `balance: 'off'`,
  `retryUntil()` 1h → 3h, and the horizon container's memory limit
  192M → 256M.

### Docs

- `deploy/README.md`'s acceptance checklist now explicitly checks
  `APP_DEBUG=false`/`SESSION_SECURE_COOKIE=true` on the server's real
  `.env`, not just the template.

## [0.5.1] - 2026-09-25

### Fixed

- Ops: Redis now persists queued jobs to disk (`appendonly yes`,
  `appendfsync everysec`) instead of holding them only in RAM — a
  container restart no longer silently drops whatever Horizon hasn't
  picked up yet. Memory limit raised 256M → 384M for the rewrite
  headroom this needs.

## [0.5.0] - 2026-09-25

### Added

- Ops: a Discord alert now fires if the daily pricing sync's dispatch
  command errors outright, or if the newest price snapshot is older
  than expected — the exact silent-stall failure mode from the
  2026-09-24/25 incident below, which went unnoticed for 30 hours
  because nothing was watching for it.

## [0.4.1] - 2026-09-25

### Fixed

- `redis` and `scheduler`'s Docker memory limits (64M each) were too
  tight for the current catalog size — `scheduler` was OOM-killed
  mid-dispatch of the daily pricing sync, and `redis` crash-looped
  under its own memory pressure, stalling that sync silently for
  ~30 hours. Raised to 256M and 128M respectively.
- Horizon's failed-job telemetry was kept for a full week by default;
  a single day's worth of it was enough to outgrow the queue container's
  memory and take the daily pricing sync down with it. Trimmed to 48h.

## [0.4.0] - 2026-09-24

### Added

- Admin: "Manage collection" now groups by card instead of by individual
  variant — one row per Pokémon with its owned variants shown as chips,
  instead of a repeated row per variant/condition combo.
- Admin: editing a card opens one modal listing every variant you own as
  an editable row, with autosave per field and instant add/remove.
- Admin: adding a new card lets you add several variants in one
  submission (e.g. 2 normal + 1 holofoil from one booster) instead of
  repeating the search per variant.

### Fixed

- Admin: a stale `condition` validation rule, two missing variant-selection
  fallbacks, and dead per-item delete code left over from an earlier pass
  at this feature.
- Public gallery: a CSS class collision from the admin rewrite was
  regressing the set-rail chip's spacing and border.
- A card's total collection value could render EUR pricing labeled with a
  `$` sign; now shows the correct currency, or "Mixed currencies" when a
  card's variants don't share one.

## [0.3.1] - 2026-09-23

### Fixed

- Activity: an added card is now priced as the variant that copy actually
  is. A reverse-holofoil card was showing its normal print's price — a
  Pitch Black Tropius read $0.05 where the reverse holo is worth $0.22.
- Activity: the value chart counts each copy at its own variant's price
  instead of pricing every copy as the normal print. One normal plus one
  reverse-holofoil copy charted as two normals, so the chart could end on
  a different figure than the collection total printed right above it.
- Activity: a price movement now follows the variant you own, rather than
  reporting the normal print's trend to someone who only has the reverse
  holo.

## [0.3.0] - 2026-09-23

### Added

- Gallery & card detail charts: hovering (or dragging a finger on touch)
  now shows the exact date and value at the nearest point, with a guide
  line and gridlines for scale — instead of only the start/end values.

## [0.2.0] - 2026-09-19

### Added

- Gallery: the Pokémon type now shows as a color dot next to the card name.
- Theme: a subtle film-grain overlay across the whole site.

### Fixed

- Mobile header no longer overflows the viewport — "Manage collection"
  moved into the mobile nav panel instead of fighting for space on the
  fixed top row.
- Mobile gallery grid columns now render at equal width.
- Manage Collection's table is usable on mobile — rows collapse into
  cards instead of clipping Edit/Delete off-screen.

## [0.1.0] - 2026-09-19

### Added

- CI/CD: automated test suite on every push/PR, and a release-branch deploy
  pipeline to polaris2.
