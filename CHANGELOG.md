# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.3.1] - 2026-10-08

### Fixed

- A TCGplayer link confirmed by an admin gets a wider cardmarket bound
  (15x instead of 4x), since cardmarket often lumps a stamped promo
  (staff, Pokémon Center) in with its plain print. The check against the
  print's own recent tcgcsv price is unchanged.

### Deploy

No migrations. To confirm a held link as admin, see "tcgcsv sync" in
`deploy/README.md`.

## [1.3.0] - 2026-10-08

### Added

- tcgcsv fills TCGplayer price gaps. Prints linked to a TCGplayer product
  that tcgdex doesn't price on TCGplayer (special prints like cosmos holos)
  now get tcgcsv's market price every day, instead of only a hand-copied
  one. tcgdex's prices and hand-entered ones are never overwritten, though
  tcgcsv's price takes over from a hand-copied one on display. A price far
  off the print's recent one or its cardmarket price is held back and sent
  to Discord; after 3 days it is written unless cardmarket disagrees, with
  a Discord note. A weakly-linked print with nothing to check against is
  not filled.
  `TCGCSV_MODE=shadow` turns writing off again; any other value than `fill`
  does too.
- The pricing freshness alert watches the tcgcsv sync on its own, by its
  last complete run, and a fresh tcgcsv price no longer hides a stalled
  tcgdex sync.

### Changed

- The manual-price refusal no longer says tcgdex prices a print that only
  tcgcsv does.

### Security

- `shell-quote` (pulled in by the dev-only `concurrently`) is pinned past
  GHSA-pqg4-j6r4-53mv.

### Deploy

