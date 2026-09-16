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

**All 5 items are 🚀 deployed to production** (single combined deploy).
Verified post-deploy: all containers healthy, `horizon` still has
internet egress after the redeploy, `https://tcgvault.cativo.dev/up`
→ 200.

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

## 2. ✅ Add-card search returns an unpaginated wall of 100+ results

**Root cause confirmed against tcgdex's real API**: `searchCardsByName()`
never sent pagination params, and tcgdex's `/cards?name=` returns every
match in one response unless told otherwise (verified live: unfiltered
"Pikachu" → 207 results; with `pagination:page`/`pagination:itemsPerPage`
→ exactly the requested page size). See
https://tcgdex.dev/rest/filtering-sorting-pagination#pagination.

**Fix applied**: `TcgdexCardCatalogProvider::searchCardsByName()` now
always sends `pagination:itemsPerPage=24` and `pagination:page`, real
server-side pagination (not a client-side slice of an unbounded fetch —
cheaper and faster). `AddCollectionItem` shows the first page, infers
"might be more" heuristically from a full page (tcgdex's search has no
total-count field), and offers `loadMoreResults()` — same
infinite-scroll (`wire:intersect`) + manual button pattern as
`Gallery\Index`, appending pages rather than replacing so a card from an
earlier page stays selectable. When there's more and no set filter is
active, a hint nudges toward the existing set dropdown to narrow further.

Verified live on `localhost:8090`: "Pikachu" now shows "Showing the
first 24 matches..." with the Condition/Quantity/Notes/Save form
reachable right below, `Load more` appends without losing the earlier
page, and selecting a card still works normally.

7 new tests (2 provider, 5 component); full suite 310/310 passing.

## 3. ✅ Mobile nav + set-pill row overflow the viewport

