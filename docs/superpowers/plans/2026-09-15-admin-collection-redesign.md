# Admin Collection Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `/admin` ("My Collection") from an unpaginated, unsearchable table into a real collection-management screen: persisted variant-review tracking, search/filter/sort/pagination, Variant/Grading/Value/Status columns, inline quantity editing, and a real delete-confirmation modal — all on the app's existing `design.md` token system.

**Architecture:** No new module boundaries. `CollectionItem` gets one new column. `CollectionService::addItem()` and `App\Livewire\Admin\Import` get a small pass-through for the new flag. `App\Livewire\Admin\CollectionItems` (the existing component) grows new public properties (search/filters/sort) and its `render()` query gains `where`/`orderBy`/`paginate()`; its Blade view grows the toolbar, three new columns, inline-qty editing, and a delete-confirmation modal.

**Tech Stack:** Laravel 13 + Livewire, Postgres, Pest — same stack as the rest of the app, no new dependencies.

## Global Constraints

- Every color/spacing/font value in new markup MUST use an existing `resources/css/app.css` token or an existing `.nw-*` class — never a bare Tailwind utility or inline hex (the screen is explicitly moving OFF ad-hoc Tailwind onto the locked system).
- `needs_variant_review` clears ONLY when `saveItem()` sets a non-null `variant` — editing any other field (including Notes, which has its own separate save method) must never touch it.
- Every lookup by raw item ID MUST continue to go through the existing `ownedItemOrFail()` helper (`app/Livewire/Admin/CollectionItems.php`) — never `CollectionItem::find()`/`findOrFail()` directly. This is a load-bearing IDOR guard (`CollectionItem` has no tenant column of its own).
- Value column: `CardPriceResolver::resolve($item->card)` → `Money::format($snapshot->market_minor * $item->quantity, $snapshot->currency)` when a snapshot exists, em dash (`—`) otherwise. Never invent a price.
- Pagination page size is exactly 24 (per the spec, chosen deliberately — do not change without asking).
- Default sort is Value, descending.

---

### Task 1: Persist and wire `needs_variant_review`

**Files:**
- Create: `database/migrations/2026_09_15_000004_add_needs_variant_review_to_collection_items_table.php`
- Modify: `app/Modules/Collection/Models/CollectionItem.php`
- Modify: `app/Modules/Collection/Services/CollectionService.php`
- Modify: `app/Livewire/Admin/Import.php`
- Modify: `app/Livewire/Admin/CollectionItems.php` (auto-clear in `saveItem()`)
- Test: `tests/Unit/Modules/Collection/CollectionServiceTest.php` (existing file — add cases)
- Test: `tests/Feature/Livewire/Admin/ImportTest.php` (existing file — add a case)
- Test: `tests/Feature/Livewire/Admin/CollectionItemsTest.php` (existing file — add a case)

**Interfaces:**
- Consumes: `MatchedImportLine::$variantAmbiguous` (already exists, from this session's earlier importer fix) — no change needed there.
- Produces: `CollectionItem::$needs_variant_review` (bool, default false). `CollectionService::addItem()`'s `$itemData` array accepts an optional `needs_variant_review` key (bool, defaults to `false` when absent). Later tasks (2-4) read this column but never write it except via `saveItem()`'s auto-clear.

- [ ] **Step 1: Write the failing test for `addItem()` storing the flag**

Open `tests/Unit/Modules/Collection/CollectionServiceTest.php` and add:

```php
test('addItem stores needs_variant_review when passed true', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->once()
        ->andReturn(fakeCatalogCard('me05-116', 'me05', '116', 'Mega Darkrai ex'));
    $provider->shouldReceive('findSet')->with('me05')->once()
        ->andReturn(new SetSummaryData(tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: 1, logoUrl: null));

    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create();
    $service = new CollectionService(new CatalogSyncService($provider));

    $item = $service->addItem($collection, 'me05-116', [
        'condition' => 'NM',
        'quantity' => 3,
        'needs_variant_review' => true,
    ]);

    expect($item->needs_variant_review)->toBeTrue();
});

test('addItem defaults needs_variant_review to false when not passed', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->once()
        ->andReturn(fakeCatalogCard('me05-116', 'me05', '116', 'Mega Darkrai ex'));
    $provider->shouldReceive('findSet')->with('me05')->once()
        ->andReturn(new SetSummaryData(tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: 1, logoUrl: null));

    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create();
    $service = new CollectionService(new CatalogSyncService($provider));

    $item = $service->addItem($collection, 'me05-116', ['condition' => 'NM']);

    expect($item->needs_variant_review)->toBeFalse();
});
```

Check the top of `CollectionServiceTest.php` for its existing `fakeCatalogCard()` helper and `SetSummaryData`/`CatalogSyncService`/`CardCatalogProvider` imports — reuse them exactly as the file's other tests already do; do not redefine the helper.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/sail artisan test --filter="needs_variant_review"`
Expected: FAIL — the column doesn't exist yet.

- [ ] **Step 3: Create the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_items', function (Blueprint $table) {
            // Set true by the TCGplayer bulk importer when tcgdex reports
            // more than one known price variant for a card but the export
            // text carries no signal for which physical copy is which
            // (TCGplayer's own format never marks holo/reverse-holo on the
            // line — found live 2026-09-15). Cleared the moment a real
            // variant is assigned via the edit modal.
            $table->boolean('needs_variant_review')->default(false)->after('variant');
        });
    }

    public function down(): void
    {
        Schema::table('collection_items', function (Blueprint $table) {
            $table->dropColumn('needs_variant_review');
        });
    }
};
```

- [ ] **Step 4: Run the migration locally**

Run: `./vendor/bin/sail artisan migrate`
Expected: `2026_09_15_000004_add_needs_variant_review_to_collection_items_table` runs successfully.

- [ ] **Step 5: Add the cast to the model**

In `app/Modules/Collection/Models/CollectionItem.php`, add `'needs_variant_review'` to `$fillable` and update `casts()`:

```php
    protected $fillable = [
        'collection_id',
        'card_id',
        'card_tcgdex_id',
        'variant',
        'condition',
        'grade_company',
        'grade_value',
        'quantity',
        'notes',
        'photo_path',
        'needs_variant_review',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'needs_variant_review' => 'boolean',
        ];
    }