No migrations. Fill mode is on by default; `TCGCSV_MODE=shadow` in `.env`
plus `up -d --force-recreate horizon scheduler` rolls it back (see "tcgcsv
sync" in `deploy/README.md`). The first fills land after the 20:30 UTC run.

## [1.2.1] - 2026-10-06

### Fixed

- The queue container's memory limit rises from 320M to 448M. The tcgcsv
  worker added in 1.2.0 runs as two processes, so the container idled near
  its limit and the nightly price sync's extra worker would have pushed it
  over, stopping every queue.

### Deploy

No migrations.

## [1.2.0] - 2026-10-06

### Added

- Every price records where it came from (`tcgdex`, `tcgcsv`, or entered
  by hand) separately from the marketplace it prices, so TCGplayer prices
  can arrive through more than one feed without splitting a print's price
  history.
- tcgcsv.com shadow sync. Once a day (20:30 UTC) TCGplayer prices are
  pulled from tcgcsv — about one request per set, following tcgcsv's usage
  rules — and each print is linked to its TCGplayer product. Nothing is
  priced from it yet: each day's agreement with tcgdex's prices, and the
  prints tcgdex doesn't price at all, are logged and kept for 30 days to
  decide whether tcgcsv becomes the primary TCGplayer source. The sets to
  pull are mapped with `catalog:propose-tcgplayer-groups`.

### Changed

- The card page and the gallery name where their prices came from instead
  of crediting tcgdex for everything, and date the latest sync rather than
  the latest manual entry. A manual price says when it was entered, and a
  manual price copied from a feed says which. Each price tile has a tooltip
  with its marketplace, origin and date.

### Fixed

- A card's only foil or stamped print is offered as its own print when it
  is a separate product. Boss's Orders' Prize Pack cosmos was offered as a
  plain "Holofoil" that doesn't exist; across the catalog 94 cards now
  offer their real special print instead of a phantom base print. No
  collected copy loses its variant.
- Base prices are labelled by the prints a card really has: a foil price is
  no longer filed under a print the card doesn't have.

### Deploy

Three migrations (additive). Run `php artisan migrate --force` against the
new image before `up -d`. The horizon container's memory limit rises to
320M for the new tcgcsv worker. Afterwards, map the TCGplayer groups —
see "tcgcsv shadow sync" in `deploy/README.md`.

## [1.1.2] - 2026-10-06

### Fixed

- A manual price can be set for a print whose market price has frozen.
  The card editor refused one whenever any TCGplayer or Cardmarket price
  existed for the print, so 30th Celebration copies — whose TCGplayer
  price stopped updating on 24 September — could not be priced. It now
  refuses only when a marketplace price for that print is still current,
  judged within the same 30-day window the listings show.

### Deploy

No migrations.

## [1.1.1] - 2026-10-06

### Fixed

- A price source that stopped updating no longer outranks one updated
  today. tcgdex stopped sending TCGplayer prices for 30th Celebration, so
  those cards kept showing a TCGplayer price from 24 September — with a
  price move computed from it — over that day's Cardmarket price. A
  higher-priority price now has to be within three days of the card's
  latest sync to win; one missed sync still doesn't switch the currency.
- A normal or holo copy whose own price froze falls back to the card's
  current Cardmarket price. A copy with no price of its own, a reverse
  holo, or a special print is never priced from another print's row.
- Manual prices count as current at any age and rank after a current
  marketplace price for the same print.
- The card page marks a price older than the latest sync "as of" its date
  instead of showing a move, including the headline price when nothing
  current is left.

### Changed

- A copy priced in USD can switch to EUR on the Activity chart when its
  TCGplayer price freezes and Cardmarket takes over; the USD line then
  drops by that copy's value without anything being sold.

### Deploy

No migrations.

## [1.1.0] - 2026-10-06

### Added

- Special prints are their own variants. Poké Ball, Master Ball, Friend
  Ball and other pattern reverses, Energy reverses, cosmos holos and
  stamped promos are separate products with their own prices, and tcgdex
  lists them per card. Adding or editing a copy now offers the prints that
  card actually has, each priced on its own — a Master Ball reverse is no
  longer valued as a plain reverse at a tenth of its price. Labels read the
  way collectors say them ("Reverse Holofoil · Poké Ball"), and the
  collection's variant filter lists the special prints you own.
- Prints tcgdex doesn't list can be recorded by hand. "Other print"
  composes one from tcgdex's own foil and stamp names — a Prize Pack cosmos
  holo is Holofoil · Cosmos · Player Rewards Program — so it gets the same
  key tcgdex would give it if it adds that print later.
- Manual market prices for those prints. A price entered in the card editor
  is shared catalog data, shown to everyone like tcgdex's prices and
  labelled "Manual" on the card page. Only accounts with the new
  `manage-catalog-prices` permission can set one (super-admins pass
  automatically). It is refused for a print tcgdex already prices, and it
  stands until replaced rather than leaving the 30-day price window.

### Fixed

- Ascended Heroes cards no longer offer a plain reverse holo; that set has
  only Poké Ball and Energy pattern reverses.
- A card whose only print tcgdex files with a foil or stamp (a gold Hyper
  rare, a set-logo promo) is still offered as one print, not split into two.
- The card-level price no longer falls back to a special print's price.
- The card page and Activity name every price source instead of labelling
  anything that isn't TCGplayer as Cardmarket.
- The stalled-sync alert ignores manual prices, so saving one can't hide a
  sync that stopped.

### Deploy

No migrations. After deploying, create the new permission:

```
php artisan db:seed --class=PermissionSeeder --force
```

## [1.0.2] - 2026-10-06

### Fixed

- The nightly pricing sync no longer reports an error each time tcgdex
  briefly refuses a request (a 5xx such as 503 "no available server", a
  429, or a dropped or timed-out connection). Those requests were already
  retried and succeeded — no card went unpriced in the nights checked —
  but every failed attempt reached Bugsink. They are now retried quietly,
  with a warning in the log naming the card and the status. A card that
  still fails when the sync's three-hour window closes is reported once.

### Security

- Updated `source-map-js` to 1.2.2 (GHSA-68fv-2mgg-jv7q), a build-time
  dependency; the built assets are unchanged.

### Deploy

No migrations.

## [1.0.1] - 2026-10-05

### Fixed

- The public gallery is much faster. The collection page took 5–6 s and
  Activity 15–17 s in production; nearly all of it was PHP work over each
  card's full price history, not the database. Listings now load only the
  last 30 days of prices (the card page charts the full history), the
  collection page loads its cards once instead of twice, Activity's value
  chart prices every day in one pass, and Telescope no longer watches every
  loaded model in production. Before this, every screen got slower each day
  as the nightly sync added prices.
- A card is priced from the same 30-day window everywhere, so its page can
  no longer show a different price or currency than its tile. A card with
  no price in that window says so instead of claiming it was never priced.
- Sorting the collection by number while searching returned an error page.

### Changed

- A card with no price in the last 30 days counts as unpriced everywhere:
  on its tile and card page (no price chart either), in collection and set
  totals, on Activity, in the admin collection table, and in the CSV
  export.
- The site now needs Safari 16.4+, Chrome 111+ or Firefox 128+ (Tailwind
  CSS 4).

### Security

- Updated `league/commonmark` to 2.10.3 (GHSA-3q6v-r5mr-hxv8,
  GHSA-97jj-33gv-5xf9) and moved to Tailwind CSS 4, which drops the
  vulnerable `braces` build dependency (GHSA-vfj7-8cjw-p6xm).

### Deploy

No migrations.

## [1.0.0] - 2026-09-29

The wide private beta: registration stays invite-only and invites go out
in waves. No code changes from 0.13.0; this release marks the go/no-go
in `ROADMAP.md` as passed.

### Deploy

No migrations.

## [0.13.0] - 2026-09-29

### Fixed

- The invite-email limits (3 an hour per address, 50 a day per staff
  account) could be beaten by sending requests in parallel: 10 at once let
  7 or 8 emails through. Both limits are now checked and counted under one
  lock, and a slot from an email that failed to queue is only given back
  to the window it came from, never to an expired or newer one. A `+tag`
  or Gmail dots no longer give the same inbox a separate budget.
- Two accounts could share one inbox when their emails differed only in
  capitals. Accounts are stored lowercase and the database refuses a
  second spelling. New invites are stored lowercase too; existing ones
  keep their spelling so links already sent still work, but the check for
  a pending invite ignores case.
- Signing in and resetting a password now work whatever case the email
  (or username) is typed in.

### Changed

- Invite addresses must be plain ASCII: accented addresses and quoted
  local parts are refused when creating an invite.
- Resending invites is limited to 20 a minute per staff account, like
  creating them.
- On the `database` cache store, cache locks use their own
  `pgsql_locks` connection, so a held lock can't abort a transaction.
  Production uses Redis, where this is unused.

### Deploy

Two migrations: run `php artisan migrate --force` against the new image
before the deploy's `up -d`.

- `users`: lowercases emails and drops reset links stored under a
  capitalised address (the member asks for a new one). If two accounts
  already share an address it stops and lists their user ids.
- `invites`: if several pending invites differ only in case, it keeps
  one per address and revokes the rest; rolling back does not restore
  them.

Prod had no such accounts, invites or reset links (checked before release).

## [0.12.0] - 2026-09-29

### Added

- Creating an invite now emails the link to the invited address, with who
  invited them and when the link expires. Each pending invite has a Resend
  button. An invite revoked, used or deleted before its email goes out is
  not sent.
- Invite emails are limited to 3 an hour per address (creating and
  resending together) and 50 a day per staff account.

### Changed

- Every email (invites, password resets, feedback) uses one tcg-vault
  layout. Nothing in it loads from another server.
- The privacy policy says invites are emailed, and that the email provider
  keeps a copy of sent emails for 30 days.

### Deploy

No migrations.

## [0.11.0] - 2026-09-28

### Added

- Nightly backups: a `backup` container dumps the database and archives the
  collection photos into `~/deploy/tcg-vault/backups/` every night after
  03:00 UTC, keeping each copy for just under 14 days. The copies stay on
  the same server, so they cover mistakes and a corrupt volume, not losing
  the server.
- `backups:check-freshness` (daily at 06:00 UTC) alerts Discord if either
  backup is missing or more than 26 h old, or if the disk has under 5 GB
  free.
- Restore steps in `deploy/README.md`, including deleting again any account
  deleted after the backup was taken.

### Changed

- The privacy policy states the 14-day backup period, and that deleted
  accounts leave the backups within it.

### Deploy

No migrations. `deploy.yml` now also copies `backup/backup.sh` and starts
the `backup` service; check the first backup was written and restore it
into a scratch database once (`deploy/README.md`, "Check a backup").

## [0.10.1] - 2026-09-28

### Fixed

- Cards tcgdex's API sends without an image (all MEP Black Star Promos,
  some SVP promos) now show it: the app finds the file on tcgdex's asset
  server at its usual address and saves it only once it's confirmed to
  exist. A stored image is no longer cleared on a night the check fails.