**Re-measured live at 390px (forced viewport, since window resizing wasn't
available in this session's browser tool) — only half of this was a real
bug:**

- **Nav (`.nw-topbar`)**: real. `scrollWidth 450` vs `clientWidth 390` —
  the topbar itself pushed the whole page 60px past the viewport, and
  "ACTIVITY" was genuinely clipped with no way to reach it.
- **Set-pill rail (`.nw-rail`)**: not a bug. It overflows *internally*
  (595 vs 322) but its own parent never does (390 = 390) — `.nw-rail` is
  already a deliberately contained scroll rail (`overflow-x: auto` +
  hidden scrollbar), the same idiom as `.nw-toolbar .nw-seg` and
  `.nw-strip` elsewhere in this file. Working as designed, just with no
  visual hint that more content exists past the edge — noted in the
  backlog below, not a blocker.

**Fix applied to `.nw-nav`**: `min-width: 0` lets it shrink instead of
forcing the topbar wider than the viewport, `overflow-x: auto` +
hidden scrollbar contains the overflow instead of leaking it to the
page (same idiom as the rail), and a `::after` fade-gradient hints
there's more to scroll to — a hidden scrollbar alone gave zero
indication "ACTIVITY" was reachable. No hamburger/JS needed; `design.md`
has no responsive guidance that would block this.

Verified live: at a forced 390px width the topbar no longer overflows
the page (`scrollWidth === clientWidth`), and scrolling the nav strip
reveals "ACTIVITY" fully legible. Full suite still 313/313 (CSS-only
change, no new test coverage needed).

## 4. ✅ TCGPlayer import: dev-facing error + untranslated page

**Self-updating set map: ruled out, not a real decision.** `config/tcgvault.php`'s
own comment already documents why: tcgdex's Set object has no
TCGplayer-code field, so there's no API-driven way to resolve one — the
manually-maintained map is the only option. Rephrasing the error was the
only implementable path.

**Language: Carlos chose to translate to English**, matching the rest of
the admin (there's no i18n mechanism in this app at all — no
`resources/lang/`, `__()` calls elsewhere are unused Breeze/Jetstream
scaffolding — so this was a manual string pass either direction, not a
locale-file flip).

**Fixes applied**:
- `unknown_set`'s reason label no longer names `config/tcgvault.php` or
  `tcgplayer_set_map` — an admin can't act on either. It now reads
  "unrecognized set — not yet supported for import."
- Translated `import.blade.php`, `Import.php`'s summary/button strings,
  and the "Import TCGplayer" nav link in `collection-items.blade.php`
  (was "Importar TCGplayer") to English.

Verified live: previewing an unknown set code shows "1 not recognized" /
"unrecognized set — not yet supported for import" — no config path or
internal variable name leaks into the UI. 4 existing test assertions
updated to English, 1 new regression test pins the rephrased message
and asserts it never contains "tcgvault.php" or "tcgplayer_set_map".
Full suite 314/314.

## 5. ✅ Notes not editable after creation; "Needs review" has no visible fix

**Neither was actually broken — both were a pure discoverability gap.**
`startEditingNotes()` was already wired to the same `—` span regardless of
whether an item has notes yet (confirmed by an existing passing test), and
assigning a Variant in the "Edit item" modal already clears
`needs_variant_review` (also already tested). Nothing to fix
functionally — the problem was that neither affordance gave any visual
signal it was interactive or what it would do.

**Fixes applied**:
- Notes cell: added a dashed underline + `title="Click to add/edit a
  note"` so the `—` (or existing note) reads as clickable, not as a
  static placeholder.
- "Review" badge: turned into a button that opens the same "Edit item"
  modal the Edit link opens, with `title="Assign a Variant in Edit item
  to clear this"` explaining what it takes to resolve.

Verified live: clicking "Review" opens "Edit item" straight to the
Variant field. 2 new tests, full suite 316/316.
**Severity**: Friction.

---

## Backlog — resolved 2026-09-16, one item still open

All 8 originally listed here are done, verified live, and committed
(not yet deployed — see status note at the top). Deliberately fixed
before the multi-tenant work resumes, on Carlos's call: better to
polish while traffic is low-single-user than after more people land on
a rough page.

- ✅ Search fields (public + admin) had no loading feedback during the
  debounce window — a pulsing-dot indicator now shows on all 3 (gallery,
  admin table, add-card).
- ✅ Collection value's `$X + €Y` visually implied a sum — `+` → `·`, a
  separator with no arithmetic meaning.
- ✅ Activity's "0 price moves" header could undercount what the feed
  actually showed (additions always appear regardless of price moves) —
  now counts the feed itself ("N updates").
- ✅ Admin table's "Review" badge and "Delete" shared `--danger` red —
  **Carlos approved a third, narrow color token**, `--warning` (amber),
  restricted the same way `--danger` is (never destructive, never price
  direction, never per-category). `design.md` amended.
- ✅ Add-card search showed the previous query's results, unchanged,
  for the full request round-trip — the grid now dims while a search is
  in flight, not just a spinner elsewhere on the page.
- ✅ A card with no synced price showed a bare "—" in the admin Value
  column — now has a title explaining it's unsynced, not broken.
- ✅ Landing's dark hero/close used a different near-black than the
  rest of the app's `--ink` — unified to one token; Carlos reviewed a
  screenshot and approved keeping the dark bookends, not going fully
  light.
- ✅ **Rarity badges/tile headers with no rarity-tied accent** —
  Carlos reconsidered explicitly (separate decision from the
  Review/Delete warning-token approval) after a real point: an
  undifferentiated grid isn't just a stylistic gap, it's a genuine
  usability problem for anyone who doesn't already read rarity
  abbreviations fluently. Researched how MTG/Hearthstone/Pokémon TCG
  Pocket solve this (all tie the accent to fixed printed rarity, never
  price) before proposing a 3-tier standard/silver/chase system;
  reviewed a live mockup before implementing. `design.md` amended with
  the exception's exact boundaries.

Also found and fixed along the way (not originally on this list):
searching `?search=<no matches>` on the public gallery collapsed into
the same empty state as a genuinely-empty collection, hiding the
search box and Clear button — no way to remove the search term short
of editing the URL by hand.
