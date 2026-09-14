# tcg-vault — Design Spec

Status: approved by Carlos, ready for implementation planning.
Visual system: see [`design.md`](../../design.md) at the project root — this
document does not duplicate it, only references it.

## 1. What this is

A personal Pokémon TCG card-collection tracker for Carlos: an admin panel to
catalog his physical cards, and a public gallery showing the collection with
live market value (current + historical). Portfolio piece, deployed on his
own infrastructure (polaris2).

**Explicit non-goal for this milestone:** a multi-tenant marketplace/SaaS.
That's a real future direction (§6) the architecture must not block, but
nothing in that direction gets built now.

## 2. Architecture

**Laravel 13 modular monolith, Livewire 4, single deploy.**

Chosen after two rounds of adversarial comparison: a first architecture-only
pass recommended Laravel+Inertia/React; a second, deliberately bias-checked
pass scored 13 candidate stacks (Rails+Hotwire, Phoenix+LiveView, Next.js,
NestJS+SPA, Go, Django, etc.) against explicit weighted criteria with
familiarity excluded as a scoring factor. Laravel+Livewire and Rails+Hotwire
tied at the top (67.5/77.5); Laravel won the real tiebreaker — Carlos's own
shipping speed on a stack he already knows fluently, which the abstract
scoring deliberately didn't capture. Livewire beat Inertia/React specifically
because Inertia SSR would need an extra Node container for future public SEO,
which server-rendered Livewire gets for free.

### Modules (plain PSR-4 folders under `app/Modules/`, not a package)

| Module | Now? | Owns | The seam |
|---|---|---|---|
| **Catalog** | Yes | sets, cards, price snapshots, tcgdex sync | **Global, never tenant-scoped.** All tcgdex access behind one `CardCatalogProvider` interface. |
| **Collection** | Yes | collections, collection_items (condition, grade, qty, notes, photos) | **Tenant-scoped.** Every write goes through a service and emits a domain event, even with zero listeners today. |
| **Valuation** | Yes | current value + history series (read-only) | Reads Catalog + Collection, writes nothing. |
| **Identity** | Yes (thin) | users, auth | One user today. The thing that stops being hardcoded later. |
| **Marketplace** | No — reserved | — | Don't put `for_sale`/`asking_price` on `collection_items`. When it arrives, it's a `listings` table referencing items. |
| **Billing** | No — reserved | — | No `plan`/`stripe_id` on `users` now. Everything gates through one `Entitlements` service that returns `true` for everything until billing exists. |

**Rules to hold from commit one:**
1. No cross-module Eloquent relationships or direct model access — modules talk through service classes + DTOs (`spatie/laravel-data`) only.
2. Every tenant-scoped query goes through a global Eloquent scope, never a hand-written `where('user_id', ...)`.
3. tcgdex only behind `CardCatalogProvider`; raw payload cached as JSONB so a schema change on their end never forces a refetch.
4. Money as integer minor units + explicit currency, always — tcgdex returns both EUR (Cardmarket) and USD (TCGplayer) in the same response.

## 3. Data source — tcgdex.dev

Free, no auth required, catalog + pricing in one response. **pokemontcg.io was
evaluated and rejected**: confirmed deprecated (screenshot evidence), no new
registrations, existing keys die 2027-03-01.

Two facts verified live against the real API that shape the design:
- **No bulk pricing endpoint.** `GET /v2/en/sets/{id}` returns cards without
  pricing; GraphQL rejects a `pricing` field outright. The daily snapshot is
  necessarily N per-card REST calls — this is why it's a queued job with
  retry/backoff, not a single cron script.
- **Image URLs require the zero-padded `localId`** exactly as the API
  returns it (`https://assets.tcgdex.net/en/{series}/{setId}/{localId}/high.webp`,
  e.g. `007` not `7` — the unpadded form 404s). This bit the brainstorm
  mockups once; the ingestion code must use the API's own `localId` string,
  never a coerced integer.

## 4. Data model

The core modeling decision: **catalog data is global, collection data is
tenant-scoped** — getting this split right now is what makes multi-tenancy a
feature addition later instead of a rewrite.

```
-- GLOBAL / catalog (never tenant-scoped)
sets(id pk, tcgdex_id uniq, name, series, released_on, card_count, logo_url)
cards(id pk, tcgdex_id uniq, set_id fk, local_id, name, rarity,
      variants jsonb, official_image_url, raw jsonb, synced_at)
card_price_snapshots(
      card_id fk, source enum('cardmarket','tcgplayer'), variant text,
      captured_on date, currency char(3),
      market_minor bigint, low_minor bigint, trend_minor bigint,
      raw jsonb, source_updated_at timestamptz,
      PRIMARY KEY (card_id, source, variant, captured_on))

-- TENANT-SCOPED
users(id pk, ...)
collections(id pk, user_id fk, name, slug, is_public bool)
collection_items(
      id pk, collection_id fk,
      card_id fk,            -- local FK: integrity now
      card_tcgdex_id text,   -- denormalised: the future extraction seam
      variant, condition, grade_company, grade_value,
      quantity, notes, photo_path, created_at)
```

Four deliberate choices carried over from the architecture review:
1. Money stored as `bigint` minor units + explicit `currency` — never float.
2. `variant` is part of the price snapshot's primary key — the live payload
   proved prices diverge sharply by variant (normal vs. reverse-holofoil).
3. `raw jsonb` on both `cards` and `card_price_snapshots` — cheap insurance
   against a tcgdex field addition forcing a refetch.
