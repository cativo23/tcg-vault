# Grouped collection admin (Listado / Editar / Crear)

## Context

"Manage collection" today treats every `CollectionItem` (a unique
variant+condition+grading combo) as its own row, in its own add flow, edited
one at a time. Owning several variants of the same card means: repeating the
full search-and-select flow for each one, editing them one at a time, and
seeing the same card name/set repeated across N rows in the table. This spec
groups all three CRUD surfaces around the card instead of the item.

Current behavior, for reference:

- `app/Modules/Collection/Models/CollectionItem.php:16-28` — the row shape
  (`card_id`, `variant`, `condition`, `grade_company`, `grade_value`,
  `quantity`, `notes`, `photo_path`).
- `app/Modules/Collection/Services/CollectionService.php:42-66` —
  `addItem()` already matches on the identity tuple
  `(card_id, variant, condition, grade_company, grade_value)` and increments
  `quantity` on a match instead of creating a duplicate row. This spec
  exposes that existing merge behavior in the UI instead of changing it.
- `app/Livewire/Admin/AddCollectionItem.php:219-309` and
  `resources/views/livewire/admin/add-collection-item.blade.php` — the
  current "Add a card" flow: search tcgdex → select one card → fill a single
  variant/condition/quantity/grading form → save.
- `app/Livewire/Admin/CollectionItems.php:174-361` and
  `resources/views/livewire/admin/collection-items.blade.php:67-241` — the
  current table (one row per item) and its inline qty/notes editing plus a
  per-item edit modal.

## Goals

- Adding several variants of the same card (new or already-owned) takes one
  search + one save, not one repetition per variant.
- The table reads one line per Pokémon card, not one line per copy.
- Editing all of a card's variants happens in one place.

## Non-goals

- Changing the tcgdex search itself, or adding "already owned" badges to
  search results (considered and explicitly deferred — Crear stays a plain
  search-and-select, no ownership hinting).
- Changing `CollectionService::addItem()`'s merge-by-identity logic — the
  three flows below are UI changes over that existing behavior.
- A bulk photo/notes editor across multiple cards at once.

## Design

### 1. Listado — one row per card, variants as inline chips

`CollectionItems.php`'s query groups items by `card_id` instead of listing
each item. Each row shows: card name + thumbnail, set, a row of variant
chips (`Normal · NM ×2`, `Holofoil · NM ×3`, ...), and an aggregate value
(sum of each variant's per-unit market price × its quantity). The toolbar's
`nw-count` reads "Showing `<N>` cards · `<M>` copies" — both distinct
counts, computed from the same grouped query, so neither number is a lie
about what the table shows.

Existing filters (condition, variant, needs-review, sort) still operate at
the item level internally — a card row appears if *any* of its items match
the active filter, and only the matching chips render (non-matching variants
of an otherwise-matching card are simply not shown as chips, not hidden by
excluding the whole row). Sort by "Value" sorts by the card's aggregate.

Clicking anywhere on a row (the row itself, a chip, or a dedicated "Edit"
button) opens the Editar modal (below) for that card. A "+ Add variant"
button on the row is a shortcut into the same modal, scrolled to its
"add another variant" control.

### 2. Editar — one modal per card, all variants together

Replaces the current single-item edit modal
(`collection-items.blade.php:171-241`) with one modal keyed by `card_id`
that lists every `CollectionItem` for that card as an editable row:
variant, condition, quantity — each with a delete control.

**Save behavior (hybrid, per the visual companion walkthrough)**:

- Editing an existing row's variant/condition/quantity field autosaves on
  blur/Enter, exactly like today's inline Qty/Notes editing
  (`collection-items.blade.php:117-122`) — a small "Saved" tag appears next
  to the field for ~2s and fades, giving feedback without a persistent
  banner.
- Deleting a row (✕) and adding a row ("+ Add another variant to this
  card") both fire immediately against the server — they are actions, not
  form fields waiting on a save button. This avoids the failure mode of
  "added a variant, didn't hit Save, closed the modal, lost it."
- The modal's only button is "Done", which just closes it — nothing is
  ever left unsaved when the user dismisses the modal by any route (Done,
  ✕, backdrop click, Esc).
- A new row added via "+ Add another variant" defaults to condition "Near
  Mint", quantity 1, and an empty variant (forcing an explicit choice
  before it autosaves — an unset variant on save is stored as `null`,
  consistent with today's "not specified" handling and the existing
  `needs_variant_review` flag).

Validation: the same identity-tuple merge from `CollectionService::addItem`
applies if an edited row's new variant/condition/grading collides with
another existing row for the same card — that case surfaces as a validation
error on the row ("You already have this exact variant/condition — edit
that row instead") rather than silently merging mid-edit, since a silent
merge here could quietly delete data the user just typed into a different
row.

**Grading and notes**: the compact row only shows variant/condition/qty —
grading company/value and free-text notes (both supported today per item)
aren't dropped, but don't belong in a row meant to stay scannable across
several variants at once. Each row gets a small "Details" toggle that
expands just that row to reveal grading company, grade value, notes, and
the custom photo — collapsed by default, so the common case (no grading,
no notes) stays a single compact line.

### 3. Crear — search once, add several variants in one save

`AddCollectionItem.php`'s flow keeps its current search/select-a-card step
unchanged (no ownership badges on results, per the earlier decision). Once
a card is selected, the single variant/condition/quantity/grading form is
replaced by the same repeatable variant-row list from the Editar modal,
including the same collapsed-by-default "Details" toggle per row for
grading/notes/photo described above: starts with one row, "+ Add another
variant of this same card" appends more.

One "Save `<N>` variants to collection" button submits every row in one
request, calling `CollectionService::addItem()` once per row inside a single
transaction — so a validation failure on one row (e.g., a duplicate
variant+condition combo across two rows in the same submission) rolls back
the whole save with a per-row error, rather than partially saving.

## Testing

- Feature tests for the grouped Listado query: card with 3 items across 2
  conditions groups into 1 row with 3 chips and the correct aggregate value;
  a card with one `needs_variant_review` item still surfaces the review
  flag on the row.
- Feature tests for the Editar modal's Livewire component: autosave on
  field blur, immediate add/delete calls, and the duplicate-row validation
  error path.
- Feature tests for Crear: submitting 3 rows for one new card in a single
  request creates 3 `CollectionItem` rows; a duplicate variant+condition
  combo within the same submission fails validation without persisting any
  row from that submission.
