# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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