```

- [ ] **Step 6: Wire `addItem()` to accept and store the flag**

In `app/Modules/Collection/Services/CollectionService.php`, update the docblock and the create call. The upsert identity tuple (Step "existing item" branch) is UNCHANGED — `needs_variant_review` is metadata on the row, not part of what makes two rows "the same":

```php
    /**
     * @param array{variant?: ?string, condition: string, grade_company?: ?string, grade_value?: ?string, quantity?: int, notes?: ?string, photo_path?: ?string, needs_variant_review?: bool} $itemData
     */
```

```php
        return $collection->items()->create([
            'card_id' => $card->id,
            'card_tcgdex_id' => $tcgdexCardId,
            'variant' => $variant,
            'condition' => $condition,
            'grade_company' => $gradeCompany,
            'grade_value' => $gradeValue,
            'quantity' => $quantity,
            'notes' => $itemData['notes'] ?? null,
            'photo_path' => $itemData['photo_path'] ?? null,
            'needs_variant_review' => $itemData['needs_variant_review'] ?? false,
        ]);
```

Note: when `$existingItem !== null` (the merge branch just above, `$existingItem->increment('quantity', $quantity)`), leave that branch untouched — a merge into an existing row that already has an explicit variant means the identity tuple matched on a non-null variant, so ambiguity doesn't apply there.

- [ ] **Step 7: Pass the flag through from the importer**

In `app/Livewire/Admin/Import.php`, in `confirm()`, update the `addItem()` call:

```php
                $service->addItem($collection, $line->tcgdexId, [
                    'condition' => 'NM',
                    'quantity' => $line->qty,
                    'needs_variant_review' => $line->variantAmbiguous,
                ]);
```

- [ ] **Step 8: Add the auto-clear to `saveItem()`**

In `app/Livewire/Admin/CollectionItems.php`, `saveItem()` currently ends with one `update()` call. Change it to clear the flag whenever a non-null variant is being saved:

```php
    public function saveItem(): void
    {
        if ($this->editingFullItemId === null) {
            return;
        }

        $this->validate();

        $item = $this->ownedItemOrFail($this->editingFullItemId);

        $item->update([
            'condition' => $this->editingCondition,
            'quantity' => $this->editingQuantity,
            'variant' => $this->editingVariant,
            'grade_company' => $this->editingGradeCompany,
            'grade_value' => $this->editingGradeValue,
            // Assigning a real variant is exactly what resolves the
            // ambiguity the importer flagged — never touched by editing
            // any other field (Notes has its own separate save method).
            'needs_variant_review' => $this->editingVariant !== null ? false : $item->needs_variant_review,
        ]);

        $this->editingFullItemId = null;
    }
