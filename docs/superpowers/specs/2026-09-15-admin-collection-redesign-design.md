# Admin "My Collection" Screen — Redesign

## Purpose

`/admin` (`App\Livewire\Admin\CollectionItems`) is the collector's own
private inventory screen. Today it loads and renders every item they own
in one unpaginated table with no search, filter, or sort — a real
performance risk once a collector has hundreds of cards, and it hides the
two things a value tracker exists to show: variant and estimated value.
Carlos, live, after using it: "mejorar x100 el admin esta muy meh... ni la
tabla tiene para buscar... ni tenemos paginación."

A dispatched Design-Director-persona review (this session) identified the
same root problem independently: no scale mechanism, and variant/grading/
value buried in a modal instead of scannable at a glance. This spec
implements that review's recommendations, refined through a live Q&A with
Carlos about the actual "fix a variant-ambiguous card" flow using his own
real collection as the test case.

## Scope decision

Carlos explicitly chose to include the "needs review" persisted flag in
this scope (not defer it) — closing the loop on the importer's
`variantAmbiguous` fix (`docs/superpowers/plans/...` — the earlier
same-session fix) rather than leaving flagged cards invisible again once
the import preview closes.

## Data model change

**New column**: `collection_items.needs_variant_review` (boolean,
default `false`, not nullable).

- Set to `true` by `CollectionService::addItem()` when the caller passes
  `needs_variant_review: true` in `$itemData` — the importer's `confirm()`
  passes this through from `MatchedImportLine::$variantAmbiguous` for
  every chunked `addItem()` call.
- The single-add flow (`AddCollectionItem`) never sets it — a person
  hand-picking a card and its variant from a dropdown already resolved
  the ambiguity themselves at add time.
- **Auto-clears** the moment the item's `variant` is edited to a non-null
  value via the admin edit modal (`CollectionItems::saveItem()`) — the
  collector just told the system which copy this is, so there's nothing
  left to review. It does NOT clear just because *any* field changed
  (e.g. editing only Notes leaves the flag alone) — only a variant
  assignment resolves it.
- `addItem()`'s existing upsert identity tuple (`variant`, `condition`,
  `grade_company`, `grade_value`) is unchanged — this flag is metadata on
  the row, not part of what makes two rows "the same" or "different."

## The actual resolve-a-flagged-card flow (verified live with Carlos's real data)

No new "split" feature needed — the existing Edit-modal + Add-card
primitives already cover both real cases, confirmed against Carlos's own
collection (3 Lampent, me05-037, one of them holofoil):

- **Multi-copy case** (qty > 1, mixed variants): edit the flagged row
  down to the quantity of ONE variant and assign that variant (clears the
  flag on that row), then use "+ Add card" to add the remaining
  copies with their own variant — a genuinely new row, since `addItem`'s
  upsert key includes `variant` and therefore won't merge it into the
  first row.
- **Single-copy case** (qty = 1, but that one copy is holo/reverse-holo):
  just edit the row's variant directly. No second row, no split — same
  modal, one field.

## Table — columns

In order, left to right:

1. **Card** (photo thumbnail if present + name) — unchanged.
2. **Set** — unchanged.
3. **Variant** — NEW column (was modal-only). Renders the stored value
   title-cased (`Str::headline`), or an em dash when null.
