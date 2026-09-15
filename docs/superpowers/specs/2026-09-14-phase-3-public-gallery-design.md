# tcg-vault Phase 3 — Public Gallery — Design Spec

Status: approved by Carlos, ready for implementation planning.
Parent spec: [`2026-09-14-tcg-vault-design.md`](2026-09-14-tcg-vault-design.md)
— this document narrows that spec's §5 "Public gallery" bullet into a
buildable slice; visual system stays [`design.md`](../../design.md), not
duplicated here.

## 1. What this is

The first public-facing screens of tcg-vault: an unauthenticated visitor can
browse the sets Carlos has added at least one card from, open a set, and see
every card in it — with Carlos's actual photo where he's uploaded one,
official tcgdex art otherwise, and a visible marker on the cards he owns.

This is a deliberately narrow slice of the parent spec's public-gallery
vision. Two things that spec bundles into "public gallery" are explicitly
NOT part of this phase — see §7.

## 2. Why the URL carries `{username}`

The parent spec (§6) already names multi-tenancy as an explicit future
direction with seams the architecture shouldn't block. Nothing about
multi-user accounts, billing, or onboarding gets built now — this phase is
still genuinely single-user. But the ROUTE SHAPE is cheap to get right
today and expensive to retrofit later (every public link, every bookmark,
every share would break), so Carlos asked for it now: every public gallery
URL is scoped under a username segment from day one, even though today
exactly one username exists.

```
GET /{username}/gallery
GET /{username}/gallery/{setTcgdexId}
```

## 3. Schema change

`users` gains one column:

```php
$table->string('username')->unique();
```

Nullable-on-create is not needed — the seeder (Task 1's
`DatabaseSeeder`) generates one for the admin user deterministically from
the configured email's local part (everything before `@`), lowercased,
with non-alphanumeric characters stripped (e.g.
`cativo23.kt@gmail.com` → `cativo23kt`). No registration flow exists yet
that would need interactive username selection (`TCGVAULT_ALLOW_REGISTRATION`
defaults off per Phase 2) — when registration is ever turned on, that flow
picking/validating a real username is a problem for whoever turns it on,
not this phase.

## 4. Login accepts username or email

`LoginForm::authenticate()` (Breeze-generated) currently always attempts
against the `email` column. Change: the same input field accepts either.
Resolution rule: if the submitted value contains `@`, attempt against
`email`; otherwise attempt against `username`. Field label/placeholder
updates from "Email" to "Email or username". No new column, no new table —
this is entirely inside the existing `LoginForm` class.

## 5. Data model for the gallery itself

No new tables beyond §3. The gallery reads, never writes:

- **Set list** (`/{username}/gallery`): every `Set` that has at least one
  `Card` belonging to it that appears in the target user's `Collection`
  (i.e., sets reachable through that user's own `CollectionItem`s — a set
  Carlos has never added a card from does not appear, per §7's Option-1
  decision). For each set: `card_count` (as recorded on the `Set` row from
  Catalog sync — this is the SET's total card count, not how many Carlos
  owns), and an owned-count computed as `DISTINCT card_id` across that
  user's `CollectionItem`s whose `card.set_id` matches (distinct, because
  Task 9 already allows multiple `CollectionItem` rows for the same card
  in different conditions/variants — those must count once toward
  completion, not once per row).
- **Set detail** (`/{username}/gallery/{setTcgdexId}`): every `Card` in
  that `Set` (not just owned ones — per §7's decision, ALL of a touched
  set's cards render, so the visitor sees what's missing, just without a
  gray-placeholder treatment yet — see §7). For each card: its latest
  `CardPriceSnapshot` per source (for the price column/sort), whether the
  target user owns it (for the visual marker and art-toggle), and if
  owned, the specific `CollectionItem.photo_path` to prefer over
  `Card.official_image_url`.
- **Set stats** (header of the detail screen): total card count (from
  `Set.card_count`), most expensive card in the set (by latest snapshot
  market price, across ALL cards in the set — not just owned, matching
  the reference behavior Carlos pointed to), full-set market value (sum of
  latest snapshot market price across every card in the set — again, the
  whole set's value, not just what Carlos owns; this reflects "what it
  would cost to complete this set today," a distinct number from anything
  ownership-based).

**Price source for stats/sort:** `CardPriceSnapshot` has a `source` column
(`cardmarket` | `tcgplayer`) and a `variant` column — a card can have
multiple snapshot rows per day. For "the" price shown in a list/sort
context (not the full multi-variant breakdown a future card-detail page
might show), use the highest-priority available source, in this order:
`tcgplayer` `normal`/`holofoil` variant → `cardmarket` `default` variant →
whichever snapshot exists at all for that card. Sort/stats treat a card
with no snapshot as `null` (excluded from "most expensive," sorts to the
end on a price sort, never treated as `$0`).

## 6. Screens

### `/{username}/gallery` — set list

Grid of set cards (reuse `.nw-card`). Each shows: set logo (if
`Set.logo_url` is present, else name-only per `design.md`'s no-image
fallback rule), set name, series, and a completion bar (`owned / total`,
e.g. "3 / 102", with a slim progress bar using `--signal` for the filled
portion — the ONE place a progress fill is allowed to use the accent
color, since it's literally "how much of this is done," not a decorative
choice). No sort/filter/search on this screen — the set list is small
enough (bounded by how many sets Carlos has actually touched) that it
doesn't need them; revisit if that stops being true.

