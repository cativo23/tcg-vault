# Roadmap

What is left to do, ordered by **value for the effort**: quick, valuable work
first, big projects after, polish last. At equal effort, anything that risks
losing or corrupting data goes before anything a visitor merely sees.

Each item carries its effort and value:

- **Effort** — `S` under half a day with tests · `M` one to two days · `L` more.
- **Value** — `high` breaks something or misprices cards · `med` a real
  improvement · `low` polish.

A finished item leaves this file in the same PR that closes it; what shipped
is in [`CHANGELOG.md`](CHANGELOG.md). Status lines below were checked against
the code on 2026-11-11; anything marked *(prod)* needs a look at production
before acting.

## Where things stand

- **v1.7.0 is live** (2026-11-05): every public page is on the binder design.
  `master` also carries #171, #172 and #174, unreleased; the two compose
  changes in them (PHP `memory_limit` 192M, `app` limit 384M, 4 FPM workers)
  were applied by hand on the host, and #172's page fixes wait for a release.
- **Invite-only private beta** since v1.0.0 (2026-09-29). Registration is
  closed; invites go out in waves.
- **Prices:** TCGplayer (USD) from tcgdex and from tcgcsv, which fills any
  linked print tcgdex leaves unpriced or stale; Cardmarket (EUR) from tcgdex
  as the fallback. tcgdex's TCGplayer prices were frozen from 2026-10-14 to
  2026-11-03 and are fresh again; tcgcsv now fills only the gaps.
- **Backups** run nightly and are kept 14 days, on the same host (accepted
  risk, see Known limits).
- **Upstream:** tcgdex v2.49.0 (2026-11-07) is the first release carrying the
  four merged data fixes (MEP and SVP group ids, 30th Celebration, 30th
  Classic Collection).

## Now — quick and valuable

1. **Bound the collection page's price read.** `S–M · high` — `/admin`
   (`CollectionItems`) eager-loads 30 days of every snapshot of every owned
   card, `raw` included, and sorts them in PHP; at 128M it showed the 500
   page, and 192M only delays it. Load only the deciding rows, as the public
   pages already do (`DecidingPriceRows::loadOnto`); `SetGrid` has the same
   pattern. Note its cache key is per card-id set, so a search keystroke
   writes a new Redis entry while Redis has no `maxmemory`.
2. **Fix the flaky `CollectionValueSeriesTest`.** `S · med` — "warming sums the
   totals over card chunks…" fails now and then on CI, and a red `test`
   check blocks a release. Suspect: `PublicCollection::cardsQuery()` has no
   `ORDER BY`, so the first-seen currency can differ between the warm run and
   the page. Try `orderBy('cards.id')`; unconfirmed.
3. **Release v1.7.1.** `S · med` — CHANGELOG entries for #171, #172, #174 and
   items 1–2, so the page fixes reach production and the repo's compose file
   stops differing from the release it ships with.
4. **One production check pass.** `S · med` *(prod)* — read-only, after a
   nightly run:
   - tcgdex v2.49.0: the links and prices of `mep`, `30th` and `30th-c` did
     not change, and the images and prices tcgcsv fills agree with tcgdex's.
   - `sv10.5w` (mapped by hand 2026-11-05) has its links and prices.
   - The held-back list (`gaps_implausible` in the last run summary): the
     2026-10-22 list predates v1.6.16, which removed the Cardmarket check
     that held most of them.
   - The `WarmValueSeriesJob` row in `failed_jobs` (attempt limit, cause never
     looked at; possibly a worker killed by a deploy).