```

- [ ] **Step 9: Write the failing tests for the importer pass-through and the auto-clear**

Append to `tests/Feature/Livewire/Admin/ImportTest.php` (check the file's existing imports/helpers first and reuse its pattern for faking a matched card with `variantAmbiguous`):

```php
test('a variant-ambiguous matched line sets needs_variant_review on the created item', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    Card::create(['tcgdex_id' => 'me05-037', 'set_id' => $set->id, 'local_id' => '037', 'name' => 'Lampent']);
    CardPriceSnapshot::create(['card_id' => Card::where('tcgdex_id', 'me05-037')->value('id'), 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 100]);
    CardPriceSnapshot::create(['card_id' => Card::where('tcgdex_id', 'me05-037')->value('id'), 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 300]);

    Livewire::test(Import::class)
        ->set('text', '3 Lampent [PBL] 037/084')
        ->call('preview')
        ->call('confirm');

    $item = CollectionItem::where('card_tcgdex_id', 'me05-037')->first();
    expect($item->needs_variant_review)->toBeTrue();
});
```

Check the top of `ImportTest.php` for its existing `use` statements (`Card`, `CardPriceSnapshot`, `Set`, `CollectionItem`, `Livewire`, `App\Livewire\Admin\Import`) — add any missing ones rather than assuming.

Append to `tests/Feature/Livewire/Admin/CollectionItemsTest.php`:

```php
test('assigning a variant clears needs_variant_review', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'needs_variant_review' => true,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $item->id)
        ->set('editingVariant', 'holofoil')
        ->call('saveItem');

    expect($item->fresh()->needs_variant_review)->toBeFalse();
});

test('editing notes on a flagged item does not clear needs_variant_review', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'needs_variant_review' => true,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingNotes', $item->id)
        ->set('editingNotes', 'a note')
        ->call('saveNotes');

    expect($item->fresh()->needs_variant_review)->toBeTrue();
});
```

- [ ] **Step 10: Run the tests to verify they pass**

Run: `./vendor/bin/sail artisan test --filter="needs_variant_review|variant_ambiguous|variant-ambiguous"`
Expected: PASS — all new tests green.

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_09_15_000004_add_needs_variant_review_to_collection_items_table.php \
        app/Modules/Collection/Models/CollectionItem.php \
        app/Modules/Collection/Services/CollectionService.php \
        app/Livewire/Admin/Import.php \
        app/Livewire/Admin/CollectionItems.php \
        tests/Unit/Modules/Collection/CollectionServiceTest.php \
        tests/Feature/Livewire/Admin/ImportTest.php \
        tests/Feature/Livewire/Admin/CollectionItemsTest.php
git commit -m "feat(admin): persist needs_variant_review, wire importer and edit-clear"
```

---

### Task 2: Search, filter, sort, and pagination

**Files:**
- Modify: `app/Livewire/Admin/CollectionItems.php`
- Modify: `resources/views/livewire/admin/collection-items.blade.php`
- Modify: `resources/css/app.css` (only if a new modifier class is genuinely needed — prefer reusing `.nw-toolbar`/`.nw-pill-input`/`.nw-pill-select`/`.nw-seg` exactly as they already exist; do not duplicate them)
- Test: `tests/Feature/Livewire/Admin/CollectionItemsTest.php`

**Interfaces:**
- Consumes: Task 1's `needs_variant_review` column.
- Produces: `CollectionItems::$search`, `$conditionFilter`, `$variantFilter`, `$needsReviewOnly` (bool), `$sort` public properties; a `sortBy(string $sort): void` method. Task 3 (new columns) and Task 4 (delete modal) render inside the same paginated `$items` the query here produces — they must not re-query.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Livewire/Admin/CollectionItemsTest.php` (reuse the file's existing `use` statements):

```php
test('search matches by card name', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $toucannon = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $toucannon->id, 'card_tcgdex_id' => 'me05-068', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->set('search', 'darkrai')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Toucannon');
});

test('search matches by set name', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $pitchBlack = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $surging = Set::create(['tcgdex_id' => 'sv08', 'name' => 'Surging Sparks']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $pitchBlack->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $other = Card::create(['tcgdex_id' => 'sv08-1', 'set_id' => $surging->id, 'local_id' => '1', 'name' => 'Something Else']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'sv08-1', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->set('search', 'pitch black')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Something Else');
});

test('search matches by notes', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $other = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'notes' => 'bought at a con']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'me05-068', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->set('search', 'con')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Toucannon');
});

test('condition filter narrows the list', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $other = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'me05-068', 'condition' => 'LP', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->set('conditionFilter', 'LP')
        ->assertSee('Toucannon')
        ->assertDontSee('Mega Darkrai ex');
});