### `/{username}/gallery/{setTcgdexId}` — set detail

- **Header/stats band**: set name, series, release date, total cards,
  most expensive card (name + price), full-set market value, completion
  bar (same treatment as the list screen, larger).
- **Toolbar**: search-by-name input, sort control (Number / Name / Rarity
  / Price), rarity filter (a `<select>` populated from the distinct
  rarities actually present in this set — never a hardcoded global
  rarity list, sets vary). **Amended post-implementation, post-final-review:**
  shipped as a server-side Livewire round-trip (`wire:model.live.debounce.300ms`),
  not the client-side filter originally specced here. Accepted as a
  deliberate deviation rather than rebuilt, given this phase's usage
  budget — a set tops out at a few hundred cards, so the round-trip cost
  is real but small, and Livewire's own debounce already caps request
  frequency. Revisit if a future phase's traffic profile makes this worth
  the client-side rewrite.
- **Card grid**: `.nw-card`-styled tiles, image-forward per `design.md`'s
  "card images are the content" rule. Owned cards get a `--signal`
  border (shipped as a 2px box-shadow, not the hairline weight
  originally specced here — cosmetic difference, not re-litigated) (NOT
  a checkmark badge, NOT a second accent color — reuses the one
  sanctioned accent, consistent with `design.md`'s one-accent rule).
  **Amended post-implementation, post-final-review:** the photo-vs-official-art
  toggle described below was never built in Task 5 and is formally
  deferred, not shipped — Task 5 shows the owner's photo when present
  (`CollectionItem.photo_path`) with no way for a visitor to switch back
  to official art. The differentiator this describes (nobody else in
  this product category shows the collector's actual physical copy)
  still holds for the "shows the real photo at all" part; only the
  toggle/switch mechanic is deferred:
  ~~If Carlos uploaded his own photo for that card (`CollectionItem.photo_path`),
  show a small toggle (or default to his photo with a "view official art"
  link) to switch between his photo and tcgdex's official art~~
- Cards with no image at all (Catalog sync never got `official_image_url`
  and Carlos has no photo) render `design.md`'s specified empty-image
  state (`.imgwrap.empty` — diagonal hatch + icon + "Sin imagen"/"No
  image" + name/set/price still visible). This state is documented in
  `design.md` (from the original mockup) but has never actually been
  built in real Blade/CSS yet — none of the Phase 2 admin screens hit
  this case in practice, so this phase is the first time it needs a real
  implementation, not a reuse of existing markup.

## 7. Explicitly deferred (not this phase)

- **The photo-vs-official-art toggle.** Added here post-final-review: the
  set-detail screen shows the owner's photo when one exists, with no way
  for a visitor to switch to tcgdex's official art instead. Building the
  actual toggle (or a "view official art" link) is deferred to a follow-up
  pass, a controller decision made under this phase's usage budget rather
  than a scope call made during Task 5's own planning.
- **Browsing sets Carlos hasn't touched.** Only sets reachable through an
  existing `Collection`/`CollectionItem` appear. Browsing the full tcgdex
  catalog (every set that exists, whether Carlos owns anything from it or
  not) needs a bulk-import feature that doesn't exist yet — explicitly
  scoped out when this was discussed, revisit later.
- **Grayscale/faded placeholder art for un-owned cards within a full
  (not-yet-imported) catalog.** Carlos's own idea, explicitly flagged by
  him as "for later, not now" — depends on the bulk-import feature above
  existing first, since today every card shown on the detail screen is
  already a real synced `Card` row with real art either way.
- **"Movimientos"** (price history chart + activity feed) — the parent
  spec's other public-gallery screen. Needs the daily price-snapshot
  scheduled job (Phase 4) to have meaningful history to chart; building
  the chart now would have nothing to show.
- **Real multi-user onboarding** (registration UX, username picking/
  validation, per-user billing/limits). The `{username}` URL segment and
  `users.username` column are the only piece of multi-tenancy pulled
  forward into this phase — everything else in the parent spec's §6
  stays deferred exactly as that spec already says.
- **Per-card detail page** (a dedicated `/gallery/{set}/{card}` screen
  showing full multi-variant price history/breakdown). This phase's card
  grid is the full experience; a drill-down page is a natural future
  addition once "Movimientos"-style historical data exists to show on it.

## 8. Testing approach

Pest, Feature tests hitting the public routes directly (no `actingAs` —
these routes must work logged-out, and a regression that accidentally
added `auth` middleware here should fail loudly). Cover: a set with zero
owned cards still shows correctly (0/N completion), a set the target
user hasn't touched returns 404 (not an empty page — per §7, an
untouched set simply doesn't exist in this user's gallery), the
`{username}` segment resolving a route to the wrong/nonexistent user
404s (not an exception page), sort/filter/search each independently,
the price-source fallback chain (tcgplayer → cardmarket → null) with a
card that has only one of the two, and the login-by-username path
alongside the existing login-by-email tests (never replacing them).