4. **Condition** — unchanged, stays as the existing mono short code.
5. **Grading** — NEW column. Renders `"{$company} {$value}"` (e.g. "PSA
   10") when both are set, an em dash otherwise.
6. **Qty** — unchanged position, but becomes inline-editable (see below).
7. **Value** — NEW column. The item's estimated value: `CardPriceResolver::resolve($item->card)`
   → format via the existing `Money::format()` helper × `$item->quantity`
   (the row's copies together, not a single-unit price) when a snapshot
   exists; em dash when the card has no pricing yet. This is the ONLY new
   column that costs a query per row — bounded by the page size (24), same
   cost class as the public gallery's own per-card price resolution.
8. **Notes** — unchanged, stays inline-editable exactly as today.
9. **Status** — NEW column. Renders a "Revisar" badge (using
   `--color-danger`, the token already reserved for destructive/error UI
   and currently unused on this screen) when `needs_variant_review` is
   true; empty otherwise.
10. **Actions** (Edit / Delete) — unchanged position, Delete's
    confirmation changes (see below).

## Toolbar (reusing `.nw-toolbar`, `.nw-pill-input`, `.nw-pill-select`, `.nw-seg` — proven on the public gallery, not new components)

- **Search** (`.nw-pill-input`, 300ms debounce — same as
  `gallery/show.blade.php`'s existing search): matches card name, set
  name, OR notes, case-insensitive (`ILIKE`, matching the gallery's own
  search convention).
- **Filters**: a condition `.nw-pill-select` (All / NM / LP / MP / HP /
  DMG), a variant `.nw-pill-select` (All / Normal / Holofoil /
  Reverse Holofoil / Not specified), and a "Needs review" toggle
  (`.nw-seg` two-state: All / Needs review) — the highest-leverage filter
  per the design review, since this is how a collector finds every
  flagged card in one click instead of scanning the whole table.
- **Sort** (`.nw-seg`): Value (default, descending — "what's my most
  valuable card" is the product's core question), Name (A→Z), Added
  (newest first).
- **Pagination**: Livewire's built-in `->paginate(24)`. 24 was chosen to
  match a 4-wide/6-row visual grid feel on desktop without inventing a
  new number; this is a personal collection (dozens to low hundreds of
  items per the product's whole framing), not a scale that needs
  cursor-pagination or virtualization.

## Row editing

- **Quantity** becomes inline-editable, following the exact pattern
  Notes already uses (click the value → becomes an input → Enter or
  blur saves via a dedicated Livewire method, not the big modal) — it's
  the field collectors adjust most casually (picked up a duplicate,
  traded one away) and doesn't warrant opening a modal.
- **Condition, Variant, Grading** stay in the existing modal — these are
  more deliberate edits with real validation (the modal's existing
  per-card-dynamic variant dropdown, sourced from the card's actual known
  price variants, is unchanged).
- **Delete** gets its own styled confirmation modal, replacing the native
  browser `wire:confirm`. Same visual shape as the existing edit modal
  (centered card, dark overlay, click-outside/Escape to close), title
  "Remove this card?", the card's name + variant + qty as context, a
  `--color-danger`-styled confirm button. This was flagged in the design
  review as the one genuinely jarring interaction on an otherwise
  consistent screen.

## Visual system migration

The screen moves off `layouts.app` + ad-hoc Tailwind utility classes onto
the same locked token system (`design.md`) the public gallery already
uses: `.nw-card`, `.nw-toolbar`, `.nw-pill-input`/`.nw-pill-select`,
`.nw-seg`, `.nw-btn-primary`/`.nw-btn-secondary`/`.nw-btn-danger` (already
defined, used elsewhere), bone/ink colors, Archivo font, Martian Mono for
the Qty/Value numeric columns. This is NOT a new design system — it's
extending the one that already exists into a screen that never got it.
The edit and delete modals keep their current shape/behavior, restyled
onto the same tokens.

## Testing

- Feature tests for: the new column data renders correctly (variant,
  grading, value, needs-review badge); search matches name/set/notes;
  each filter (condition, variant, needs-review) narrows correctly;
  each sort mode orders correctly with Value-desc as the unauthenticated
  default; pagination returns 24 per page and a second page is reachable;
  inline Qty editing persists; the new delete-confirmation modal actually
  deletes only after confirmation (not on the initial click); editing a
  flagged item's variant clears `needs_variant_review`, editing only
  Notes on a flagged item does NOT clear it.
- `CollectionService::addItem()`'s existing test suite gets one new case:
  passing `needs_variant_review: true` sets the column on create; the
  flag does not itself change which existing row a call upserts into
  (the identity tuple is unchanged).
- `Import::confirm()`'s existing tests get one new assertion: a
  variant-ambiguous matched line's `addItem()` call actually carries
  `needs_variant_review: true` through.

## Out of scope

- Bulk actions (select multiple rows, bulk delete/edit) — not requested,
  YAGNI until a real need surfaces.
- Any change to the single-add flow (`AddCollectionItem`) beyond none —
  it already collects variant explicitly, so it never produces an
  ambiguous row.
- Any change to the importer's parser/preview itself — that shipped
  earlier this session; this spec only adds where the flag it already
  computes gets *stored* and *surfaced*.
- Client-side sorting/filtering (stays server-round-trip via Livewire,
  matching the public gallery's own established pattern).