test('variant filter narrows the list', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $other = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'holofoil']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'me05-068', 'condition' => 'NM', 'quantity' => 1, 'variant' => 'normal']);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->set('variantFilter', 'holofoil')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Toucannon');
});

test('needs-review filter shows only flagged items', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $darkrai = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $other = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $darkrai->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'needs_variant_review' => true]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $other->id, 'card_tcgdex_id' => 'me05-068', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->set('needsReviewOnly', true)
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Toucannon');
});

test('sorting by name orders alphabetically', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $zebra = Card::create(['tcgdex_id' => 'me05-1', 'set_id' => $set->id, 'local_id' => '1', 'name' => 'Zebstrika']);
    $abra = Card::create(['tcgdex_id' => 'me05-2', 'set_id' => $set->id, 'local_id' => '2', 'name' => 'Abra']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $zebra->id, 'card_tcgdex_id' => 'me05-1', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $abra->id, 'card_tcgdex_id' => 'me05-2', 'condition' => 'NM', 'quantity' => 1]);

    $component = Livewire::test(\App\Livewire\Admin\CollectionItems::class)->call('sortBy', 'name');

    $names = $component->viewData('items')->pluck('card.name')->all();
    expect($names)->toBe(['Abra', 'Zebstrika']);
});

test('default sort is value descending', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $cheap = Card::create(['tcgdex_id' => 'me05-1', 'set_id' => $set->id, 'local_id' => '1', 'name' => 'Cheap Card']);
    $pricey = Card::create(['tcgdex_id' => 'me05-2', 'set_id' => $set->id, 'local_id' => '2', 'name' => 'Pricey Card']);
    CardPriceSnapshot::create(['card_id' => $cheap->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 100]);
    CardPriceSnapshot::create(['card_id' => $pricey->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 10000]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $cheap->id, 'card_tcgdex_id' => 'me05-1', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $pricey->id, 'card_tcgdex_id' => 'me05-2', 'condition' => 'NM', 'quantity' => 1]);

    $component = Livewire::test(\App\Livewire\Admin\CollectionItems::class);

    $names = $component->viewData('items')->pluck('card.name')->all();
    expect($names)->toBe(['Pricey Card', 'Cheap Card']);
});

test('the list paginates at 24 items per page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    for ($i = 1; $i <= 26; $i++) {
        $card = Card::create(['tcgdex_id' => "me05-{$i}", 'set_id' => $set->id, 'local_id' => (string) $i, 'name' => "Card {$i}"]);
        CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => "me05-{$i}", 'condition' => 'NM', 'quantity' => 1]);
    }

    $component = Livewire::test(\App\Livewire\Admin\CollectionItems::class);

    expect($component->viewData('items'))->toHaveCount(24);
    expect($component->viewData('items')->total())->toBe(26);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/sail artisan test --filter="search|filter|sort|paginate"`
Expected: FAIL — none of these properties/behaviors exist yet.

- [ ] **Step 3: Add the new public properties and rewrite `render()`**

In `app/Livewire/Admin/CollectionItems.php`, add near the top of the class (alongside the existing `editingItemId` etc. properties):

```php
    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'condition', except: '')]
    public string $conditionFilter = '';

    #[Url(as: 'variant', except: '')]
    public string $variantFilter = '';

    #[Url(as: 'review', except: false)]
    public bool $needsReviewOnly = false;

    #[Url(except: 'value')]
    public string $sort = 'value';

    private const SORTS = ['value', 'name', 'newest'];
```

Add `use Livewire\Attributes\Url;` to the file's imports if not already present (check the file's current `use` block first).

Add the `sortBy()` method (same shape as the gallery's own `Show::sortBy()`):

```php
    public function sortBy(string $sort): void
    {
        if (in_array($sort, self::SORTS, true)) {
            $this->sort = $sort;
        }
    }