### Deploy

Run `php artisan catalog:backfill-images` once to fill in cards synced
before this release.

## [0.10.0] - 2026-09-26

### Added

- A staff Members page (`/staff/members`, `manage-members` permission) to
  suspend or delete accounts. Suspending is reversible: the member can't
  sign in, an open session ends on its next request, and their public page
  is hidden. Deleting removes the account, collection and photos after the
  member's username is typed. Nobody can act on their own account, a
  super-admin, or (unless super-admin) another staff member. Each action is
  recorded, by account number only, for a year.

### Changed

- Fonts are now served by the app itself instead of Google Fonts.
- Uploaded photos have their location and other embedded details removed
  (rotation and colour profile are kept). A photo that can't be processed
  is refused rather than stored as uploaded, and photo processing is
  limited per user per minute.
- Photos waiting to be saved are kept on private storage, not the public
  disk.
- Invites nobody accepted are deleted within 31 days of expiring or being
  revoked, and expired password reset tokens are cleared daily.

### Fixed

- Typed passwords are masked before a request reaches Telescope or an error
  report reaches Bugsink, including Livewire payloads, breadcrumbs and
  stack-frame arguments.
- Deleting another member's account removes their photos too.

### Deploy

Run `php artisan migrate --force` (`users.suspended_at`,
`moderation_actions`), `php artisan db:seed` (the `manage-members`
permission), and once, `php artisan photos:strip-metadata` to clean photos
stored before this release.

## [0.9.0] - 2026-09-26

### Added

- Static analysis in CI: Larastan at level 8 with no baseline, run on
  every push and pull request.
- A dependency audit in CI (`composer audit` and `npm audit`), failing on
  high and critical advisories.
- The production image is now built on every pull request (without
  pushing), so a broken Dockerfile fails review instead of a deploy.

### Fixed

- The CSV export now fails with an error if its output stream can't be
  opened, instead of downloading an empty file.
- Prices fall back to a plain amount if the currency formatter fails,
  instead of rendering empty.

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
