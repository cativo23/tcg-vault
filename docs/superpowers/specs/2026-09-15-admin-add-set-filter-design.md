# Admin Add-Card Set Filter — Design

**Context:** `/admin/add`'s tcgdex name search (`AddCollectionItem::runSearch()`) returns every
printing across every set. Carlos, live: searching "pikachu" wanting only the "Paldea Evolved"
printing returns every Pikachu ever printed, with no way to narrow the search itself — the earlier
fix (showing the resolved set name under each result) only helps *identify* which result is which,
not *filter* them out. Carlos's own words when this was first raised: "pero ademas como buscar mas
facil quizas poner un drowdown de todos los sets y de ahi el buscador busca dentro del set? pero no
sé" — parked as genuinely undecided, resolved now via its own brainstorm.

## Verified capability

tcgdex's `/cards` search endpoint supports server-side filtering by nested field via dot notation
(`object.key=value`, e.g. `name=pikachu`). Confirmed live against the real API:

```
GET https://api.tcgdex.net/v2/en/cards?name=pikachu&set.id=sv02
```

returned exactly the 2 Pikachu printings that belong to set `sv02` (regular + ex), not the full
cross-set list. The filter runs entirely on tcgdex's side — no need to fetch everything and filter
in PHP.

## Architecture

- **`CardCatalogProvider::searchCardsByName()`** gains a second, optional parameter:
  `searchCardsByName(string $query, ?string $setTcgdexId = null): array`. Default `null` keeps the
  existing (unfiltered, cross-set) behavior — no breaking change for any other caller.
- **`TcgdexCardCatalogProvider::searchCardsByName()`** passes `'set.id' => $setTcgdexId` into the
  existing `Http::get('cards', [...])` params array only when `$setTcgdexId !== null`. No new HTTP
  call, no new timeout tier — same request shape as today, one extra query param.
- **`AddCollectionItem` (Livewire)**:
  - New public property `?string $setFilter = null` (`null` = "All sets").
  - New public property `array $availableSets` (`[tcgdex_id => name]`), populated once in `mount()`
    from `Set::orderBy('name')->pluck('name', 'tcgdex_id')->all()` — the local Catalog only, no
    tcgdex round-trip, matches the earlier decision that the dropdown lists only synced sets.
  - `runSearch()` passes `$this->setFilter` through to `searchCardsByName($this->search,
    $this->setFilter)`. `$resultSetNames` resolution (added in the previous fix) is unchanged —
    still useful when `$setFilter` is `null` and results span multiple sets.
  - New `updatedSetFilter(): void` method calls `$this->runSearch()` — picking a set re-runs the
    current search immediately, same as the debounced name input.
- **View (`add-collection-item.blade.php`)**: a `<select wire:model.live="setFilter">` above the
  search input, first option `"All sets"` (empty value → `null`), followed by `$availableSets`
  sorted by name. No required/blocking behavior — the name search works exactly as it does today
  when left on "All sets".

## Data flow

1. Carlos optionally picks a set from the dropdown (defaults to "All sets").
2. Carlos types a name (debounced 400ms, as today) or changes the set (immediate).
3. `runSearch()` calls `searchCardsByName($search, $setFilter)`.
4. tcgdex returns only cards matching both the name and (if given) the set.
5. Results render exactly as today — image, name, resolved set name, tcgdex code.

## Error handling

Unchanged from today: a tcgdex failure during `runSearch()` is caught, reported, and surfaces the
existing "Could not search right now" error — the set filter adds a query param, not a new failure
mode.

## Testing

- `TcgdexCardCatalogProvider` test: asserts `set.id` is included in the outgoing request query when
  `$setTcgdexId` is passed, and absent when it's `null` (`Http::fake()` assertion on the request).
- `AddCollectionItem` Livewire test: `$availableSets` is populated from `Set` factory rows on mount;
  setting `setFilter` and calling `runSearch()` passes it through to the (faked) provider; leaving
  `setFilter` unset behaves identically to the current test suite (regression safety).

## Out of scope

- No change to `listSetCardIds()`, `findCard()`, or any other `CardCatalogProvider` method.
- No new tcgdex endpoint or bulk sets list — the dropdown is local-only, per the earlier decision.
- No change to the public gallery's set browsing — this is admin-only, `/admin/add`.