```

Replace `render()` entirely:

```php
    public function render()
    {
        // Reached only through Collection::items(), which is scoped via
        // Collection's TenantScope — never query CollectionItem::query()
        // directly here, that would bypass the tenant filter entirely.
        $query = Collection::query()
            ->get()
            ->pluck('id');

        $itemsQuery = CollectionItem::query()
            ->whereIn('collection_id', $query)
            ->with(['card.set']);

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

        if ($this->variantFilter !== '') {
            $itemsQuery->where('variant', $this->variantFilter);
        }

        if ($this->needsReviewOnly) {
            $itemsQuery->where('needs_variant_review', true);
        }

        if ($this->sort === 'name') {
            $itemsQuery->join('cards', 'cards.id', '=', 'collection_items.card_id')
                ->orderBy('cards.name')
                ->select('collection_items.*');
        } elseif ($this->sort === 'newest') {
            $itemsQuery->orderByDesc('collection_items.created_at');
        }
        // 'value' (default) is resolved in PHP below — market_minor lives on
        // a separate priceSnapshots relation, not a joinable flat column,
        // and CardPriceResolver's "pick the right source" logic can't be
        // expressed as a single SQL ORDER BY.

        $items = $itemsQuery->paginate($this->sort === 'value' ? 1000 : 24, page: $this->sort === 'value' ? 1 : null);

        $resolver = new CardPriceResolver;

        if ($this->sort === 'value') {
            // Resolve value once per item, sort in memory, then slice the
            // requested page — CardPriceResolver's resolve() can't be
            // expressed as a SQL ORDER BY (it walks a source-priority
            // chain across a separate table). Bounded by a real personal
            // collection's size (dozens–low hundreds), not thousands.
            $withValue = $items->getCollection()->map(function (CollectionItem $item) use ($resolver) {
                $snapshot = $resolver->resolve($item->card);
                $item->setAttribute('_valueMinor', $snapshot?->market_minor !== null ? $snapshot->market_minor * $item->quantity : -1);

                return $item;
            })->sortByDesc('_valueMinor')->values();

            $page = request()->integer('page', 1);
            $perPage = 24;
            $paged = $withValue->slice(($page - 1) * $perPage, $perPage)->values();

            $items = new \Illuminate\Pagination\LengthAwarePaginator(
                $paged,
                $withValue->count(),
                $perPage,
                $page,
                ['path' => request()->url(), 'query' => request()->query()],
            );
        }

        return view('livewire.admin.collection-items', ['items' => $items, 'resolver' => $resolver]);
    }
```

Add `use App\Modules\Catalog\Services\CardPriceResolver;` and `use App\Modules\Collection\Models\CollectionItem;` (check if `CollectionItem` is already imported — it is, per the existing `ownedItemOrFail()` method) to the file's imports.

- [ ] **Step 4: Add the toolbar to the Blade view**

In `resources/views/livewire/admin/collection-items.blade.php`, replace the existing header block (`<div class="flex items-center justify-between mb-4">...</div>`) with a `.nw-wrap`-wrapped header plus a `.nw-toolbar`, following the exact pattern already proven in `resources/views/livewire/gallery/show.blade.php`:

```blade
<div class="nw-wrap py-10">
    <div class="flex items-center justify-between mb-4">
        <h1 class="nw-display nw-h1 nw-h1--sm">My Collection</h1>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.collection.import') }}" class="nw-btn-secondary">Importar TCGplayer</a>
            <a href="{{ route('admin.collection.add') }}" class="nw-btn-primary">+ Add card</a>
        </div>
    </div>

    <div class="nw-toolbar mb-4">
        <div class="nw-count">Showing <b>{{ $items->total() }}</b> {{ Str::plural('card', $items->total()) }}</div>

        <div class="nw-toolbar-group">
            <label class="sr-only" for="collection-search">Search your collection</label>
            <input id="collection-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, set, notes…" class="nw-pill-input w-40 sm:w-52" autocomplete="off">

            <label class="sr-only" for="collection-condition">Filter by condition</label>
            <select id="collection-condition" wire:model.live="conditionFilter" class="nw-pill-select">
                <option value="">All conditions</option>
                <option value="NM">Near Mint</option>
                <option value="LP">Lightly Played</option>
                <option value="MP">Moderately Played</option>
                <option value="HP">Heavily Played</option>
                <option value="DMG">Damaged</option>
            </select>

            <label class="sr-only" for="collection-variant">Filter by variant</label>
            <select id="collection-variant" wire:model.live="variantFilter" class="nw-pill-select">
                <option value="">All variants</option>
                <option value="normal">Normal</option>
                <option value="holofoil">Holofoil</option>
                <option value="reverse-holofoil">Reverse Holofoil</option>
            </select>

            <div class="nw-seg" role="group" aria-label="Needs review">
                <button type="button" wire:click="$set('needsReviewOnly', {{ $needsReviewOnly ? 'false' : 'true' }})" aria-pressed="{{ $needsReviewOnly ? 'true' : 'false' }}">Needs review</button>
            </div>

            <div class="nw-seg" role="group" aria-label="Sort">
                <span class="lbl">Sort</span>
                <button type="button" wire:click="sortBy('value')" aria-pressed="{{ $sort === 'value' ? 'true' : 'false' }}">Value</button>
                <button type="button" wire:click="sortBy('name')" aria-pressed="{{ $sort === 'name' ? 'true' : 'false' }}">Name</button>
                <button type="button" wire:click="sortBy('newest')" aria-pressed="{{ $sort === 'newest' ? 'true' : 'false' }}">Added</button>
            </div>
        </div>
    </div>