4. `card_tcgdex_id` denormalized onto `collection_items` — ~20 bytes, and
   the exact thing that survives if Catalog is ever extracted to its own
   service.

Custom folders/albums (Carlos's "todos mis Pikachus" idea) are an explicit
**beta/extra** on top of `collections` — not required for MVP, flagged as a
plausible future paid-tier feature (§6), not built now.

## 5. MVP feature scope

**Admin** (single-user login, behind auth):
- Search tcgdex by name/set to add a card — autocomplete, not OCR/image
  recognition (evaluated and explicitly rejected: adds real complexity for
  marginal gain over search-and-pick, and a photo-scan approach was
  reconsidered once pokemontcg.io turned out deprecated and price accuracy
  became the priority over "wow" input UX).
- Personal photo upload, optional — falls back to tcgdex's official image
  if none is provided. Every card must still render name/set/price with
  **no image at all** if both are missing (see `design.md`'s fallback state).
- Condition/grade, quantity, free-text notes. **No purchase-price /
  profit tracking** — explicitly descoped.

**Public gallery** (read-only, unauthenticated):
- Browsable/filterable/searchable catalog, organized primarily by official
  tcgdex set (the "Sets" screen).
- Current value per card + collection total, via the daily tcgdex snapshot.
- Historical value chart + activity feed (the "Movimientos" screen) — price
  changes since the last snapshot with a relevant delta, plus a log of
  cards added.

**Value tracking:** current + historical (not current-only) — Carlos asked
for the chart explicitly, which is why the daily snapshot job and the
`card_price_snapshots` table exist at all; a current-only MVP would not have
needed either.

## 6. Explicit future direction (not built now, architecture must not block it)

Carlos's own framing, kept close to his words: a "Facebook Marketplace for
TCG collectors" —

- Other collectors get accounts and their own public collection/showcase.
- **Freemium**: free tier capped at some card count with full functionality;
  paid subscription unlocks more cards and/or features (custom folders are a
  plausible gated feature here).
- **Marketplace 2.0**: sellers mark items "for sale" with a *suggested*
  price (negotiable) pulled from Catalog data, buyers can reserve, in-person
  transaction gets marked sold afterward. **Explicitly browse/listing only —
  no in-platform payments, no escrow.** Coordination happens off-platform
  (social media, messaging), same as existing Facebook-group card selling,
  but structured and searchable instead of a wall of "vendo" posts.
- Logged-in buyers can leave reviews after a transaction — the community
  trust layer Carlos specifically wants, in contrast to unmoderated Facebook
  groups.

This is why Catalog/Collection are split now, why `Marketplace` and
`Billing` are named-but-empty module boundaries, and why `collections`
already models multiple named collections per user rather than one implicit
bag of cards.

## 7. Visual design

Locked in [`design.md`](../../design.md) after an extensive comparative
brainstorm (nightwire/cyberpunk, a luxury-vault direction, an auction-ledger
text-only layout, and ~6 font pairings were tried and explicitly rejected
along the way — see that file's header for the full rejected list, kept so
nobody re-proposes them).

Summary: bone-paper background, ink-black chrome, **one** signal-green
accent (means "price up," nothing else), a single variable-width type family
(Archivo) doing both display and body duty, Martian Mono reserved strictly
for numerals. Three screens are mocked and approved: **Colección** (the
card grid dashboard), **Sets** (progress-per-set with tcgdex logos), and
**Movimientos** (value chart + activity feed).

**Language:** UI copy ships in **English first**. The approved mockups are
in Spanish because that was faster to iterate on live with Carlos — that is
a brainstorming artifact, not a product decision. Translate once, after the
design is fully locked, not incrementally.

## 8. Deploy

polaris2 (Hetzner VPS, Docker Compose + Traefik, verified live: 41
containers running, ~3.8GB RAM free of 7.7GB, 1.2GB already in swap — RAM
budget is a real constraint, not a nice-to-have).

- `deploy/tcg-vault/docker-compose.yml`, following the existing
  `deploy/portfolio-api` pattern: app + queue worker + scheduler containers
  (FrankenPHP, not separate nginx+FPM), Postgres 17, Redis, all with
  `deploy.resources` limits set explicitly (most existing apps on the box
  don't set them — this one should, given the box is already swapping).
- Networking: app joins the external `space-server_web` Traefik network;
  Postgres/Redis sit only on a private `tcg-vault-internal` network, no
  host ports published.
- Subdomain: `vault.cativo.dev`. Brand name inside the app: **tcg-vault**.
- Repo: **public** (portfolio piece) — admin password via env var/secret,
  never hardcoded, standard secret hygiene applies.
- **Backups are an explicit MVP requirement, not a v2 nice-to-have.**
  polaris2 has no backup mechanism today; the daily price-snapshot history
  is the one dataset here that tcgdex itself cannot re-supply (it only
  serves current prices). A nightly `pg_dump` into the box's planned
  restic → Storage Box flow ships with v1.

## 9. Explicitly out of scope for this milestone

- Multi-tenancy, other user accounts, marketplace listings, billing/Stripe,
  reviews — all of §6, deliberately deferred, seams only.
- In-platform payments or escrow of any kind.
- Purchase-price / profit-and-loss tracking.
- OCR or image-based card recognition.
- Inertia SSR / any public-SEO-specific infrastructure (the seam is a config
  flag + one container away, not needed until the gallery is multi-tenant).
- Custom folders/albums beyond the default per-user collection (beta-flagged
  future feature, not MVP).