5. **A command to link a print to a TCGplayer product.** `S · med-high` —
   today every admin link is a tinker one-liner. A `catalog:link-tcgplayer`
   command (card, variant, product, subtype, group; validated against the
   group's products) makes the open link gaps routine:
   - ten MEP cards with no link and no image (`mep-055`–`mep-063`,
     `mep-Museum`);
   - about 26 deck-exclusive prints tcgdex gives no TCGplayer id;
   - every stamped print a collector adds (Prize Pack, Pokéween), which
     never links itself.
6. **Say where each price came from in the CSV export.** `S · med` — add a
   `price_source` column (`CollectionCsvExporter::HEADER` has none). Then
   delete the hand-entered rows tcgcsv now covers (10 rows over 7 prints) —
   a production write, with a yes.
7. **Small hygiene.** `S · low` — `composer.json` says `"php": "^8.3"` while
   the lock needs 8.4 (Pest 5, PHPUnit 13) and every runtime is 8.4; delete
   the orphaned Breeze pages (verify-email, confirm-password, `dashboard`,
   `x-application-logo`) and the unused styles and tokens listed at the end
   of `design.md`.

## Next — medium projects

8. **Write the identity matcher's links.** `M · med-high` *(prod)* — the
   matcher has run in log mode since v1.6.12 and agreed with every existing
   link (6,916 agree, 0 different, 9 new on 2026-10-26). Turning it on needs
   code, not just config: `TCGCSV_IDENTITY_LINKS` knows only `log` and off,
   and `CardTcgplayerLink::TRUST` has no identity level. Roll out with a yes
   after two matching builds. Plan: `.superpowers/sdd/2026-10-23-price-link-redesign/FINAL.md`.
9. **Filter the collection by card type.** `M · med` — selling a batch of
   Trainers means finding them first. Pokémon (Basic, Stage 1, Stage 2),
   Trainer (Supporter, Item, Tool, Stadium), Energy (Basic, Special),
   combinable with the set and price filters. The stored tcgdex payload
   (`raw`) has `category` on every card, `trainerType` on modern trainers
   (null on some old ones) and `energyType` on energy, so a first version
   can read the payload without a migration. Needs a ruling on BREAK, V,
   VMAX and ex. *(prod: count cards with no `category`.)*
10. **Integrity constraints on prices and links.** `M · med` *(prod)* —
    CHECKs on source/origin/currency/method, `UNIQUE(product_id, sub_type)`
    on links, `group_id NOT NULL`; no variant-key CHECK (it would break the
    nightly sync). Needs the `30th-c-007` cleanup first, with a yes. Same
    plan file as item 8.

## Later — big projects

11. **USD only: stop using Cardmarket.** `L · high` *(prod)* — Cardmarket's
    figure is EUR and often another print's. Stages: stop reading and writing
    it together (S2+S4, which also stops storing Cardmarket `raw`), resolver
    staleness by `source_updated_at`, the remaining read paths (S2b, S2c),
    the rename (S3), at least 7 days, then purge (S5a) and a CHECK (S5b),
    both irreversible, and the copy that still promises a euro price
    (`home.blade.php`, `terms.blade.php`, README) last. On 2026-10-28 about
    344 prints and 17 cards would have been left with no market price, so
    re-count first. Plan and red team: `.superpowers/sdd/2026-10-28-remove-cardmarket/`.
12. **Wishlist / want-list.** `L · med-high` — the feature collectors are most
    likely to ask for; nothing exists yet.
13. **Redesign the owner and staff pages.** `L · med` — `/admin`, `/staff`,
    auth, legal and error pages still use `app.css` and the old primitives
    (inventory: `.superpowers/sdd/2026-11-05-admin-revamp/INVENTORY.md`).
    Deleting the retired `--chrome-*` tokens needs a plan for the legal,
    auth and error pages too, not only `/admin`.
14. **Multiple collections, deck lists, trade and sale tracking, usage
    analytics.** `L each · low-med` — in that order of likely demand; none
    exists.
15. **Public beta prerequisites.** `L · low until registration opens` —
    mandatory email verification (`User` does not implement
    `MustVerifyEmail`, and invite signup marks the address verified), signup
    abuse controls, uploaded-photo moderation, terms with real teeth.
16. **Larastan level 9.** `L · low` — most of what level 9 adds is
    `TcgdexCardCatalogProvider` reading tcgdex's JSON as `mixed`. The fix is
    a parsing layer that validates each field before building
    `CardDetailData` / `PriceEntryData`, with its own tests; then raise
    `phpstan.neon`.
17. **Copy the backups off the host.** `M · med-high`, last by choice — losing
    polaris2 loses the backups too. Cloudflare R2's free tier needs billing
    enabled on the Cloudflare account first.

## Polish — when the code is touched anyway

- **Revamp leftovers.** (1) Unify `PriceChart` and `ValueChart` (give
  `PriceChart::plot()` a `maxStep`, delete `ValueChart`) and fold the
  copy-link and chart-slider code duplicated in `card.js`, `collection.js` and
  `activity.js` into shared modules, aligning `stepReading` / `readingStep`
  argument order. (2) After a mouse sweep over a chart, the next arrow key
  steps from the day under the pointer while the slider still announces the
  last keyboard value. (3) A manual price's step line stops at its first and
  last entry instead of reaching the range edges. (4) Activity reads the
  first 500 cards, the collection page 600. (5) A card page strip with fewer
  than three tiles renders slightly smaller than the mockup.
- **Redis.** Give the cache its own instance (a cache wants eviction, a queue
  must never evict), then set `maxmemory`; consider one shared Redis for the
  host's projects instead of a second one-off here.
- **Postgres and PHP-FPM tuning.** `pg_stat_statements`, `track_io_timing`
  and `work_mem` in one restart; an FPM `max_requests`; `srcset` images.
- **Slimmer CI/CD.** A feature plus its release runs the full suite four
  times (PR and push to `master`, twice). Wanted later, after a short design
  talk. Constraints: branch protection requires `audit`, `test` and `image`,
  so a workflow-level `paths-ignore` would leave them pending and block the
  merge, and `auto-release.yml` waits up to 15 min for `test` on the merge
  commit. Safe levers: job-level conditions (skip `image` when nothing it
  builds changed), no PR run for `release/*`, a warm npm cache for new
  branches, and a retry on the `image` job's `composer install` (it failed
  once on GitHub 504s).

## Waiting on something

- **The Activity chart in the nightly window** — measure it between 00:00 and
  01:00 UTC with real traffic, and watch the "activity chart built on a page
  view" warnings. Build the precomputed per-print table only if one trigger
  fires: more than 3 page-view builds a week for two weeks, one over 10 s for
  a non-owner, ten or more public users, a warm run over 200 s, or a second
  use for per-print daily values.
- **Automatic links for stamped prints** (Prize Pack group 22880 by name and
  number) — postponed by the owner; item 5 covers it by hand.
- **An admin link editor in the UI** — only if item 5's command proves too
  slow.
- **A purchase price per copy** — parked by the owner.
- **Why tcgdex sends no TCGplayer price for about half its cards each night**
  — unanswered upstream.

## Known limits (accepted)

- Backups live on the same host as the data. They cover a bad migration, a
  wrong delete or a corrupt volume, not losing polaris2 (item 17).
- A copy saved without a variant on a deck-exclusive card shows the non-holo
  price: the resolver prefers TCGplayer `normal` to `holofoil`.
- A new set whose abbreviation differs between tcgdex and tcgcsv (all SWSH
  sets so far) needs its TCGplayer group mapped by hand
  (`catalog:propose-tcgplayer-groups --set=… --group=…`); the nightly
  coverage report names any set left unmapped.

## Not on the roadmap

- A public write API. The app is server-rendered Livewire with no API
  surface, and adding one before the data model settles would freeze it
  early.
- Native mobile apps. The gallery is responsive; a wrapper would add a
  release process without adding a capability.
