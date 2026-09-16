# UX/Product Audit — Action Plan

Source: full UX/product-design audit run 2026-09-16 against `localhost:8090`
(public gallery + admin) by a browser-driving agent, prompt designed via the
`prompt-engineer` skill. Full findings live in that session's transcript —
this file tracks what we act on, in what order, and current status.

Work each item **one at a time**, TDD-first (`tdd-first.md`), via a
dedicated agent per item: `investigation-agent` first if the affected code
isn't already well understood, then implement + test, then a live browser
check on `localhost:8090` before moving to the next item. Deploy to
production only after Carlos confirms an item live locally.

## Design-system constraint (read before touching colors)

`design.md` locks this app to **one accent color** (`--signal` green =
"price up"), deliberately, after a long comparative brainstorm that
rejected multiple color directions. Any fix below that touches color
(#8) must work WITHIN that constraint or get Carlos's explicit sign-off
to amend `design.md` first — don't add a second accent hue as a side
effect of an unrelated fix.

## Status legend
🔲 not started · 🟡 in progress · ✅ done locally · 🚀 deployed to prod

**Item #1 is ✅ done locally** (see below) — Carlos paused before starting #2
for the night. **Resume at #2.**

---

## 1. ✅ "Show missing" (ghost cards) renders almost nothing

**Turned out not to be a `Gallery\Show` logic bug at all.** The filter code
was correct and matched its only test. Root cause: the `horizon` container
(where every queued job — `SyncCardPricingJob`, `ImportSetJob`, the daily
`catalog:refresh-prices` schedule — actually runs) was only attached to the
`tcgvault-internal` Docker network, which is `internal: true` and has no
route out. Every queued call to tcgdex's API failed DNS resolution and
eventually landed in `failed_jobs`. Adding a single card still worked
because that one sync runs synchronously inside the `app` container's web
request (which does have internet) — only the async backfill of the rest
of a set, and the entire daily price refresh, were silently broken.

This means the "Fresh pricing, updated daily" landing-page claim (fixed
earlier this session) had never actually been true in an automated sense
until this fix — daily refresh had no way to succeed before now.

**Fix applied and deployed**: added `space-server_web` to `horizon`'s
`networks` in `compose.prod.yml` (matching what `app` already has),
recreated the container. `scheduler` didn't need it — it only dispatches
to the queue, never calls tcgdex directly.

**Data cleanup**: 4 sets had accumulated partial card rows from before this
fix (sv02 Paldea Evolved, swsh3 Darkness Ablaze, me03 Perfect Order,
sv08.5 Prismatic Evolutions) — re-dispatched `ImportSetJob` for each;
backfill completed with 0 failures once the network fix landed.

**Still worth doing** (not done yet, low priority): add a Horizon/queue
health check or alert on `failed_jobs` growth — this class of failure
produces no user-facing error and no alert, only a silently stale set.

## 2. 🔲 Add-card search returns an unpaginated wall of 100+ results

**Problem**: Searching a common name (e.g. "Pikachu") with no set filter
returns 100+ inline tiles (many with no image), burying the actual
Condition/Quantity/Notes/Save form far below the fold.
**Likely area**: `App\Livewire\Admin\AddCollectionItem::runSearch()` /
`CardCatalogProvider::searchCardsByName()`.
**Severity**: Blocker.

## 3. 🔲 Mobile nav + set-pill row overflow the viewport

**Problem**: At ~390px, top nav clips "ACTIVITY" with no collapse/hamburger,
and the set-pill rail (`/{username}` home) overflows past the right edge —
confirmed real horizontal overflow (`scrollWidth` > `clientWidth`), not just
a visual impression. Distinct from the card-tile truncation bug already
fixed this session (`resources/css/app.css` — the `.cset .truncate`
min-width fix) — that one was inside the grid; this one is the nav/rail
chrome itself.
**Likely area**: `.nw-topbar`/`.nw-nav` and `.nw-rail` in `resources/css/app.css`.
**Severity**: Blocker (mobile).

## 4. 🔲 TCGPlayer import: dev-facing error + untranslated page

**Problem**: An unrecognized set code surfaces `"set desconocido — agregá
el código a config/tcgvault.php → tcgplayer_set_map"` directly in the admin
UI — actionable only by someone editing PHP. Separately, the entire
`/admin/import` page is in Spanish inside an otherwise-English app.
**Likely area**: whatever import service reads `config/tcgvault.php`'s
`tcgplayer_set_map` (needs `investigation-agent` first — not touched this
session) + `resources/views/livewire/admin/*import*.blade.php`.
**Decision needed before implementing**: rephrase the error only, or also
make the set map self-updating from tcgdex data? Ask Carlos. Language: pick
one (English, matching the rest of the app) and translate the page, or
confirm Spanish is intentional for this one screen.
**Severity**: Blocker (unrecognized-set case) / Friction (language).

## 5. 🔲 Notes not editable after creation; "Needs review" has no visible fix

**Problem**: Admin table's Notes column shows "—" and isn't clickable for
existing items (Notes can only be set once, at creation). Separately, rows
flagged "Needs review" (red badge) give no indication that assigning a
Variant is what clears the flag.
**Likely area**: `App\Livewire\Admin\CollectionItems` (`startEditingNotes`
exists already for the table-cell inline edit — check why it's not wired
into the "Edit item" modal too) + the modal's Blade view for a "why does
this need review" hint.
**Severity**: Friction.

---

## Backlog (noted, not scheduled yet — revisit after the 5 above)

- Search fields (public + admin) give no loading feedback during the
  ~2s debounce window — feels unresponsive.
- Collection value shows `$X + €Y` with a `+` that visually implies a sum
  of two currencies that can never actually be added.
- Activity page header "0 PRICE MOVES" sits directly above an unrelated
  "Added <card>" list — reads as a mismatched count.
- Admin table: "Review" status badge and "Delete" action share the same
  red/terracotta tone — **routes through the design-system constraint
  above**, needs Carlos's call, not a unilateral color add.
- Rarity badges/tile headers are all black/white regardless of rarity — a
  subtle rarity-tied accent could aid scanning the grid. Same constraint.
- Landing page (`/`) is dark/near-black; the rest of the authenticated app
  is light/bone — reads as two different products.
- Add-card search can visibly show results from a previous query for a
  moment after retyping/pasting — no spinner while a request is in flight.
- Newly-added card with no tcgdex price data shows a bare "—" in Value
  with no explanation (e.g. a tooltip).
