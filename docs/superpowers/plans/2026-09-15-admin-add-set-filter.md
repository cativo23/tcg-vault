# Admin Add-Card Set Filter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let `/admin/add`'s tcgdex name search be optionally narrowed to one set, via a dropdown
of locally-synced sets, using tcgdex's own server-side `set.id` filter.

**Architecture:** `CardCatalogProvider::searchCardsByName()` gains an optional second parameter.
`TcgdexCardCatalogProvider` forwards it as tcgdex's `set.id` query param. `AddCollectionItem`
(Livewire) gains a `setFilter` property, populated dropdown from the local `Set` table, and passes
it through on every search.

**Tech Stack:** Laravel 13, Livewire, Pest, Mockery, `Http::fake()`.

## Global Constraints

- `searchCardsByName()`'s new parameter is optional and defaults to `null` — every existing caller
  and every existing test that calls it with one argument must keep working without being forced
  to change, EXCEPT where noted below (Mockery's `->with()` argument-count matching means existing
  mocked expectations must be updated to match the new call shape — this is explicitly in scope for
  Task 2, not a regression to avoid).
- The set dropdown lists only sets already synced locally (`Set` table) — no tcgdex round-trip to
  populate it. Verified live: `GET /v2/en/cards?name=pikachu&set.id=sv02` returns only that set's
  matches.
- Leaving the dropdown on "All sets" must behave identically to today's unfiltered search.

---

### Task 1: `CardCatalogProvider` interface + `TcgdexCardCatalogProvider` set filter

**Files:**
- Modify: `app/Modules/Catalog/Contracts/CardCatalogProvider.php`
- Modify: `app/Modules/Catalog/Providers/TcgdexCardCatalogProvider.php`
- Test: `tests/Unit/Modules/Catalog/TcgdexCardCatalogProviderTest.php`

**Interfaces:**
- Produces: `CardCatalogProvider::searchCardsByName(string $query, ?string $setTcgdexId = null): array`
  (return type unchanged: `array<int, CardSummaryData>`). This is the exact signature Task 2's
  Livewire component calls.

- [ ] **Step 1: Write the failing test — set filter is sent as tcgdex's `set.id` param**

Add to `tests/Unit/Modules/Catalog/TcgdexCardCatalogProviderTest.php`, right after the existing
`'searchCardsByName maps tcgdex brief results into CardSummaryData'` test (around line 270):

```php
test('searchCardsByName includes set.id in the request when a set filter is given', function () {
    Http::fake(['api.tcgdex.net/v2/en/cards*' => Http::response([], 200)]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));
    $provider->searchCardsByName('Pikachu', 'sv02');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.tcgdex.net/v2/en/cards?name=Pikachu&set.id=sv02';
    });
});

test('searchCardsByName omits set.id from the request when no set filter is given', function () {
    Http::fake(['api.tcgdex.net/v2/en/cards*' => Http::response([], 200)]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));
    $provider->searchCardsByName('Pikachu');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.tcgdex.net/v2/en/cards?name=Pikachu';
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test --filter="searchCardsByName includes set.id" tests/Unit/Modules/Catalog/TcgdexCardCatalogProviderTest.php`
Expected: FAIL — `searchCardsByName()` currently takes only 1 argument (a
`ArgumentCountError` or the second test's assertion mismatches because there's no way to omit
`set.id` distinctly yet; either way, both new tests must fail before Step 3).

- [ ] **Step 3: Update the interface**

In `app/Modules/Catalog/Contracts/CardCatalogProvider.php`, replace the `searchCardsByName` method
declaration (lines 36-42) with:

```php
    /**
     * Search tcgdex by card name (brief results only — no pricing; call
     * findCard() on a chosen result for full detail + current price).
     * Optionally narrowed to one set via $setTcgdexId — passed straight
     * through to tcgdex's own server-side filter, not applied client-side.
     *
     * @return array<int, CardSummaryData>
     */
    public function searchCardsByName(string $query, ?string $setTcgdexId = null): array;
```

- [ ] **Step 4: Implement the filter in `TcgdexCardCatalogProvider`**

In `app/Modules/Catalog/Providers/TcgdexCardCatalogProvider.php`, replace the `searchCardsByName`
method (lines 138-158) with:

