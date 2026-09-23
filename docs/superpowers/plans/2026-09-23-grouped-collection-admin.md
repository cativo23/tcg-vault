# Grouped Collection Admin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild tcg-vault's "Manage collection" admin (Listado / Editar / Crear) so every screen operates on a *card*, not a *collection item* — one row per Pokémon in the table, one modal editing all its variants together, and one submission to add several variants of a new card at once.

**Architecture:** `CollectionItems` (Livewire) gains a card-grouping query for the table and a card-scoped edit modal replacing its old per-item modal; `AddCollectionItem` (Livewire) gains a repeatable `rows[]` array replacing its single flat variant/condition/quantity form. Both share one Blade partial for a "variant row" (variant/condition/qty + a collapsed Details disclosure for grading/notes/photo) so the same markup and field names back both the modal and the create form.

**Tech Stack:** Laravel 13, Livewire 3, Pest, Tailwind + the Nightwire design tokens in `resources/css/app.css`.

## Global Constraints

- TDD-first: write the failing test before the implementation, on every step (per `CLAUDE.md`).
- Every `CollectionItem` lookup by raw ID MUST go through the existing tenant-safe `ownedItemOrFail()` (or its new card-scoped sibling below) — never `CollectionItem::find()`/`findOrFail()` directly (`app/Livewire/Admin/CollectionItems.php:174-184`'s own rule, now applying to more call sites).
- `CollectionService::addItem()`'s identity-tuple merge (`app/Modules/Collection/Services/CollectionService.php:42-66`) is NOT changed — every task below builds UI on top of that existing behavior.
- Conventional commits (`type(scope): description`), one concern per commit, per repo convention.
- Follow this repo's existing release pipeline (feature branch → PR → `master` → `release/vX.Y.Z` branch → PR → `master`) once all tasks here are done and reviewed — not per-task.

---

### Task 1: Grouped query + view — one row per card with variant chips

> **Merged 2026-09-23:** originally planned as two tasks (a backend-only
> query change, then a separate view rewrite). Merged during execution —
> `Livewire::test()` always renders the real Blade view even when only
> testing `render()`'s data, so a query-only task's own tests cannot pass
> without the view already handling the new grouped shape. Splitting them
> would leave an intermediate commit that 500s the page. One task, one
> working commit.

**Files:**
- Modify: `app/Livewire/Admin/CollectionItems.php:391-490` (the `render()` method, and the `VALUE_SORT_ROW_LIMIT` constant's usage)
- Modify: `resources/views/livewire/admin/collection-items.blade.php:24-165` (toolbar count + table body)
- Modify: `resources/css/app.css` (new `.nw-chip` class)
- Test: `tests/Feature/Livewire/Admin/CollectionItemsTest.php`

**Interfaces:**
- Produces: `render()` passes the view `cardGroups` (a `LengthAwarePaginator` of `stdClass` groups shaped `{card: Card, items: Collection<CollectionItem>, totalQuantity: int, totalValueMinor: int, needsReview: bool}`), `totalCards` (int), `totalCopies` (int). Each card row renders a `wire:click="openCardEditor({{ $group->card->id }})"` trigger — Task 3 defines that method (it doesn't exist yet; the button/row click is inert until Task 3 lands, which is fine — nothing in this task's own tests calls it).

- [ ] **Step 1: Write the failing test**

```php
test('a card with 3 items across 2 conditions groups into one row with the right totals', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 100]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 300]);

    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 2]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'NM', 'quantity' => 3]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'LP', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class);

    // One row, not three: 2 + 3 + 1 copies, ($1.00*2)+($3.00*3)+($3.00*1) minor units.
    expect($test->viewData('cardGroups'))->toHaveCount(1);
    $group = $test->viewData('cardGroups')->first();
    expect($group->totalQuantity)->toBe(6);
    expect($group->totalValueMinor)->toBe(200 + 900 + 300);
    expect($test->viewData('totalCards'))->toBe(1);
    expect($test->viewData('totalCopies'))->toBe(6);
});

test('two different cards each with one item produce two separate groups', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $a = Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Tropius']);
    $b = Card::create(['tcgdex_id' => 'me05-002', 'set_id' => $set->id, 'local_id' => '002', 'name' => 'Grubbin']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $a->id, 'card_tcgdex_id' => 'me05-001', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $b->id, 'card_tcgdex_id' => 'me05-002', 'condition' => 'NM', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class);

    expect($test->viewData('cardGroups'))->toHaveCount(2);
    expect($test->viewData('totalCards'))->toBe(2);
    expect($test->viewData('totalCopies'))->toBe(2);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail php vendor/bin/pest --filter="groups into one row|two separate groups"`
Expected: FAIL — `viewData('cardGroups')` doesn't exist yet (current view data key is `items`).

- [ ] **Step 3: Replace `render()` with the grouped version**

In `app/Livewire/Admin/CollectionItems.php`, replace the whole `render()` method (currently lines 391-490) with:

```php
    public function render()
    {
        // Reached only through Collection::items(), which is scoped via
        // Collection's TenantScope — never query CollectionItem::query()
        // directly here, that would bypass the tenant filter entirely.
        $collectionIds = Collection::query()->pluck('id');

        $itemsQuery = CollectionItem::query()
            ->whereIn('collection_id', $collectionIds)
            ->with(['card.set', 'card.priceSnapshots']);

        if ($this->search !== '') {
            $term = '%'.trim($this->search).'%';
            $itemsQuery->where(function ($q) use ($term) {
                $q->whereHas('card', fn ($cq) => $cq->where('name', 'ilike', $term))
                    ->orWhereHas('card.set', fn ($sq) => $sq->where('name', 'ilike', $term))
                    ->orWhere('notes', 'ilike', $term);
            });
        }

        if ($this->conditionFilter !== '') {
            $itemsQuery->where('condition', $this->conditionFilter);
        }

        if ($this->variantFilter === '__none__') {
            $itemsQuery->whereNull('variant');
        } elseif ($this->variantFilter !== '') {
            $itemsQuery->where('variant', $this->variantFilter);
        }

        if ($this->needsReviewOnly) {
            $itemsQuery->where('needs_variant_review', true);
        }

        $resolver = new CardPriceResolver;

        // Bounded by a real personal collection's size (dozens–low
        // hundreds) — same ceiling the old single-item 'value' sort
        // already assumed. Grouping-then-paginating can't be expressed as
        // a single SQL query here: market_minor lives on a separate
        // priceSnapshots relation CardPriceResolver walks in PHP, so the
        // aggregate a card-row sorts/pages by can only be computed after
        // every matching item is loaded.
        $allItems = $itemsQuery->limit(self::VALUE_SORT_ROW_LIMIT)->get();

        $groups = $allItems->groupBy('card_id')->map(function ($items) use ($resolver) {
            $valued = $items->map(function (CollectionItem $item) use ($resolver) {
                // resolveForVariant, not resolve(): each item IS a
                // specific variant — the card-level chain would price
                // every item for this card identically regardless of
                // which variant it actually is.
                $snapshot = $resolver->resolveForVariant($item->card, $item->variant);
                $item->setAttribute('_valueMinor', $snapshot?->market_minor);

                return $item;
            });

            return (object) [
                'card' => $valued->first()->card,
                'items' => $valued->sortBy('variant')->values(),
                'totalQuantity' => (int) $valued->sum('quantity'),
                'totalValueMinor' => (int) $valued->sum(
                    fn (CollectionItem $i) => ($i->_valueMinor ?? 0) * $i->quantity,
                ),
                'needsReview' => $valued->contains(fn (CollectionItem $i) => $i->needs_variant_review),
                'newestAt' => $valued->max('created_at'),
            ];
        })->values();

        $groups = match ($this->sort) {
            'name' => $groups->sortBy(fn ($g) => $g->card->name),
            'newest' => $groups->sortByDesc('newestAt'),
            default => $groups->sortByDesc('totalValueMinor'),
        }->values();

        $perPage = 24;
        $page = $this->getPage();
        $paged = $groups->slice(($page - 1) * $perPage, $perPage)->values();

        $cardGroups = new LengthAwarePaginator(
            $paged,
            $groups->count(),
            $perPage,
            $page,
            // This route only ever renders this one component — hardcode
            // it rather than trust the paginator's default path
            // inference, which resolved to "/" instead of "/admin" here.
            ['path' => route('admin.collection.index')],
        );

        return view('livewire.admin.collection-items', [
            'cardGroups' => $cardGroups,
            'totalCards' => $groups->count(),
            'totalCopies' => (int) $groups->sum('totalQuantity'),
            'resolver' => $resolver,
        ]);
    }
```

This removes the old `if ($this->sort === 'value')` branch entirely (grouping now always resolves value in PHP, for every sort) — delete it along with the old plain `$itemsQuery->paginate(...)` call it used to fall through to.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/sail php vendor/bin/pest --filter="groups into one row|two separate groups"`
Expected: PASS

- [ ] **Step 5: Write the failing view tests**

```php
test('the toolbar count reads distinct cards and total copies, not row count', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 2]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'NM', 'quantity' => 3]);

    Livewire::test(CollectionItems::class)
        ->assertSee('Showing')
        ->assertSee('1', false)
        ->assertSee('cards', false)
        ->assertSee('5', false)
        ->assertSee('copies', false);
});