```

Keep the rest of the file (the `<div class="nw-card overflow-hidden">...table...</div>` and the modal) as-is for this task — Task 3 rewrites the table body, Task 4 adds the delete modal. Close the new outer `.nw-wrap` div by changing the file's final `</div>` (currently closing the old `max-w-4xl mx-auto` wrapper) to match — the wrapper class changed from `max-w-4xl mx-auto py-10 px-4` to `nw-wrap py-10`, so only the class list changes, the nesting depth stays the same.

Add pagination links right after the closing `</table>`'s wrapping `.nw-card` div:

```blade
    <div class="mt-4">
        {{ $items->links() }}
    </div>
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/sail artisan test --filter="search|filter|sort|paginate"`
Expected: PASS — all new tests green.

- [ ] **Step 6: Run the full existing `CollectionItemsTest.php` file to check nothing regressed**

Run: `./vendor/bin/sail artisan test tests/Feature/Livewire/Admin/CollectionItemsTest.php`
Expected: PASS — every pre-existing test (notes editing, delete, IDOR guards, variant dropdown) still green with the new `render()`.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Admin/CollectionItems.php resources/views/livewire/admin/collection-items.blade.php tests/Feature/Livewire/Admin/CollectionItemsTest.php
git commit -m "feat(admin): add search, filter, sort, and pagination to the collection table"
```

---

### Task 3: Variant, Grading, Value, and Status columns + inline Qty editing

**Files:**
- Modify: `app/Livewire/Admin/CollectionItems.php`
- Modify: `resources/views/livewire/admin/collection-items.blade.php`
- Test: `tests/Feature/Livewire/Admin/CollectionItemsTest.php`

**Interfaces:**
- Consumes: Task 2's `$items`/`$resolver` view data (paginated, already sorted/filtered).
- Produces: `CollectionItems::$editingQtyItemId` (int|null), `startEditingQty(int $itemId): void`, `saveQty(): void` — a new inline-edit pair following the exact shape of the existing `startEditingNotes()`/`saveNotes()`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Livewire/Admin/CollectionItemsTest.php`:

```php
test('the table shows variant, grading, value, and status columns', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 2000]);
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 2, 'variant' => 'holofoil',
        'grade_company' => 'PSA', 'grade_value' => '10',
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->assertSee('Holofoil')
        ->assertSee('PSA 10')
        ->assertSee('$40.00'); // 2000 minor * qty 2 = 4000 minor = $40.00
});

test('an item with no priced snapshot shows an em dash for value', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->assertSee('—');
});

test('a flagged item shows the needs-review badge', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'needs_variant_review' => true]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->assertSee('Revisar');
});

test('quantity can be edited inline', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingQty', $item->id)
        ->set('editingQtyValue', 5)
        ->call('saveQty');

    expect($item->fresh()->quantity)->toBe(5);
});

