# TCGplayer Bulk Importer — Design

**Date:** 2026-09-15
**Status:** Approved

## Problem

Carlos can export his TCGplayer Android app collection/decklist as plain text, one card per
line. Today the only way to add cards to tcg-vault is one at a time through the single-card
search screen. For an export with 50+ lines that's impractical. This feature parses that
export format and bulk-adds the matched cards to the collection.

## Input format (verified against a real export)

```
1 Toucannon - 068/084 [PBL] 068/084
2 Lampent [PBL] 037/084
1 Basic Fire Energy - 002 [MEE] 2
```

`<qty> <name>[ - <disambiguator>] [<SET_CODE>] <localNumber>`. The disambiguator appears when
TCGplayer's own card name is ambiguous within the export (e.g. two different `Bastiodon`
prints) — it is not itself parsed for data, it just precedes the bracketed set code. Basic
energy lines use a separate, non-zero-padded numbering scheme under set code `MEE`.

tcgdex has no TCGplayer-code field on its Set object (confirmed via `tcgdex.dev/rest/set`
docs) — set-code mapping cannot be derived automatically and must be maintained by hand.

## Verified real-data findings (this session, via polaris2 SSH — tcgdex has no egress from
this sandbox)

- `PBL` → tcgdex set id `me05` ("Pitch Black"), confirmed by matching `cardCount.official: 84`
  against tcgdex's `/v2/en/sets` listing.
- `MEE` → tcgdex set id `mee`, matched directly by id (the "Mega Evolution Energy" set) —
  confirmed to be a coincidence, not a systematic abbreviation mapping.
- `me05` zero-pads `localId` to 3 digits; `mee` also zero-pads to 3 digits (`001`–`008`).
- Ran the full parser (regex + this mapping) against Carlos's real 56-line export:
  56/56 lines parsed, 56/56 matched a real tcgdex card, 0 unrecognized, 0 unknown-set. After
  merging duplicate `(set, localId)` lines (e.g. `Lampent` appeared on 2 separate lines):
  52 unique cards, 66 total copies.
- Energy-card rarity check: `mee-002` "Fire Energy" is Common rarity with null pricing on both
  pricing sources — genuinely worthless bulk in this case. No special-case filtering needed for
  basic energy lines; they're treated like any other line.

## Decisions

1. **Set-code mapping: small hand-maintained table**, not auto-detection. Starts with
   `PBL → me05`, `MEE → mee`. New entries added by hand as new sets are exported.
2. **New screen at `/admin/import`** (textarea + preview), not bolted onto the existing
   single-card search screen.
3. **No special-case filtering of basic energy cards.** Verified with real tcgdex data that
   they're not systematically valuable — treated like any other card line.
4. **Quantity merge against the existing collection: sum to existing.** If Carlos already
   owns 1 copy of a card and the import brings 3 more, the result is 4. This is the same
   behavior `CollectionService::addItem()` already has when the same card is added twice —
   no new merge logic, the importer just calls it once per matched card.
5. **Preview format: summarized table**, one row per already-merged card (qty, name, set,
   number) — not a diff against current holdings, not a bare count. Matches the "Merged
   quantities" table format validated against the real export in this session.
6. **Unmatched lines never block the import.** They're listed in a separate "not recognized"
   section with a reason (`unknown_set` or `card_not_found`). The matched cards above them can
   still be confirmed and imported independently.

## Architecture

```
Pasted text
    │
    ▼
TcgplayerImportParser::parse()   (pure, no DB writes)
    │
    ▼
ParsedImport { matched: [...], unmatched: [...] }
    │
    ▼
Admin\Import Livewire component — renders preview
    │  (user clicks "Confirmar")
    ▼
CollectionService::addItem() × N   (existing upsert path, unchanged)
```

### `App\Modules\Collection\Services\TcgplayerImportParser`

New class. `parse(string $text): ParsedImport`.

- Line regex: `^(?P<qty>\d+)\s+(?P<name>.+?)(?:\s+-\s+\S+)?\s+\[(?P<set>\w+)\]\s+(?P<local>\S+)$`
  (validated against the real 56-line export this session with 0 misses).
- Set-code map: a small hardcoded array/config (e.g. `config/tcgvault.php`'s
  `tcgplayer_set_map`), starting with `['PBL' => 'me05', 'MEE' => 'mee']`.
- For each line: resolve `set` via the map (miss → `unmatched`, reason `unknown_set`);
  zero-pad `local` to the width tcgdex uses for that set; verify the resulting
  `{set}-{local}` actually exists in the Catalog via `CardCatalogProvider` (miss → `unmatched`,
  reason `card_not_found`).
- Merge quantities for lines that resolve to the same `(set, localId)` before returning.
- Never touches the database — pure parse + lookup.

### `ParsedImport` (spatie/laravel-data)

```php
final class ParsedImport extends Data
{
    /** @param Collection<int, MatchedImportLine> $matched */
    /** @param Collection<int, UnmatchedImportLine> $unmatched */
    public function __construct(
        public readonly Collection $matched,
        public readonly Collection $unmatched,
    ) {}
}
```

`MatchedImportLine`: `qty`, `tcgdexId`, `name`. `UnmatchedImportLine`: `rawLine`, `reason`.

### `App\Livewire\Admin\Import`

New Livewire component, gated the same way `/admin/add` already is (admin-only).

- Textarea + "Preview" button → calls the parser, stores `ParsedImport` in component state.
- Renders the matched table (qty, name, set, number) and, if non-empty, a separate
  "No reconocidas" section listing each unmatched line and its reason.
- "Confirmar import" button, enabled whenever `matched` is non-empty (unmatched lines never
  disable it) — loops `matched` and calls `CollectionService::addItem()` per card. Per-card
  exceptions are caught and logged, same catch-and-continue philosophy as `ImportSetJob`, so
  one bad card during confirmation doesn't abort the rest of the batch.
- After confirmation: a summary ("N cartas agregadas, M copias totales") and a fresh empty
  textarea for the next paste.

No new merge/upsert logic — `addItem()` already sums quantities for a card the user already
owns, and already dispatches `ImportSetJob` when the card's set isn't fully imported yet, so a
bulk import of a not-yet-seen set naturally backfills the rest of that set in the background,
same as any single manual add today.

## Testing

- **Parser unit tests** (table-driven): a normal line, a line with a disambiguator on a
  repeated name (`Bastiodon - 093/084` vs `Bastiodon - 062/084`, both real lines from Carlos's
  export), a basic-energy line (`MEE`), an unknown set code, a card number tcgdex doesn't
  recognize, and quantity merging across two lines for the same card.
- **Feature test** for the Livewire flow: paste Carlos's real 56-line export as a fixture,
  preview shows 52 matched rows, confirm adds 66 total copies to the collection, verified
  against `CollectionService`'s existing quantity semantics.

## Out of scope

- Auto-detecting new set codes (the mapping table is hand-maintained by design).
- A diff-against-current-holdings preview (decided against — the summarized table is enough).
- Any UI beyond the confirm/summary flow (no undo screen, no import history for now).