test('a card row shows each owned variant as a chip with its quantity', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 2]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'LP', 'quantity' => 3]);

    Livewire::test(CollectionItems::class)
        ->assertSee('Mega Darkrai ex')
        ->assertSee('Normal · NM')
        ->assertSee('×2', false)
        ->assertSee('Holofoil · LP')
        ->assertSee('×3', false);
});
```

- [ ] **Step 6: Run tests to verify they fail**

Run: `./vendor/bin/sail php vendor/bin/pest --filter="toolbar count reads|owned variant as a chip"`
Expected: FAIL — the current view still renders the old per-item `$items` table.

- [ ] **Step 7: Rewrite the toolbar count and table body**

In `resources/views/livewire/admin/collection-items.blade.php`, replace line 25:

```blade
<div class="nw-count">Showing <b>{{ $items->total() }}</b> {{ Str::plural('card', $items->total()) }}</div>
```

with:

```blade
<div class="nw-count">Showing <b>{{ $totalCards }}</b> {{ Str::plural('card', $totalCards) }} <span style="opacity:.6">· {{ $totalCopies }} {{ Str::plural('copy', $totalCopies) }}</span></div>
```

Replace the whole `<table>` block (lines 67-164) with:

```blade
    <div class="nw-card overflow-hidden nw-table-responsive">
        <table class="w-full text-sm">
            <thead>
                <tr class="nw-topbar text-left">
                    <th class="p-3">Card</th>
                    <th class="p-3">Set</th>
                    <th class="p-3">Variants owned</th>
                    <th class="p-3">Total value</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($cardGroups as $group)
                    <tr wire:key="group-{{ $group->card->id }}" wire:click="openCardEditor({{ $group->card->id }})"
                        class="nw-stagger-item nw-row-hover border-t cursor-pointer" style="border-color: var(--hair); --nw-stagger-index: {{ min($loop->index, 10) }}">
                        <td class="p-3 font-medium nw-tcell-name" data-label="">
                            {{ $group->card->name }}
                            @if ($group->needsReview)
                                <span class="text-xs font-medium ml-2" style="color: var(--warning)" title="One or more variants need review">Review</span>
                            @endif
                        </td>
                        <td class="p-3" style="color: var(--muted)" data-label="Set">{{ $group->card->set->name }}</td>
                        <td class="p-3" data-label="Variants owned">
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($group->items as $item)
                                    <span class="nw-chip">
                                        {{ $item->variant ? \Illuminate\Support\Str::headline($item->variant) : '— unspecified' }} · {{ $item->condition }}
                                        <span class="mono" style="color: var(--muted)">×{{ $item->quantity }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </td>
                        <td class="p-3 mono" data-label="Total value">
                            {{ $group->totalValueMinor > 0 ? \App\Support\Money::format($group->totalValueMinor, 'USD') : '—' }}
                        </td>
                        <td class="p-3 text-right nw-tcell-actions" data-label="" onclick="event.stopPropagation()">
                            <button wire:click="openCardEditor({{ $group->card->id }})" class="nw-row-btn">Edit</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-6 text-center" style="color: var(--muted)">No cards yet — <a href="{{ route('admin.collection.add') }}" wire:navigate style="color: var(--ink); text-decoration: underline">add your first one</a>.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $cardGroups->links() }}
    </div>
```

Note the `onclick="event.stopPropagation()"` on the Actions cell — without it, clicking "Edit" would also fire the row's own `wire:click` and call `openCardEditor` twice in one Livewire request; harmless here since both calls are idempotent opens of the same modal, but stopping propagation keeps it to one network round-trip.

- [ ] **Step 8: Add the `.nw-chip` CSS class**

`resources/css/app.css` doesn't have a `.nw-chip` class yet (only `.chip`-shaped one-offs used in earlier mockups) — add it near `.nw-row-btn` (search for that class to find the right neighborhood):

```css
.nw-chip {
  display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 600;
  background: var(--bone-2); border: 1px solid var(--hair); border-radius: 999px; padding: 4px 10px;
  white-space: nowrap;
}
```

- [ ] **Step 9: Build assets and run all of this task's tests to verify they pass**

Run: `npm run build && ./vendor/bin/sail php vendor/bin/pest --filter="groups into one row|two separate groups|toolbar count reads|owned variant as a chip"`
Expected: PASS (all 4 tests)

- [ ] **Step 10: Run the full existing suite and note breakage (do not fix yet — Task 7 handles it)**

Run: `./vendor/bin/sail php vendor/bin/pest --filter=CollectionItemsTest`
Expected: the page itself now renders correctly for the grouped case (no crash), but several PRE-EXISTING tests fail because they call the old per-item modal/inline-edit actions (`startEditingItem`, `saveItem`, `startEditingQty`, `saveQty`, `confirmDelete`/`delete`) that still exist unchanged in the component — those methods and the old modal blade markup are only removed in Task 3, and the old tests exercising them are only migrated in Task 7. Confirm every failure is specifically about one of those old actions, not a crash in the new grouped rendering path.

- [ ] **Step 11: Commit**

```bash
git add app/Livewire/Admin/CollectionItems.php resources/views/livewire/admin/collection-items.blade.php resources/css/app.css tests/Feature/Livewire/Admin/CollectionItemsTest.php
git commit -m "feat(admin): group the collection table by card, with variant chips"
```

---

### Task 3: Card-level edit modal — open/close + list all variant rows

**Files:**
- Modify: `app/Livewire/Admin/CollectionItems.php` (remove `editingFullItemId`/`editingCondition`/`editingQuantity`/`editingVariant`/`editingGradeCompany`/`editingGradeValue`/`editingAvailableVariants`/`editingItemPhotoPathProperty`/`startEditingItem`/`cancelEditingItem`/`saveItem`, lines 100-135 and 256-361 of the current file; add the replacements below)
- Modify: `resources/views/livewire/admin/collection-items.blade.php:171-241` (replace the old per-item edit modal)
- Test: `tests/Feature/Livewire/Admin/CollectionItemsTest.php`

**Interfaces:**
- Produces: `openCardEditor(int $cardId): void`, `closeCardEditor(): void`, public property `editingCardId` (`?int`) and `editingRows` (`array<int, array{id:int, variant:?string, condition:string, quantity:int, grade_company:?string, grade_value:?string, notes:?string, showDetails:bool}>`), `editingAvailableVariants` (`array<int,string>`) — Tasks 4-6 add actions that read/write `editingRows`.
- Consumes: existing `ownedItemOrFail()` (`CollectionItems.php:174-184`, unchanged) and `CardVariants::available()`.

- [ ] **Step 1: Write the failing test**

```php
test('opening the card editor lists every owned variant as an editable row', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $normal = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 2]);
    $holo = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'LP', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->assertSet('editingCardId', $card->id);

    $rows = collect($test->get('editingRows'));
    expect($rows->pluck('id')->sort()->values()->all())->toBe([$normal->id, $holo->id]);
    expect($rows->firstWhere('id', $normal->id)['quantity'])->toBe(2);
});