```php
    public function searchCardsByName(string $query, ?string $setTcgdexId = null): array
    {
        $params = ['name' => $query];

        // Verified live against api.tcgdex.net 2026-09-15: 'set.id' narrows
        // results server-side (dot notation for nested-field filters, per
        // tcgdex's own filtering docs), so a name search doesn't need to
        // fetch every cross-set printing and filter in PHP.
        if ($setTcgdexId !== null) {
            $params['set.id'] = $setTcgdexId;
        }

        $response = $this->http(self::REQUEST_TIMEOUT)->get('cards', $params);

        $response->throw();

        $json = $response->json();

        $this->assertValidSearchShape($query, $json);

        return array_map(
            fn (array $card): CardSummaryData => new CardSummaryData(
                tcgdexId: $card['id'],
                setTcgdexId: explode('-', $card['id'])[0],
                localId: $card['localId'],
                name: $card['name'],
                imageUrl: isset($card['image']) ? "{$card['image']}/high.webp" : null,
            ),
            $json,
        );
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Unit/Modules/Catalog/TcgdexCardCatalogProviderTest.php`
Expected: PASS — all tests in the file, including the two new ones.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Catalog/Contracts/CardCatalogProvider.php app/Modules/Catalog/Providers/TcgdexCardCatalogProvider.php tests/Unit/Modules/Catalog/TcgdexCardCatalogProviderTest.php
git commit -m "feat(catalog): support optional set filter in tcgdex name search"
```

---

### Task 2: `AddCollectionItem` set dropdown + wiring

**Files:**
- Modify: `app/Livewire/Admin/AddCollectionItem.php`
- Modify: `resources/views/livewire/admin/add-collection-item.blade.php`
- Test: `tests/Feature/Livewire/Admin/AddCollectionItemTest.php`

**Interfaces:**
- Consumes: `CardCatalogProvider::searchCardsByName(string $query, ?string $setTcgdexId = null): array`
  (Task 1).
- Produces: public property `?string $setFilter = null` and public property
  `array $availableSets` (`[tcgdex_id => name]`) — both read by the view added in this task.

- [ ] **Step 1: Update existing mocked expectations to match the new call shape**

Mockery's `->with('Darkrai')` matches only a call with exactly one argument. Once `runSearch()`
(Step 4 below) calls `searchCardsByName($this->search, $this->setFilter)`, every existing
`->shouldReceive('searchCardsByName')->with('<query>')` expectation in
`tests/Feature/Livewire/Admin/AddCollectionItemTest.php` must become
`->with('<query>', null)` to keep matching (the default `setFilter` is `null`, so this is not a
behavior change, only the mock's own precision). Update these five call sites:

- Line 31 (`'a logged-in admin can search tcgdex and see results'`): `->with('Darkrai')` → `->with('Darkrai', null)`
- Line 49 (`'a result whose set is already synced locally...'`): `->with('Pikachu')` → `->with('Pikachu', null)`
- Line 66 (`'a result whose set is not synced locally...'`): `->with('Pikachu')` → `->with('Pikachu', null)`
- Line 182 (`'a malformed catalog search response...'`): `->with('Darkrai')` → `->with('Darkrai', null)`
- Lines 200 and 203 (`'a stale search error clears...'`, two expectations): `->with('Darkrai')` →
  `->with('Darkrai', null)`, and `->with('Pikachu')` → `->with('Pikachu', null)`

(Line 38's `->shouldReceive('searchCardsByName')->andReturn(...)` calls with no `->with()` at all
need no change — they match any arguments.)

- [ ] **Step 2: Run the existing suite to confirm these five updates alone don't break anything else**

Run: `./vendor/bin/sail test tests/Feature/Livewire/Admin/AddCollectionItemTest.php`
Expected: PASS — this step only tightens mock argument matching; behavior is unchanged until Step 4
below is implemented, so this should already be green.

- [ ] **Step 3: Write the failing tests for the new dropdown behavior**

Add to `tests/Feature/Livewire/Admin/AddCollectionItemTest.php`, after the
`'a stale search error clears once a later search succeeds'` test (after line 216):

```php
test('the set dropdown lists only locally synced sets, sorted by name', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    Set::create(['tcgdex_id' => 'sv02', 'name' => 'Paldea Evolved']);
    Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);

    Livewire::test(\App\Livewire\Admin\AddCollectionItem::class)
        ->assertSet('availableSets', ['me05' => 'Pitch Black', 'sv02' => 'Paldea Evolved']);
});

test('picking a set narrows the search to that set', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    Set::create(['tcgdex_id' => 'sv02', 'name' => 'Paldea Evolved']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', 'sv02')->once()->andReturn([
        new CardSummaryData(tcgdexId: 'sv02-062', setTcgdexId: 'sv02', localId: '062', name: 'Pikachu', imageUrl: null),
    ]);
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(\App\Livewire\Admin\AddCollectionItem::class)
        ->set('search', 'Pikachu')
        ->set('setFilter', 'sv02')
        ->call('runSearch')
        ->assertSet('results.0.tcgdexId', 'sv02-062');
});

test('changing the set filter re-runs the current search immediately', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    Set::create(['tcgdex_id' => 'sv02', 'name' => 'Paldea Evolved']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null)->once()->andReturn([
        new CardSummaryData(tcgdexId: 'me05-999', setTcgdexId: 'me05', localId: '999', name: 'Pikachu', imageUrl: null),
    ]);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', 'sv02')->once()->andReturn([
        new CardSummaryData(tcgdexId: 'sv02-062', setTcgdexId: 'sv02', localId: '062', name: 'Pikachu', imageUrl: null),
    ]);
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(\App\Livewire\Admin\AddCollectionItem::class)
        ->set('search', 'Pikachu')
        ->call('runSearch')
        ->assertSet('results.0.tcgdexId', 'me05-999')
        ->set('setFilter', 'sv02')
        ->assertSet('results.0.tcgdexId', 'sv02-062');
});