test('a user cannot inline-edit quantity on another users item by guessing its ID (IDOR)', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    $otherItem = CollectionItem::create(['collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingQty', $otherItem->id)
        ->assertStatus(404);

    expect($otherItem->fresh()->quantity)->toBe(1);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/sail artisan test --filter="variant, grading, value|em dash|needs-review badge|edited inline|IDOR"`
Expected: FAIL — none of the new columns or `startEditingQty`/`saveQty` exist yet.

- [ ] **Step 3: Add the inline-qty properties and methods**

In `app/Livewire/Admin/CollectionItems.php`, add near the other editing properties:

```php
    public ?int $editingQtyItemId = null;

    #[Validate('required|integer|min:1')]
    public int $editingQtyValue = 1;
```

Add the methods (same shape as `startEditingNotes`/`saveNotes`):

```php
    public function startEditingQty(int $itemId): void
    {
        $item = $this->ownedItemOrFail($itemId);
        $this->editingQtyItemId = $itemId;
        $this->editingQtyValue = $item->quantity;
    }

    public function saveQty(): void
    {
        if ($this->editingQtyItemId === null) {
            return;
        }

        $this->validate(['editingQtyValue' => 'required|integer|min:1']);

        $this->ownedItemOrFail($this->editingQtyItemId)->update(['quantity' => $this->editingQtyValue]);
        $this->editingQtyItemId = null;
    }
```

- [ ] **Step 4: Rewrite the table body in the Blade view**

Replace the `<thead>`/`<tbody>` in `resources/views/livewire/admin/collection-items.blade.php`:

```blade
        <table class="w-full text-sm">
            <thead>
                <tr class="nw-topbar text-left">
                    <th class="p-3">Card</th>
                    <th class="p-3">Set</th>
                    <th class="p-3">Variant</th>
                    <th class="p-3">Condition</th>
                    <th class="p-3">Grading</th>
                    <th class="p-3">Qty</th>
                    <th class="p-3">Value</th>
                    <th class="p-3">Notes</th>
                    <th class="p-3">Status</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    @php
                        $snapshot = $resolver->resolve($item->card);
                        $valueLabel = $snapshot?->market_minor !== null
                            ? \App\Support\Money::format($snapshot->market_minor * $item->quantity, $snapshot->currency)
                            : '—';
                        $gradingLabel = $item->grade_company && $item->grade_value
                            ? "{$item->grade_company} {$item->grade_value}"
                            : '—';
                    @endphp
                    <tr class="nw-stagger-item nw-row-hover border-t" style="border-color: var(--hair); --nw-stagger-index: {{ min($loop->index, 10) }}">
                        <td class="p-3 font-medium">
                            @if ($item->photo_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('collection-photos')->url($item->photo_path) }}"
                                     alt="" class="w-10 h-10 object-cover rounded inline-block mr-2 align-middle">
                            @endif
                            {{ $item->card->name }}
                        </td>
                        <td class="p-3" style="color: var(--muted)">{{ $item->card->set->name }}</td>
                        <td class="p-3">{{ $item->variant ? \Illuminate\Support\Str::headline($item->variant) : '—' }}</td>
                        <td class="p-3 mono">{{ $item->condition }}</td>
                        <td class="p-3">{{ $gradingLabel }}</td>
                        <td class="p-3 mono">
                            @if ($editingQtyItemId === $item->id)
                                <input type="number" min="1" wire:model="editingQtyValue" wire:keydown.enter="saveQty" wire:blur="saveQty" class="border rounded px-2 py-1 w-16">
                            @else
                                <span wire:click="startEditingQty({{ $item->id }})" class="cursor-pointer">{{ $item->quantity }}</span>
                            @endif
                        </td>
                        <td class="p-3 mono">{{ $valueLabel }}</td>
                        <td class="p-3">
                            @if ($editingItemId === $item->id)
                                <input type="text" wire:model="editingNotes" wire:keydown.enter="saveNotes" class="border rounded px-2 py-1 w-full">
                            @else
                                <span wire:click="startEditingNotes({{ $item->id }})" class="cursor-pointer">{{ $item->notes ?: '—' }}</span>
                            @endif
                        </td>
                        <td class="p-3">
                            @if ($item->needs_variant_review)
                                <span class="text-xs font-medium" style="color: var(--danger)">Revisar</span>
                            @endif
                        </td>
                        <td class="p-3 text-right">
                            <button wire:click="startEditingItem({{ $item->id }})" class="text-xs mr-2" style="color: var(--ink)">Edit</button>
                            <button wire:click="delete({{ $item->id }})" wire:confirm="Remove this card from your collection?" class="text-xs" style="color: var(--danger)">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="p-6 text-center" style="color: var(--muted)">No cards yet — add your first one.</td></tr>
                @endforelse
            </tbody>
        </table>
```

Note: `wire:confirm` on the Delete button is REPLACED in Task 4 — leave it as-is for this task so the table renders correctly in isolation; Task 4 swaps it for the new modal.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/sail artisan test tests/Feature/Livewire/Admin/CollectionItemsTest.php`
Expected: PASS — all tests in the file green, including Task 1 and Task 2's tests (this task's `@php` block must not break their assertions).

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Admin/CollectionItems.php resources/views/livewire/admin/collection-items.blade.php tests/Feature/Livewire/Admin/CollectionItemsTest.php
git commit -m "feat(admin): add variant/grading/value/status columns and inline qty editing"
```

---

### Task 4: Real delete-confirmation modal + visual polish pass

**Files:**
- Modify: `app/Livewire/Admin/CollectionItems.php`
- Modify: `resources/views/livewire/admin/collection-items.blade.php`
- Test: `tests/Feature/Livewire/Admin/CollectionItemsTest.php`

**Interfaces:**
- Consumes: the existing `delete(int $itemId): void` method (unchanged signature — this task only changes WHEN it's called from, not what it does).
- Produces: `CollectionItems::$confirmingDeleteItemId` (int|null), `confirmDelete(int $itemId): void`, `cancelDelete(): void`. `delete()` itself stays as the actual deletion — the new methods only gate when it's invoked from the UI.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Livewire/Admin/CollectionItemsTest.php`:

