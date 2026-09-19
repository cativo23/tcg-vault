# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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