test('leaving the set filter on "All sets" behaves exactly like today\'s unfiltered search', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Pikachu', null)->once()->andReturn([
        new CardSummaryData(tcgdexId: 'me05-999', setTcgdexId: 'me05', localId: '999', name: 'Pikachu', imageUrl: null),
    ]);
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(\App\Livewire\Admin\AddCollectionItem::class)
        ->assertSet('setFilter', null)
        ->set('search', 'Pikachu')
        ->call('runSearch')
        ->assertSet('results.0.tcgdexId', 'me05-999');
});
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `./vendor/bin/sail test --filter="set dropdown|narrows the search|re-runs the current search|All sets" tests/Feature/Livewire/Admin/AddCollectionItemTest.php`
Expected: FAIL — `setFilter` and `availableSets` don't exist yet, and `runSearch()` doesn't pass a
second argument yet.

- [ ] **Step 5: Add `setFilter`, `availableSets`, `mount()`, and wire them into `runSearch()`**

In `app/Livewire/Admin/AddCollectionItem.php`:

Add the `use App\Modules\Catalog\Models\Set;` import (it's not there yet — only
`CardCatalogProvider`, `CardSummaryData`, `Collection`, `CollectionService` are currently imported).

Add these two public properties right after the `$resultSetNames` property block (after line 42):

```php
    /**
     * Narrows runSearch() to one set when set — populated from the
     * dropdown, tcgdex-id keyed (e.g. "sv02"). null = "All sets", the
     * same unfiltered behavior as before this feature existed.
     */
    public ?string $setFilter = null;

    /**
     * Dropdown options: locally synced sets only, tcgdex_id => name.
     * Populated once in mount() — no tcgdex round-trip, per design (a set
     * that hasn't been synced yet simply isn't offered as a filter).
     *
     * @var array<string, string>
     */
    public array $availableSets = [];
```

Add a `mount()` method right before `runSearch()` (before line 82):

```php
    public function mount(): void
    {
        $this->availableSets = Set::orderBy('name')->pluck('name', 'tcgdex_id')->all();
    }
```

Replace the `runSearch()` method body's provider call (line 94) — change:

```php
            $this->results = $provider->searchCardsByName($this->search);
```

to:

```php
            $this->results = $provider->searchCardsByName($this->search, $this->setFilter);
```

Add a new method right after `runSearch()` (after its closing brace, before `selectCard()`):

```php
    public function updatedSetFilter(): void
    {
        $this->runSearch(app(CardCatalogProvider::class));
    }
```

- [ ] **Step 6: Add the dropdown to the view**

In `resources/views/livewire/admin/add-collection-item.blade.php`, insert this block right before
the existing "Search tcgdex by name" `<div class="mb-4">` (before line 5):

```blade
        <div class="mb-4">
            <label class="block text-sm font-medium mb-1">Set</label>
            <select wire:model.live="setFilter" class="w-full border rounded px-3 py-2">
                <option value="">All sets</option>
                @foreach ($availableSets as $tcgdexId => $name)
                    <option value="{{ $tcgdexId }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
```

(Livewire binds an empty `<option value="">` to `null` on a nullable string property, matching
`$setFilter`'s default.)

- [ ] **Step 7: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/Livewire/Admin/AddCollectionItemTest.php`
Expected: PASS — all tests in the file, including the four new ones.

- [ ] **Step 8: Run the full suite to confirm no other regressions**

Run: `./vendor/bin/sail test`
Expected: PASS — full green suite (261 tests + the 6 added in this plan = 267).

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/Admin/AddCollectionItem.php resources/views/livewire/admin/add-collection-item.blade.php tests/Feature/Livewire/Admin/AddCollectionItemTest.php
git commit -m "feat(admin): add set filter dropdown to add-card search"
```

---

## Self-Review Notes

- **Spec coverage:** interface change (Task 1), tcgdex forwarding (Task 1), dropdown sourced from
  local `Set` only (Task 2 `mount()`), optional/non-blocking filter (Task 2 `setFilter` default
  `null`), immediate re-search on set change (Task 2 `updatedSetFilter()`), unchanged behavior on
  "All sets" (Task 2 last new test) — all covered.
- **Placeholder scan:** none found — every step has literal code.
- **Type consistency:** `searchCardsByName(string $query, ?string $setTcgdexId = null): array` is
  identical across the interface (Task 1), the implementation (Task 1), and every call site (Task
  2). `$availableSets` is `array<string, string>` everywhere it's referenced.