test('a user cannot open another users card in the editor (IDOR)', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($owner)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->assertStatus(404);
});

test('closing the card editor clears its state', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->call('closeCardEditor')
        ->assertSet('editingCardId', null)
        ->assertSet('editingRows', []);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail php vendor/bin/pest --filter="opening the card editor|cannot open another users card|closing the card editor"`
Expected: FAIL — `openCardEditor` doesn't exist yet.

- [ ] **Step 3: Remove the old per-item modal properties and methods**

In `app/Livewire/Admin/CollectionItems.php`, delete:
- Properties `$editingFullItemId`, `$editingCondition`, `$editingQuantity`, `$editingVariant`, `$editingGradeCompany`, `$editingGradeValue`, `$editingPhoto`, `$editingAvailableVariants` (lines 109-147)
- The `KNOWN_VARIANTS` constant stays (still used below) but move it up if needed for ordering.
- Methods `startEditingItem()`, `getEditingItemPhotoPathProperty()`, `cancelEditingItem()`, `saveItem()` (lines 256-361)

Also delete the inline Notes/Qty editing (`$editingItemId`, `$editingNotes`, `$editingQtyItemId`, `$editingQtyValue`, `startEditingNotes()`, `saveNotes()`, `startEditingQty()`, `saveQty()`, lines 100-107 and 186-220) — these edited a single item inline in the flat table, which no longer exists; editing quantity/notes now happens through the card modal's rows (Task 4).

- [ ] **Step 4: Add the card-modal open/close state and methods**

Add after the existing `ownedItemOrFail()` method:

```php
    public ?int $editingCardId = null;

    /**
     * @var array<int, array{id:int, variant:?string, condition:string, quantity:int, grade_company:?string, grade_value:?string, notes:?string, showDetails:bool}>
     */
    public array $editingRows = [];

    /** @var array<int, string> */
    public array $editingAvailableVariants = [];

    /**
     * Same IDOR posture as ownedItemOrFail() (CollectionItems.php:174-184),
     * scoped to every item of one card instead of a single item ID — walks
     * through Collection (which carries TenantScope) so a card_id with no
     * items in the caller's OWN collection throws a 404, never a 403 that
     * would confirm the card exists in someone else's.
     */
    private function ownedCardItemsOrFail(int $cardId): \Illuminate\Support\Collection
    {
        $collectionIds = Collection::query()->pluck('id');
        $items = CollectionItem::where('card_id', $cardId)
            ->whereIn('collection_id', $collectionIds)
            ->with('card')
            ->get();

        if ($items->isEmpty()) {
            throw new NotFoundHttpException;
        }

        return $items;
    }

    public function openCardEditor(int $cardId): void
    {
        $items = $this->ownedCardItemsOrFail($cardId);

        $this->editingCardId = $cardId;
        $this->editingRows = $items->map(fn (CollectionItem $i) => [
            'id' => $i->id,
            'variant' => $i->variant,
            'condition' => $i->condition,
            'quantity' => $i->quantity,
            'grade_company' => $i->grade_company,
            'grade_value' => $i->grade_value,
            'notes' => $i->notes,
            'showDetails' => false,
        ])->values()->all();

        $card = $items->first()->card;

        // Same variant-sourcing priority as the old startEditingItem():
        // the card's own tcgdex print flags first, synced pricing
        // coverage only as a fallback for fixtures/pre-`variants` cards.
        $variants = CardVariants::available($card->variants ?? []);
        if ($variants === []) {
            $variants = array_values(array_intersect(
                self::KNOWN_VARIANTS,
                CardPriceSnapshot::where('card_id', $card->id)->distinct()->pluck('variant')->all(),
            ));
        }
        $this->editingAvailableVariants = $variants;
    }

    public function closeCardEditor(): void
    {
        $this->editingCardId = null;
        $this->editingRows = [];
        $this->editingAvailableVariants = [];
    }
```

- [ ] **Step 5: Replace the old per-item modal in the Blade view**

In `resources/views/livewire/admin/collection-items.blade.php`, delete the old edit modal block (lines 171-241) and add, after the table:

```blade
    @if ($editingCardId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(20,20,18,.55)" wire:click.self="closeCardEditor">
            <div class="nw-card w-full" style="max-width: 640px; max-height: 90vh; overflow-y: auto;">
                <div class="flex justify-between items-start p-5" style="border-bottom: 1px solid var(--hair)">
                    <div>
                        <div class="text-lg font-bold">{{ $editingRows[0]['id'] ?? null ? \App\Modules\Collection\Models\CollectionItem::find($editingRows[0]['id'])?->card?->name : '' }}</div>
                    </div>
                    <button wire:click="closeCardEditor" class="nw-row-btn" aria-label="Close">✕</button>
                </div>

                @foreach ($editingRows as $index => $row)
                    @include('livewire.admin.partials.variant-row', [
                        'namePrefix' => "editingRows.$index",
                        'row' => $row,
                        'availableVariants' => $editingAvailableVariants,
                        'onRemove' => "removeVariantRow($index)",
                        'onUpdate' => fn (string $field) => "updateRow($index, '$field')",
                    ])
                @endforeach

                <div class="p-4 flex justify-center">
                    <button wire:click="addVariantRow" class="nw-btn-secondary w-full" style="border-style: dashed;">+ Add another variant to this card</button>
                </div>

                <div class="p-5 flex justify-end" style="border-top: 1px solid var(--hair)">
                    <button wire:click="closeCardEditor" class="nw-btn-primary">Done</button>
                </div>
            </div>
        </div>
    @endif
```

`namePrefix`/`onUpdate` line up with the shared partial Task 4 creates — this step only needs the modal shell to exist and render its rows, so leave the partial as a simple placeholder for now:

Create `resources/views/livewire/admin/partials/variant-row.blade.php`:

```blade
@php
    // Placeholder until Task 4 fills in the real fields — exists now
    // only so this task's tests (which check for the card name and row
    // count, not field markup) pass without a missing-view error.
@endphp
<div class="p-3" style="border-bottom: 1px solid var(--hair)">{{ $row['variant'] ?? '— unspecified' }} · {{ $row['condition'] }} × {{ $row['quantity'] }}</div>
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/sail php vendor/bin/pest --filter="opening the card editor|cannot open another users card|closing the card editor"`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Admin/CollectionItems.php resources/views/livewire/admin/collection-items.blade.php resources/views/livewire/admin/partials/variant-row.blade.php tests/Feature/Livewire/Admin/CollectionItemsTest.php
git commit -m "feat(admin): replace the per-item edit modal with a card-scoped one"
```

---

### Task 4: Editar — per-field autosave with a fading "Saved" tag

**Files:**
- Modify: `app/Livewire/Admin/CollectionItems.php` (add `updateRow()`)
- Modify: `resources/views/livewire/admin/partials/variant-row.blade.php` (real fields, replacing Task 3's placeholder)
- Modify: `resources/css/app.css` (the `.autosave-tag` styling, ported from the visual-companion mockup)
- Modify: `resources/js/app.js` (fade the tag on the `row-saved` event)
- Test: `tests/Feature/Livewire/Admin/CollectionItemsTest.php`

**Interfaces:**
- Consumes: `editingRows` from Task 3.
- Produces: `updateRow(int $index): void` — validates and persists one row's editable fields immediately; dispatches a `row-saved` browser event with `{index}`.

- [ ] **Step 1: Write the failing test**

```php
test('editing a rows quantity in the card modal autosaves it immediately', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.quantity', 5)
        ->call('updateRow', 0)
        ->assertDispatched('row-saved', index: 0);

    expect($item->fresh()->quantity)->toBe(5);
});

test('updateRow rejects a quantity below 1 without saving it', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->set('editingRows.0.quantity', 0)
        ->call('updateRow', 0)
        ->assertHasErrors(['editingRows.0.quantity']);

    expect($item->fresh()->quantity)->toBe(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail php vendor/bin/pest --filter="autosaves it immediately|rejects a quantity below 1"`
Expected: FAIL — `updateRow` doesn't exist yet.

- [ ] **Step 3: Add `updateRow()`**

```php
    protected function rules(): array
    {
        return [
            'editingRows.*.variant' => 'nullable|in:normal,holofoil,reverse-holofoil',
            'editingRows.*.condition' => 'required|string|max:16',
            'editingRows.*.quantity' => 'required|integer|min:1',
            'editingRows.*.grade_company' => 'nullable|string|max:32',
            'editingRows.*.grade_value' => 'nullable|string|max:16',
            'editingRows.*.notes' => 'nullable|string|max:2000',
        ];
    }

    public function updateRow(int $index): void
    {
        if (! isset($this->editingRows[$index])) {
            return;
        }

        $this->validateOnly("editingRows.$index.variant");
        $this->validateOnly("editingRows.$index.condition");
        $this->validateOnly("editingRows.$index.quantity");
        $this->validateOnly("editingRows.$index.grade_company");
        $this->validateOnly("editingRows.$index.grade_value");
        $this->validateOnly("editingRows.$index.notes");

        $row = $this->editingRows[$index];
        $item = $this->ownedItemOrFail($row['id']);

        $item->update([
            'variant' => $row['variant'],
            'condition' => $row['condition'],
            'quantity' => $row['quantity'],
            'grade_company' => $row['grade_company'],
            'grade_value' => $row['grade_value'],
            'notes' => $row['notes'],
            // Assigning a real variant resolves the importer's ambiguity
            // flag — same rule as the old saveItem() (CollectionItems.php:342).
            'needs_variant_review' => $row['variant'] !== null ? false : $item->needs_variant_review,
        ]);

        $this->dispatch('row-saved', index: $index);
    }

    public function toggleRowDetails(int $index): void
    {
        if (isset($this->editingRows[$index])) {
            $this->editingRows[$index]['showDetails'] = ! $this->editingRows[$index]['showDetails'];
        }
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/sail php vendor/bin/pest --filter="autosaves it immediately|rejects a quantity below 1"`
Expected: PASS

- [ ] **Step 5: Fill in the real variant-row partial**

Replace `resources/views/livewire/admin/partials/variant-row.blade.php` entirely with:

```blade
@php
    // $namePrefix e.g. "editingRows.0" (Editar) or "rows.0" (Crear, Task 8) —
    // lets the same markup back both wire:model targets. $onUpdate/$onRemove
    // are the exact wire:click/wire:blur action strings each caller wants;
    // Crear passes null for $onUpdate (Task 8 saves the whole batch once,
    // not per-field) and a different remove action.
@endphp
<div class="p-4" style="border-bottom: 1px solid var(--hair); display: grid; grid-template-columns: 1fr 1fr 70px auto; gap: 10px; align-items: end; position: relative;" wire:key="{{ $namePrefix }}">
    <div wire:loading.class="opacity-50" wire:target="{{ $onUpdate ? $onUpdate('variant') : $namePrefix }}">
        <label class="nw-label block mb-1">Variant</label>
        <select wire:model="{{ $namePrefix }}.variant" @if($onUpdate) wire:change="{{ $onUpdate('variant') }}" @endif class="nw-input w-full">
            <option value="">— not specified —</option>
            @foreach ($availableVariants as $v)
                <option value="{{ $v }}">{{ \Illuminate\Support\Str::headline($v) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="nw-label block mb-1">Condition</label>
        <select wire:model="{{ $namePrefix }}.condition" @if($onUpdate) wire:change="{{ $onUpdate('condition') }}" @endif class="nw-input w-full">
            <option value="NM">Near Mint</option>
            <option value="LP">Lightly Played</option>
            <option value="MP">Moderately Played</option>
            <option value="HP">Heavily Played</option>
            <option value="DMG">Damaged</option>
        </select>
    </div>
    <div>
        <label class="nw-label block mb-1">Qty</label>
        <input type="number" min="1" wire:model="{{ $namePrefix }}.quantity" @if($onUpdate) wire:blur="{{ $onUpdate('quantity') }}" @endif class="nw-input w-full">
    </div>
    <button type="button" @if($onRemove) wire:click="{{ $onRemove }}" @endif class="nw-row-btn nw-row-btn--danger" title="Remove this variant" style="height: 34px;">✕</button>

    <div style="grid-column: 1 / -1;">
        <button type="button" wire:click="toggleRowDetails({{ $rowIndex }})" class="text-xs" style="color: var(--muted); text-decoration: underline; text-decoration-style: dashed;">
            {{ $row['showDetails'] ? 'Hide' : 'Add' }} grading, notes, or a photo
        </button>
    </div>

    @if ($row['showDetails'])
        <div style="grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 8px;">
            <div>
                <label class="nw-label block mb-1">Grading company</label>
                <input type="text" wire:model="{{ $namePrefix }}.grade_company" @if($onUpdate) wire:blur="{{ $onUpdate('grade_company') }}" @endif class="nw-input w-full" placeholder="PSA, BGS...">
            </div>
            <div>
                <label class="nw-label block mb-1">Grade</label>
                <input type="text" wire:model="{{ $namePrefix }}.grade_value" @if($onUpdate) wire:blur="{{ $onUpdate('grade_value') }}" @endif class="nw-input w-full" placeholder="9, 10...">
            </div>
            <div style="grid-column: 1 / -1;">
                <label class="nw-label block mb-1">Notes</label>
                <textarea wire:model="{{ $namePrefix }}.notes" @if($onUpdate) wire:blur="{{ $onUpdate('notes') }}" @endif rows="2" class="nw-input w-full"></textarea>
            </div>
        </div>
    @endif

    <span data-autosave-tag class="autosave-tag" style="display:none;">Saved</span>
</div>
```

Note this partial needs `$rowIndex` too (used by `toggleRowDetails`) — update the `@include` calls in `collection-items.blade.php` (Task 3) to also pass `'rowIndex' => $index`, and add the matching `'rowIndex' => $index` when Task 8 wires this same partial into Crear.

Add `.nw-label` if it doesn't already exist as a reusable class (check `resources/css/app.css` for an existing `.nw-label` rule first — the codebase already has one per the earlier investigation of `app.css:150` (`.nw-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.14em; color: var(--muted); }`) — reuse it as-is, no new CSS needed for labels).

Add the autosave tag CSS (from the approved visual-companion mockup) near `.nw-chip`:

```css
.autosave-tag {
  position: absolute; right: 16px; top: 6px; font-size: 9px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: var(--signal-deep); display: flex; align-items: center; gap: 4px;
}
.autosave-tag::before { content: ''; width: 5px; height: 5px; border-radius: 999px; background: var(--signal-deep); }
```

- [ ] **Step 6: Fade the tag on `row-saved`, following the existing vanilla-JS re-bind pattern**

In `resources/js/app.js`, add (same `livewire:navigated` re-bind convention every other listener in this file already uses):

```js
// The "Saved" tag next to an autosaved field (variant-row partial) —
// shown for ~2s and faded, mirroring the discreet feedback the old
// inline Qty/Notes editing gave with no persistent banner.
document.addEventListener('row-saved', (event) => {
    const rows = document.querySelectorAll(`[wire\\:key="editingRows.${event.detail.index}"]`);
    rows.forEach((row) => {
        const tag = row.querySelector('[data-autosave-tag]');
        if (!tag) return;
        tag.style.display = 'flex';
        tag.style.opacity = '1';
        clearTimeout(tag._fadeTimer);
        tag._fadeTimer = setTimeout(() => { tag.style.opacity = '0'; }, 1600);
    });
});
```

Add the matching transition once, next to `.autosave-tag`'s other rules in `app.css`:

```css
.autosave-tag { transition: opacity 400ms var(--ease); }
```

- [ ] **Step 7: Build assets and run tests**

Run: `npm run build && ./vendor/bin/sail php vendor/bin/pest --filter="autosaves it immediately|rejects a quantity below 1"`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Admin/CollectionItems.php resources/views/livewire/admin/partials/variant-row.blade.php resources/css/app.css resources/js/app.js tests/Feature/Livewire/Admin/CollectionItemsTest.php
git commit -m "feat(admin): autosave each variant row's fields with a fading confirmation"
```

---

### Task 5: Editar — add a variant row instantly

**Files:**
- Modify: `app/Livewire/Admin/CollectionItems.php` (add `addVariantRow()`)
- Test: `tests/Feature/Livewire/Admin/CollectionItemsTest.php`

**Interfaces:**
- Consumes: `CollectionService::addItem()` (unchanged), `editingRows`/`editingCardId` from Task 3.
- Produces: `addVariantRow(CollectionService $service): void`.

- [ ] **Step 1: Write the failing test**

```php
test('adding a variant row creates a new item and appends it to the modal instantly', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->call('addVariantRow');

    expect($test->get('editingRows'))->toHaveCount(2);
    expect(CollectionItem::where('card_id', $card->id)->count())->toBe(2);
});

test('adding a variant row that collides with one already open reflects the bumped quantity instead of silently doing nothing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    // A row that's already the exact identity addVariantRow() creates
    // (unspecified variant, NM) — CollectionService::addItem()'s existing
    // merge-by-identity means the "new" row is really a quantity bump on
    // this one.
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id)
        ->call('addVariantRow');

    expect($test->get('editingRows'))->toHaveCount(1);
    expect($test->get('editingRows')[0]['quantity'])->toBe(2);
    expect($item->fresh()->quantity)->toBe(2);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail php vendor/bin/pest --filter="adding a variant row"`
Expected: FAIL — `addVariantRow` doesn't exist yet.

- [ ] **Step 3: Add `addVariantRow()`**

```php
    public function addVariantRow(\App\Modules\Collection\Services\CollectionService $service): void
    {
        if ($this->editingCardId === null) {
            return;
        }

        // The Catalog is global and not tenant-scoped (CollectionService.php:23-24)
        // — this is a straight lookup of catalog data, not a user's own
        // record, so it doesn't go through ownedItemOrFail()/ownedCardItemsOrFail().
        $card = \App\Modules\Catalog\Models\Card::findOrFail($this->editingCardId);
        $collection = $this->resolveCollection();

        $item = $service->addItem($collection, $card->tcgdex_id, ['condition' => 'NM', 'quantity' => 1]);

        $existingIndex = collect($this->editingRows)->search(fn ($row) => $row['id'] === $item->id);

        if ($existingIndex !== false) {
            // addItem() merged into a row already open in this modal (an
            // unspecified-variant/NM row already existed) — reflect its
            // bumped quantity instead of silently doing nothing visible.
            $this->editingRows[$existingIndex]['quantity'] = $item->quantity;

            return;
        }

        $this->editingRows[] = [
            'id' => $item->id,
            'variant' => null,
            'condition' => 'NM',
            'quantity' => 1,
            'grade_company' => null,
            'grade_value' => null,
            'notes' => null,
            'showDetails' => false,
        ];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail php vendor/bin/pest --filter="adding a variant row"`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Admin/CollectionItems.php tests/Feature/Livewire/Admin/CollectionItemsTest.php
git commit -m "feat(admin): add a variant row to the card editor instantly"
```

---

### Task 6: Editar — remove a variant row instantly, with a confirm step

**Files:**
- Modify: `app/Livewire/Admin/CollectionItems.php` (add `confirmRemoveRow()`, `cancelRemoveRow()`, `removeVariantRow()`)
- Modify: `resources/views/livewire/admin/collection-items.blade.php` (small inline confirm, replacing the `del` button's bare click)
- Test: `tests/Feature/Livewire/Admin/CollectionItemsTest.php`

**Interfaces:**
- Consumes: `editingRows` from Task 3, `Storage::disk('collection-photos')` (same cleanup the old `delete()` already does at `CollectionItems.php:246-250`).
- Produces: `confirmRemoveRow(int $index): void`, `cancelRemoveRow(): void`, `removeVariantRow(int $index): void`, public property `confirmingRemoveRowIndex` (`?int`).

- [ ] **Step 1: Write the failing test**

```php
test('removing a variant row deletes the item and its stored photo', function () {
    Storage::fake('collection-photos');
    Storage::disk('collection-photos')->put('card.jpg', 'fake-image-bytes');

    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $normal = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'normal', 'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'card.jpg']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'variant' => 'holofoil', 'condition' => 'NM', 'quantity' => 1]);

    $test = Livewire::test(CollectionItems::class)
        ->call('openCardEditor', $card->id);

    $normalIndex = collect($test->get('editingRows'))->search(fn ($r) => $r['id'] === $normal->id);

    $test->call('confirmRemoveRow', $normalIndex)
        ->assertSet('confirmingRemoveRowIndex', $normalIndex)
        ->call('removeVariantRow', $normalIndex)
        ->assertSet('confirmingRemoveRowIndex', null);

    expect(CollectionItem::find($normal->id))->toBeNull();
    Storage::disk('collection-photos')->assertMissing('card.jpg');
    expect(collect($test->get('editingRows')))->toHaveCount(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail php vendor/bin/pest --filter="removing a variant row"`
Expected: FAIL — the new methods don't exist yet.

- [ ] **Step 3: Add the remove-row methods**

```php
    public ?int $confirmingRemoveRowIndex = null;

    public function confirmRemoveRow(int $index): void
    {
        $this->confirmingRemoveRowIndex = $index;
    }

    public function cancelRemoveRow(): void
    {
        $this->confirmingRemoveRowIndex = null;
    }

    public function removeVariantRow(int $index): void
    {
        if (! isset($this->editingRows[$index])) {
            return;
        }

        $item = $this->ownedItemOrFail($this->editingRows[$index]['id']);

        if ($item->photo_path) {
            Storage::disk('collection-photos')->delete($item->photo_path);
        }
        $item->delete();

        unset($this->editingRows[$index]);
        $this->editingRows = array_values($this->editingRows);
        $this->confirmingRemoveRowIndex = null;
    }
```

- [ ] **Step 4: Wire the confirm step into the variant-row partial**

In `resources/views/livewire/admin/partials/variant-row.blade.php`, replace the bare remove button:

```blade
<button type="button" @if($onRemove) wire:click="{{ $onRemove }}" @endif class="nw-row-btn nw-row-btn--danger" title="Remove this variant" style="height: 34px;">✕</button>
```

with a two-step confirm (only meaningful in Editar, where `$onRemove` is `confirmRemoveRow($index)` — Task 8's Crear rows pass a different, non-confirming remove action since nothing is persisted there yet):

```blade
@if (($confirmingRemoveRowIndex ?? null) === $rowIndex)
    <div style="display:flex; gap:4px;">
        <button type="button" wire:click="removeVariantRow({{ $rowIndex }})" class="nw-row-btn nw-row-btn--danger" style="height: 34px;">Confirm</button>
        <button type="button" wire:click="cancelRemoveRow" class="nw-row-btn" style="height: 34px;">✕</button>
    </div>
@else
    <button type="button" @if($onRemove) wire:click="{{ $onRemove }}" @endif class="nw-row-btn nw-row-btn--danger" title="Remove this variant" style="height: 34px;">✕</button>
@endif
```

Update the `@include` in `collection-items.blade.php` (Task 3) to pass `'onRemove' => "confirmRemoveRow($index)"` and the new `'confirmingRemoveRowIndex' => $confirmingRemoveRowIndex` context variable.

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/sail php vendor/bin/pest --filter="removing a variant row"`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Admin/CollectionItems.php resources/views/livewire/admin/partials/variant-row.blade.php resources/views/livewire/admin/collection-items.blade.php tests/Feature/Livewire/Admin/CollectionItemsTest.php
git commit -m "feat(admin): remove a variant row instantly, with an inline confirm step"
```

---

### Task 7: Migrate the pre-existing CollectionItems tests to the new API

**Files:**
- Modify: `tests/Feature/Livewire/Admin/CollectionItemsTest.php`

**Interfaces:**
- Consumes: all interfaces from Tasks 1-6 — this task only updates test call sites, no production code changes.

This task cleans up the breakage Task 1's Step 10 flagged. Apply this exact mechanical substitution to each named test:

- Any assertion against `$items`/pagination of individual items (e.g. `'the collection index lists the authenticated users items'`) — no change needed, `assertSee('Mega Darkrai ex')`/`assertSee('NM')` still passes against the grouped view; only re-run to confirm.
- `'the value column prices each row at its OWN variant, not the card-level default for every row'` — no change needed, the chip text still shows both `$0.16`/`$0.27` on the (now single, grouped) row; re-run to confirm.
- `'an admin can update an items notes'` — replace:
  ```php
  Livewire::test(CollectionItems::class)
      ->call('startEditingNotes', $item->id)
      ->set('editingNotes', 'Bought at a con')
      ->call('saveNotes');
  ```
  with:
  ```php
  $test = Livewire::test(CollectionItems::class)->call('openCardEditor', $card->id);
  $index = collect($test->get('editingRows'))->search(fn ($r) => $r['id'] === $item->id);
  $test->set("editingRows.$index.notes", 'Bought at a con')->call('updateRow', $index);
  ```
- `'an item with a photo shows a thumbnail in the rendered view'` / `'an item with no photo renders no image tag for it'` — these asserted a thumbnail in the flat table, which Task 1's grouped row no longer renders (chips have no per-item image slot, matching the approved chip mockup). Delete both tests — the photo's presence is now only relevant inside the card modal via `showDetails`, not covered by this plan's chip UI at all (no mockup showed a photo in a chip).
- `'deleting an item removes its stored photo from disk'` — replace the old `delete()`/`confirmDelete()` call sequence with `openCardEditor` → `confirmRemoveRow` → `removeVariantRow`, same shape as Task 6's own test above (reuse that exact pattern).
- Any other test in this file calling `startEditingItem`, `saveItem`, `startEditingQty`, `saveQty`, `confirmDelete`/`cancelDelete`/`delete` directly — apply the same `openCardEditor` + row-index-lookup + new-method-name substitution shown above.

- [ ] **Step 1: Apply the substitutions above to every affected test**

- [ ] **Step 2: Run the full file**

Run: `./vendor/bin/sail php vendor/bin/pest --filter=CollectionItemsTest`
Expected: PASS — every test in the file, old and new.

- [ ] **Step 3: Run Pint**

Run: `./vendor/bin/sail php vendor/bin/pint`
Expected: `{"tool":"pint","result":"passed"}`

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Livewire/Admin/CollectionItemsTest.php
git commit -m "test(admin): migrate CollectionItems tests to the card-scoped editor API"
```

---

### Task 8: Crear — repeatable variant rows, single-submission save

**Files:**
- Modify: `app/Livewire/Admin/AddCollectionItem.php` (replace the flat `variant`/`condition`/`quantity`/`gradeCompany`/`gradeValue`/`notes`/`photo` properties and `save()`, lines 93-125 and 261-309)
- Modify: `resources/views/livewire/admin/add-collection-item.blade.php` (replace the single-variant form section, lines 70-118)
- Test: `tests/Feature/Livewire/Admin/AddCollectionItemTest.php`

**Interfaces:**
- Consumes: the shared `resources/views/livewire/admin/partials/variant-row.blade.php` from Tasks 4/6 (this task's rows pass `onUpdate: null` since Crear saves the whole batch on submit, not per-field, and a plain `removeRow($index)` for `onRemove` — a row not yet saved to the database, so nothing to confirm-delete).
- Produces: public property `rows` (same row shape as `editingRows`, minus `id`), `addRow(): void`, `removeRow(int $index): void`, `toggleRowDetails(int $index): void` (same name/shape as `CollectionItems`'s — both components independently own this method since they're separate Livewire components, not shared state).

- [ ] **Step 1: Write the failing test**

```php
test('submitting 3 rows for one new card creates 3 items in one request', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->andReturn(new CardDetailData(
        tcgdexId: 'sv05-050', setTcgdexId: 'sv05', localId: '050', name: 'Iron Hands ex',
        rarity: 'SIR', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $provider->shouldReceive('findSet')->andReturn(new SetSummaryData(
        tcgdexId: 'sv05', name: 'Temporal Forces', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class, ['collectionId' => $collection->id])
        ->call('selectCard', 'sv05-050')
        ->set('rows.0.variant', 'normal')
        ->set('rows.0.quantity', 2)
        ->call('addRow')
        ->set('rows.1.variant', 'holofoil')
        ->set('rows.1.quantity', 1)
        ->call('save')
        ->assertRedirect();

    expect(CollectionItem::where('card_tcgdex_id', 'sv05-050')->count())->toBe(2);
    expect(CollectionItem::where('card_tcgdex_id', 'sv05-050')->where('variant', 'normal')->first()->quantity)->toBe(2);
});

test('two rows with the identical variant/condition in one submission fail validation and save nothing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->andReturn(new CardDetailData(
        tcgdexId: 'sv05-050', setTcgdexId: 'sv05', localId: '050', name: 'Iron Hands ex',
        rarity: 'SIR', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $provider->shouldReceive('findSet')->andReturn(new SetSummaryData(
        tcgdexId: 'sv05', name: 'Temporal Forces', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(AddCollectionItem::class, ['collectionId' => $collection->id])
        ->call('selectCard', 'sv05-050')
        ->set('rows.0.variant', 'normal')
        ->call('addRow')
        ->set('rows.1.variant', 'normal')
        ->call('save')
        ->assertHasErrors(['rows.1.variant']);

    expect(CollectionItem::where('card_tcgdex_id', 'sv05-050')->count())->toBe(0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail php vendor/bin/pest --filter="creates 3 items in one request|identical variant/condition"`
Expected: FAIL — `rows`/`addRow` don't exist yet.

- [ ] **Step 3: Replace the flat properties with `rows[]`**

In `app/Livewire/Admin/AddCollectionItem.php`, delete lines 93-125 (`$variant` through `$photo`) and replace with:

```php
    /**
     * One row per variant/condition/grading combo being added for the
     * selected card in this single submission — @see save(). Same shape
     * as CollectionItems::$editingRows, minus `id` (nothing here is
     * persisted yet).
     *
     * @var array<int, array{variant: ?string, condition: string, quantity: int, grade_company: ?string, grade_value: ?string, notes: ?string, photo: mixed, showDetails: bool}>
     */
    public array $rows = [];

    private function blankRow(): array
    {
        return [
            'variant' => null,
            'condition' => 'NM',
            'quantity' => 1,
            'grade_company' => null,
            'grade_value' => null,
            'notes' => null,
            'photo' => null,
            'showDetails' => false,
        ];
    }

    protected function rules(): array
    {
        return [
            'rows.*.variant' => 'nullable|in:normal,holofoil,reverse-holofoil',
            'rows.*.condition' => 'required|string|max:16',
            'rows.*.quantity' => 'required|integer|min:1',
            'rows.*.grade_company' => 'nullable|string|max:32',
            'rows.*.grade_value' => 'nullable|string|max:16',
            'rows.*.notes' => 'nullable|string|max:2000',
            'rows.*.photo' => 'nullable|image|mimes:jpeg,png,webp|max:5120',
        ];
    }

    public function addRow(): void
    {
        $this->rows[] = $this->blankRow();
    }

    public function removeRow(int $index): void
    {
        if (count($this->rows) <= 1 || ! isset($this->rows[$index])) {
            return;
        }
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    public function toggleRowDetails(int $index): void
    {
        if (isset($this->rows[$index])) {
            $this->rows[$index]['showDetails'] = ! $this->rows[$index]['showDetails'];
        }
    }
```

Update `mount()` to seed the first row:

```php
    public function mount(): void
    {
        $this->availableSets = Set::orderBy('name')->pluck('name', 'tcgdex_id')->all();
        $this->rows = [$this->blankRow()];
    }
```

In `selectCard()`, replace the tail (lines 253-258, the single-variant auto-select):

```php
        // Only one real variant for this card and nothing chosen yet —
        // default to it instead of making the user pick from a single
        // option. Never overrides an existing value.
        if ($this->variant === null && count($this->availableVariants) === 1) {
            $this->variant = $this->availableVariants[0];
        }
```

with:

```php
        // A newly-selected card starts a fresh single row — rows typed
        // for whatever card was selected before must not carry over.
        $this->rows = [$this->blankRow()];

        if (count($this->availableVariants) === 1) {
            $this->rows[0]['variant'] = $this->availableVariants[0];
        }
```

- [ ] **Step 4: Replace `save()`**

```php
    public function save(\App\Modules\Collection\Services\CollectionService $service): mixed
    {
        $this->validate();

        if ($this->selectedTcgdexId === null) {
            $this->addError('selectedTcgdexId', 'Choose a card from the search results first.');

            return null;
        }

        // Reject two rows in this submission that would collide on the
        // same identity CollectionService::addItem() merges on — silently
        // merging two rows the user typed side by side would drop one of
        // them with no visible error.
        $seen = [];
        foreach ($this->rows as $index => $row) {
            $key = implode('|', [$row['variant'] ?? '', $row['condition'], $row['grade_company'] ?? '', $row['grade_value'] ?? '']);
            if (isset($seen[$key])) {
                $this->addError("rows.$index.variant", 'This is the same variant/condition/grading as another row above — combine them into one row instead.');

                return null;
            }
            $seen[$key] = true;
        }

        try {
            $collection = $this->collectionId !== null
                ? \App\Modules\Collection\Models\Collection::findOrFail($this->collectionId)
                : \App\Modules\Collection\Models\Collection::firstOrCreate(
                    ['user_id' => auth()->id(), 'slug' => 'my-collection'],
                    ['name' => 'My Collection', 'is_public' => false],
                );

            \Illuminate\Support\Facades\DB::transaction(function () use ($service, $collection): void {
                foreach ($this->rows as $row) {
                    $photoPath = null;
                    if ($row['photo']) {
                        $photoPath = basename($row['photo']->store('/', 'collection-photos'));
                    }

                    $service->addItem($collection, $this->selectedTcgdexId, [
                        'variant' => $row['variant'],
                        'condition' => $row['condition'],
                        'grade_company' => $row['grade_company'],
                        'grade_value' => $row['grade_value'],
                        'quantity' => $row['quantity'],
                        'notes' => $row['notes'],
                        'photo_path' => $photoPath,
                    ]);
                }
            });
        } catch (Throwable $e) {
            report($e);

            $this->addError('selectedTcgdexId', 'Could not add this card right now. Please try again.');

            return null;
        }

        return redirect()->route('admin.collection.index');
    }
```

- [ ] **Step 5: Update the Blade view**

In `resources/views/livewire/admin/add-collection-item.blade.php`, replace the form section (lines 70-118, from the "Condition/Quantity/Variant/Grading" grid through the photo input) with:

```blade
        @if ($selectedTcgdexId)
            @foreach ($rows as $index => $row)
                @include('livewire.admin.partials.variant-row', [
                    'namePrefix' => "rows.$index",
                    'row' => $row,
                    'rowIndex' => $index,
                    'availableVariants' => $availableVariants,
                    'onRemove' => count($rows) > 1 ? "removeRow($index)" : null,
                    'onUpdate' => null,
                ])
            @endforeach

            <div class="mb-4">
                <button type="button" wire:click="addRow" class="nw-btn-secondary w-full" style="border-style: dashed;">+ Add another variant of this same card</button>
            </div>

            <button type="button" wire:click="save" class="nw-btn-primary w-full">Save {{ count($rows) }} {{ Str::plural('variant', count($rows)) }} to collection</button>
        @endif
```

(This drops the standalone "Notes"/"Your own photo" sections that used to sit below the old single form — they now live per-row behind each row's Details toggle, per the spec's explicit resolution of that gap.)

- [ ] **Step 6: Run the new tests to verify they pass**

Run: `./vendor/bin/sail php vendor/bin/pest --filter="creates 3 items in one request|identical variant/condition"`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Admin/AddCollectionItem.php resources/views/livewire/admin/add-collection-item.blade.php tests/Feature/Livewire/Admin/AddCollectionItemTest.php
git commit -m "feat(admin): add multiple variants of a new card in one submission"
```

---

### Task 9: Migrate the pre-existing AddCollectionItem tests to the new API

**Files:**
- Modify: `tests/Feature/Livewire/Admin/AddCollectionItemTest.php`

**Interfaces:**
- Consumes: all interfaces from Task 8 — this task only updates test call sites.

Apply this exact mechanical substitution to every test in the file that sets one of the old flat properties (`condition`, `quantity`, `variant`, `gradeCompany`, `gradeValue`, `notes`, `photo`) before calling `save`:

- Any `->set('condition', $x)` → `->set('rows.0.condition', $x)`
- Any `->set('quantity', $x)` → `->set('rows.0.quantity', $x)`
- Any `->set('variant', $x)` → `->set('rows.0.variant', $x)`
- Any `->set('gradeCompany', $x)` → `->set('rows.0.grade_company', $x)`
- Any `->set('gradeValue', $x)` → `->set('rows.0.grade_value', $x)`
- Any `->set('notes', $x)` → `->set('rows.0.notes', $x)`
- Any `->set('photo', $x)` → `->set('rows.0.photo', $x)`

Worked example (from `'a logged-in admin can select a result and save it to the collection'`, lines 135-142):

```php
Livewire::test(AddCollectionItem::class, ['collectionId' => $collection->id])
    ->set('search', 'Darkrai')
    ->call('runSearch')
    ->call('selectCard', 'me05-116')
    ->set('condition', 'NM')
    ->set('quantity', 1)
    ->call('save')
    ->assertRedirect();
```

becomes:

```php
Livewire::test(AddCollectionItem::class, ['collectionId' => $collection->id])
    ->set('search', 'Darkrai')
    ->call('runSearch')
    ->call('selectCard', 'me05-116')
    ->set('rows.0.condition', 'NM')
    ->set('rows.0.quantity', 1)
    ->call('save')
    ->assertRedirect();
```

Apply the same substitution to `'an uploaded photo is stored and its path saved on the item'` (line 147+), the IDOR test at line 607, the "brand-new user" test at line 577, and the "catalog sync failure" test at line 634 — every one of these sets at least one of the seven old properties before calling `save`.

- [ ] **Step 1: Apply the substitutions above to every affected test**

- [ ] **Step 2: Run the full file**

Run: `./vendor/bin/sail php vendor/bin/pest --filter=AddCollectionItemTest`
Expected: PASS — every test in the file, old and new.

- [ ] **Step 3: Run Pint and the full suite**

Run: `./vendor/bin/sail php vendor/bin/pint && ./vendor/bin/sail php vendor/bin/pest`
Expected: Pint passes; full suite passes (this is the first point since Task 1 the ENTIRE suite — not just these two files — has run; confirm no unrelated regression, e.g. a shared view include used elsewhere).

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Livewire/Admin/AddCollectionItemTest.php
git commit -m "test(admin): migrate AddCollectionItem tests to the repeatable rows API"
```

---

## After all 9 tasks

Once the full suite is green, follow this repo's existing release pipeline (documented in `deploy/README.md` and already used for prior features): feature branch → PR → CI → merge to `master` → `release/vX.Y.Z` branch → PR → CI → merge to `master` → GitHub Release → automatic deploy to polaris2. Add a `[Unreleased]` → versioned `CHANGELOG.md` entry describing the grouped Listado, the card-level Editar modal, and Crear's multi-variant submission, same as prior releases.
