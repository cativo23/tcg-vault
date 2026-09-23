# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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
