# tcg-vault Phase 4 — Daily Price History + Movimientos — Design Spec

Status: approved by Carlos, ready for implementation planning.
Parent spec: [`2026-09-14-tcg-vault-design.md`](2026-09-14-tcg-vault-design.md) §5
("Value tracking: current + historical") and §7 (the "Movimientos" screen).
Phase 3 spec: [`2026-09-14-phase-3-public-gallery-design.md`](2026-09-14-phase-3-public-gallery-design.md)
§7, which formally deferred this screen pending this phase's data.

## 1. What this is

A daily background job that re-syncs pricing for every card already in the
Catalog (no new cards — this is a refresh, not an import), and the
"Movimientos" screen the public gallery's nav header has been showing as
inert since Phase 3: a per-set feed of price deltas since the last
snapshot, plus a log of cards recently added to the collection.

## 2. The daily pricing job

**No new sync logic** — `CatalogSyncService::syncCard()` (Phase 1) already
does exactly the right thing: refetch the card from tcgdex and
`updateOrCreate` a `CardPriceSnapshot` row keyed on
`(card_id, source, variant, captured_on)`. Running it again for an
already-synced card on a new day naturally produces a second day's row
per source/variant, which is the entire history mechanism — no new
schema.

**Why a queued job, not a synchronous command** (per the parent spec):
tcgdex has no bulk pricing endpoint, so refreshing N cards is N HTTP
calls. A synchronous artisan command (like `catalog:import-set`, Phase 1)
is fine for an interactive one-off import of a handful of cards; a daily
refresh of every card in the Catalog needs retry/backoff per card without
one slow card blocking the rest — that's what Laravel's queue gives for
free that a plain command loop doesn't.

- `SyncCardPricingJob` (implements `ShouldQueue`): syncs exactly one card
  by `tcgdex_id`. `$tries = 3`, exponential backoff. A card that still
  fails after retries is logged and skipped — it does NOT fail the whole
  day's run.
- `catalog:refresh-prices` artisan command: iterates every `Card` row
  currently in the Catalog, dispatches one `SyncCardPricingJob` per card.
  No arguments — always "every card we already track."
- Scheduled daily via `routes/console.php`'s `Schedule::command(...)`.

**Explicitly NOT this phase's job:** discovering new cards/sets to
import. This refreshes what's already there.

## 3. Movimientos screen

Route: `/{username}/gallery/movimientos` — same public, unauthenticated,
`is_public`-gated pattern as the other two gallery screens (Task 4/5,
Phase 3). Reuses the same `layouts.public` layout and nav header — this
phase flips "Movimientos" from inert to a real link.

**Two sections, per the parent spec's exact wording** ("price changes
since the last snapshot with a relevant delta, plus a log of cards
added"):

- **Price deltas**: for each `Card` reachable through the target user's
  public collection(s) (same reachability rule as Tasks 4/5 — only cards
  the user actually owns, since this is specifically about their
  collection's value moving, not the whole Catalog), compare its most
  recent two `captured_on` snapshots (via `CardPriceResolver`'s same
  source-priority chain, applied to each of the two dates) and show the
  delta (up/down/flat) since the previous snapshot. `--signal` (green)
  for up, `--flat` (neutral gray) for down or unchanged — this is
  `design.md`'s ORIGINAL, primary meaning for both tokens, established
  before Phase 2/3 ever existed; nothing about this phase changes that
  rule, it's the first screen that actually needs it. A card with only
  one snapshot ever (just synced, no prior day to compare) shows no delta
  arrow, not a fake one.
- **Activity feed**: the target user's `CollectionItem`s (again, only
  ones in a public collection), most-recently-created first, showing
  "added `<card name>`" and a relative timestamp. Reuses
  `<x-card-image>` (Phase 3) for a small thumbnail per entry.

**Explicitly NOT this phase:** a chart/graph. The parent spec says
"historical value chart," but building an actual line chart means either
a new JS charting dependency (this project has taken zero new npm
dependencies through Phases 1-3, deliberately) or a hand-rolled SVG
sparkline. Given this phase's list of deltas already surfaces the same
information in the simplest possible form, the chart is deferred to a
follow-up — this section lists the same facts a chart would plot, just
without the visualization. Revisit if that turns out to feel
insufficient once real data exists to look at.

## 4. Testing approach

Pest, `Illuminate\Support\Facades\Queue::fake()` for the job-dispatch
test (assert one `SyncCardPricingJob` per existing card, no HTTP calls
made). A separate Job-level test (not faked) proving `SyncCardPricingJob`
really calls `CatalogSyncService::syncCard()` and that a
`CardNotFoundException`/`CatalogIdentityMismatchException` doesn't bubble
up uncaught (matching `catalog:import-set`'s existing per-card
catch-and-continue philosophy, translated to the job's own retry
semantics — a genuinely malformed/gone card shouldn't retry 3 times
uselessly, it should fail fast and get logged). Feature tests for the
Movimientos route mirroring Tasks 4/5's existing security pattern
(is_public gating, no-auth-required, 404 for an unknown username) —
these are the SAME invariants already proven twice; a third screen must
prove them too, not assume they transfer.