```php
test('clicking delete opens a confirmation modal instead of deleting immediately', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('confirmDelete', $item->id)
        ->assertSet('confirmingDeleteItemId', $item->id);

    expect(CollectionItem::find($item->id))->not->toBeNull();
});

test('confirming delete in the modal actually deletes the item', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('confirmDelete', $item->id)
        ->call('delete', $item->id);

    expect(CollectionItem::find($item->id))->toBeNull();
});

test('cancelling the delete modal closes it without deleting', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $item = CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('confirmDelete', $item->id)
        ->call('cancelDelete')
        ->assertSet('confirmingDeleteItemId', null);

    expect(CollectionItem::find($item->id))->not->toBeNull();
});

test('a user cannot open the delete modal for another users item by guessing its ID (IDOR)', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    $otherItem = CollectionItem::create(['collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('confirmDelete', $otherItem->id)
        ->assertStatus(404);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/sail artisan test --filter="delete.*modal|Cancelling"`
Expected: FAIL — `confirmDelete`/`cancelDelete`/`confirmingDeleteItemId` don't exist yet.

- [ ] **Step 3: Add the confirmation-gating properties and methods**

In `app/Livewire/Admin/CollectionItems.php`, add:

```php
    public ?int $confirmingDeleteItemId = null;

    public function confirmDelete(int $itemId): void
    {
        // ownedItemOrFail() throws (404) for another tenant's item before
        // the modal ever opens — same IDOR posture as every other lookup
        // in this class.
        $this->ownedItemOrFail($itemId);
        $this->confirmingDeleteItemId = $itemId;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteItemId = null;
    }
```

`delete()` itself is unchanged — it already goes through `ownedItemOrFail()`.

- [ ] **Step 4: Replace the Delete button and add the confirmation modal in the Blade view**

Replace the Delete button in the table row:

```blade
                            <button wire:click="confirmDelete({{ $item->id }})" class="text-xs" style="color: var(--danger)">Delete</button>
```

Add the confirmation modal right after the existing edit modal's closing `@endif` (before the file's final closing `</div>`):

```blade
    @if ($confirmingDeleteItemId !== null)
        @php $deletingItem = $items->firstWhere('id', $confirmingDeleteItemId); @endphp
        <div class="fixed inset-0 z-40 flex items-center justify-center p-4"
             style="background: rgba(20,20,18,.5)"
             wire:click.self="cancelDelete"
             wire:keydown.escape.window="cancelDelete">
            <div class="nw-card modal-in w-full max-w-sm p-5">
                <h2 class="text-lg font-semibold mb-2" style="color: var(--ink)">Remove this card?</h2>
                @if ($deletingItem)
                    <p class="text-sm mb-4" style="color: var(--muted)">
                        {{ $deletingItem->card->name }}
                        @if ($deletingItem->variant) &middot; {{ \Illuminate\Support\Str::headline($deletingItem->variant) }} @endif
                        &middot; qty {{ $deletingItem->quantity }}
                    </p>
                @endif
                <div class="flex gap-2">
                    <button wire:click="delete({{ $confirmingDeleteItemId }})" class="nw-btn-danger text-sm px-4 py-2">Delete</button>
                    <button wire:click="cancelDelete" class="text-sm px-4 py-2" style="color: var(--muted)">Cancel</button>
                </div>
            </div>
        </div>
    @endif
```

Note: `$deletingItem` is looked up from the already-paginated `$items` in view scope — if the item isn't on the current page (shouldn't happen in practice since the button that opens this modal is always on a rendered row), the `@if ($deletingItem)` guard just skips the context line rather than erroring.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/sail artisan test tests/Feature/Livewire/Admin/CollectionItemsTest.php`
Expected: PASS — every test in the file green, including all of Tasks 1-3's.

- [ ] **Step 6: Run the full suite**

Run: `./vendor/bin/sail artisan test`
Expected: PASS (the one pre-existing unrelated `AddCollectionItemTest` permission-denied failure, if the environment still has it, is not caused by this work — see the project's own recurring-quirks notes before treating it as a regression).

- [ ] **Step 7: Manual browser pass**

Run: `npm run build`, log in, visit `/admin`. Confirm: search/filter/sort/pagination all work, the new columns render correctly (including the "Revisar" badge on a variant-ambiguous item), Qty is inline-editable, clicking Delete opens the new modal (not a native browser confirm), Cancel closes it without deleting, confirming actually deletes.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Admin/CollectionItems.php resources/views/livewire/admin/collection-items.blade.php tests/Feature/Livewire/Admin/CollectionItemsTest.php
git commit -m "feat(admin): replace native delete confirm with a styled modal"
```
