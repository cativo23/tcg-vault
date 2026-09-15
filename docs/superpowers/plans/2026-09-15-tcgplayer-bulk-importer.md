# TCGplayer Bulk Importer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Parse TCGplayer's Android-app collection/decklist export format and bulk-add the matched cards to the collection through a new `/admin/import` screen.

**Architecture:** A pure, stateless `TcgplayerImportParser` service turns pasted text into a `ParsedImport` DTO (matched cards + unrecognized lines), using a small hand-maintained set-code mapping table and the existing `CardCatalogProvider` for per-card lookup/validation. A new `Import` Livewire component drives a two-step preview → confirm flow, reusing the existing `CollectionService::addItem()` upsert path unchanged for the actual writes.

**Tech Stack:** Laravel 13, Livewire 3, `spatie/laravel-data`, Pest, Mockery.

## Global Constraints

- Design source of truth: `docs/superpowers/specs/2026-09-15-tcgplayer-bulk-importer-design.md`.
- Set-code map lives in `config/tcgvault.php` under `tcgplayer_set_map`, starting with exactly `['PBL' => 'me05', 'MEE' => 'mee']` (verified against real tcgdex data this session).
- Line format: `^(?P<qty>\d+)\s+(?P<name>.+?)(?:\s+-\s+\S+)?\s+\[(?P<set>\w+)\]\s+(?P<local>\S+)$` — the disambiguator (` - <anything>`) before the bracketed set code is optional and discarded, never parsed for data.
- Both currently-mapped sets (`me05`, `mee`) zero-pad `localId` to 3 digits — the parser pads to 3 digits unconditionally; a future mapping entry with a different width will legitimately surface as `card_not_found` until handled explicitly (YAGNI, matches the design's "out of scope" note on auto-detection).
- Unmatched-line reasons are exactly one of: `unparsed` (line doesn't match the regex at all), `unknown_set` (bracketed code isn't in the map), `card_not_found` (tcgdex has no such card).
- Quantity merge: lines resolving to the same `(set, localId)` are summed *before* any lookup — no duplicate `findCard()` calls for repeated lines in one paste.
- Quantity merge against the user's existing collection is entirely delegated to `CollectionService::addItem()` (already sums into an existing `CollectionItem` of the same identity) — no new merge logic anywhere in this feature.
- Preview is a flat, already-merged table (qty, name, tcgdex id) — no diff against current holdings.
- Unmatched lines never block confirmation; the confirm action is enabled whenever there is at least one matched line.
- Route: `/admin/import`, `->middleware(['auth'])` only — this app has no admin gate beyond authentication (single-admin personal app, confirmed via `bootstrap/app.php` and every other `/admin/*` route); do not invent one.
- `declare(strict_types=1);` and `final class` on every new PHP class, matching every existing class in `app/Modules/*`.

---

### Task 1: Import DTOs, set-code mapping, and `TcgplayerImportParser`

**Files:**
- Create: `app/Modules/Collection/Data/MatchedImportLine.php`
- Create: `app/Modules/Collection/Data/UnmatchedImportLine.php`
- Create: `app/Modules/Collection/Data/ParsedImport.php`
- Create: `app/Modules/Collection/Services/TcgplayerImportParser.php`
- Modify: `config/tcgvault.php` (add `tcgplayer_set_map` key)
- Test: `tests/Unit/Modules/Collection/TcgplayerImportParserTest.php`

**Interfaces:**
- Consumes: `App\Modules\Catalog\Contracts\CardCatalogProvider::findCard(string $tcgdexId): CardDetailData` (`@throws App\Modules\Catalog\Exceptions\CardNotFoundException`).
- Produces: `TcgplayerImportParser::parse(string $text): ParsedImport`, where `ParsedImport { matched: Illuminate\Support\Collection<int, MatchedImportLine>, unmatched: Illuminate\Support\Collection<int, UnmatchedImportLine> }`, `MatchedImportLine { qty: int, tcgdexId: string, name: string }`, `UnmatchedImportLine { rawLine: string, reason: string }`. Task 2 consumes all three by these exact names/shapes.

- [ ] **Step 1: Add the set-code mapping config**

Open `config/tcgvault.php` and add a new key after `allow_registration`:

```php
    /**
     * Hand-maintained map of TCGplayer's own set codes (as they appear in
     * its Android app's collection/decklist export, e.g. "[PBL]") to the
     * matching tcgdex set id. tcgdex has no TCGplayer-code field on its Set
     * object, so this cannot be derived automatically — add an entry here
     * whenever a new set is exported and the code isn't recognized yet.
     * Verified 2026-09-15 against real tcgdex data: PBL -> me05 ("Pitch
     * Black"), MEE -> mee ("Mega Evolution Energy", basic energy reprints).
     */
    'tcgplayer_set_map' => [
        'PBL' => 'me05',
        'MEE' => 'mee',
    ],
```

- [ ] **Step 2: Write the DTO classes**

`app/Modules/Collection/Data/MatchedImportLine.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Collection\Data;

use Spatie\LaravelData\Data;

final class MatchedImportLine extends Data
{
    public function __construct(
        public readonly int $qty,
        public readonly string $tcgdexId,
        public readonly string $name,
    ) {}
}
```

`app/Modules/Collection/Data/UnmatchedImportLine.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Collection\Data;

use Spatie\LaravelData\Data;

final class UnmatchedImportLine extends Data
{
    public function __construct(
        public readonly string $rawLine,
        /** One of: 'unparsed', 'unknown_set', 'card_not_found'. */
        public readonly string $reason,
    ) {}
}
```

`app/Modules/Collection/Data/ParsedImport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Collection\Data;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

final class ParsedImport extends Data
{
    public function __construct(
        /** @var Collection<int, MatchedImportLine> */
        public readonly Collection $matched,
        /** @var Collection<int, UnmatchedImportLine> */
        public readonly Collection $unmatched,
    ) {}
}
```

- [ ] **Step 3: Write the failing parser tests**

Create `tests/Unit/Modules/Collection/TcgplayerImportParserTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Collection\Services\TcgplayerImportParser;

function fakeImportCard(string $tcgdexId, string $setTcgdexId, string $localId, string $name): CardDetailData
{
    return CardDetailData::from([
        'tcgdexId' => $tcgdexId,
        'setTcgdexId' => $setTcgdexId,
        'localId' => $localId,
        'name' => $name,
        'rarity' => 'Common',
        'variants' => [],
        'officialImageUrl' => null,
        'prices' => [],
        'raw' => [],
    ]);
}

test('parses a normal line and matches it against the catalog', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-068')->once()
        ->andReturn(fakeImportCard('me05-068', 'me05', '068', 'Toucannon'));

    $result = (new TcgplayerImportParser($provider))->parse('1 Toucannon - 068/084 [PBL] 068/084');

    expect($result->matched)->toHaveCount(1);
    expect($result->matched->first()->qty)->toBe(1);
    expect($result->matched->first()->tcgdexId)->toBe('me05-068');
    expect($result->matched->first()->name)->toBe('Toucannon');
    expect($result->unmatched)->toHaveCount(0);
});

test('resolves two lines with the same name and different disambiguators as two distinct cards', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-093')->once()
        ->andReturn(fakeImportCard('me05-093', 'me05', '093', 'Bastiodon'));
    $provider->shouldReceive('findCard')->with('me05-062')->once()
        ->andReturn(fakeImportCard('me05-062', 'me05', '062', 'Bastiodon'));

    $result = (new TcgplayerImportParser($provider))->parse(
        "1 Bastiodon - 093/084 [PBL] 093/084\n1 Bastiodon - 062/084 [PBL] 062/084"
    );

    expect($result->matched)->toHaveCount(2);
    expect($result->matched->pluck('tcgdexId')->all())->toEqualCanonicalizing(['me05-093', 'me05-062']);
});

test('parses a basic energy line under the MEE set code', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('mee-002')->once()
        ->andReturn(fakeImportCard('mee-002', 'mee', '002', 'Fire Energy'));

    $result = (new TcgplayerImportParser($provider))->parse('1 Basic Fire Energy - 002 [MEE] 2');

    expect($result->matched)->toHaveCount(1);
    expect($result->matched->first()->tcgdexId)->toBe('mee-002');
    expect($result->matched->first()->name)->toBe('Fire Energy');
});

test('flags an unrecognized set code without calling the catalog', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldNotReceive('findCard');

    $result = (new TcgplayerImportParser($provider))->parse('1 Some Card [ZZZ] 001/100');

    expect($result->matched)->toHaveCount(0);
    expect($result->unmatched)->toHaveCount(1);
    expect($result->unmatched->first()->reason)->toBe('unknown_set');
    expect($result->unmatched->first()->rawLine)->toBe('1 Some Card [ZZZ] 001/100');
});

test('flags a card tcgdex does not recognize', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-999')->once()
        ->andThrow(CardNotFoundException::forTcgdexId('me05-999'));

    $result = (new TcgplayerImportParser($provider))->parse('1 Ghost Card [PBL] 999/084');

    expect($result->matched)->toHaveCount(0);
    expect($result->unmatched)->toHaveCount(1);
    expect($result->unmatched->first()->reason)->toBe('card_not_found');
});

test('flags a line that does not match the export format at all', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldNotReceive('findCard');

    $result = (new TcgplayerImportParser($provider))->parse('this is not a valid export line');

    expect($result->unmatched)->toHaveCount(1);
    expect($result->unmatched->first()->reason)->toBe('unparsed');
});

test('merges quantities for two lines resolving to the same card and looks it up only once', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-037')->once()
        ->andReturn(fakeImportCard('me05-037', 'me05', '037', 'Lampent'));

    $result = (new TcgplayerImportParser($provider))->parse("2 Lampent [PBL] 037/084\n1 Lampent [PBL] 037/084");

    expect($result->matched)->toHaveCount(1);
    expect($result->matched->first()->qty)->toBe(3);
});

test('ignores blank lines between real lines', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-068')->once()
        ->andReturn(fakeImportCard('me05-068', 'me05', '068', 'Toucannon'));

    $result = (new TcgplayerImportParser($provider))->parse("\n1 Toucannon - 068/084 [PBL] 068/084\n\n");

    expect($result->matched)->toHaveCount(1);
    expect($result->unmatched)->toHaveCount(0);
});
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `./vendor/bin/sail test tests/Unit/Modules/Collection/TcgplayerImportParserTest.php`
Expected: FAIL — `TcgplayerImportParser` class not found.

- [ ] **Step 5: Implement `TcgplayerImportParser`**

`app/Modules/Collection/Services/TcgplayerImportParser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Collection\Data\MatchedImportLine;
use App\Modules\Collection\Data\ParsedImport;
use App\Modules\Collection\Data\UnmatchedImportLine;
use Illuminate\Support\Collection;

/**
 * Parses TCGplayer's Android-app collection/decklist export format (one
 * card per line: qty, name, optional " - <disambiguator>", bracketed set
 * code, local number) into matched tcgdex cards and unrecognized lines.
 * Pure — never touches the database, only reads from the Catalog via
 * CardCatalogProvider to validate each candidate card actually exists.
 */
final class TcgplayerImportParser
{
    private const LINE_PATTERN = '/^(?P<qty>\d+)\s+(?P<name>.+?)(?:\s+-\s+\S+)?\s+\[(?P<set>\w+)\]\s+(?P<local>\S+)$/';

    public function __construct(private readonly CardCatalogProvider $provider) {}

    public function parse(string $text): ParsedImport
    {
        $setMap = config('tcgvault.tcgplayer_set_map');

        /** @var array<string, array{qty: int, rawLine: string}> $candidates keyed by "{tcgdexSetId}-{localId}" */
        $candidates = [];
        $unmatched = [];

        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];

        foreach ($lines as $rawLine) {
            $rawLine = trim($rawLine);
            if ($rawLine === '') {
                continue;
            }

            if (! preg_match(self::LINE_PATTERN, $rawLine, $m)) {
                $unmatched[] = new UnmatchedImportLine(rawLine: $rawLine, reason: 'unparsed');

                continue;
            }

            $qty = (int) $m['qty'];
            $setCode = $m['set'];
            $localRaw = explode('/', $m['local'])[0];

            $tcgdexSetId = $setMap[$setCode] ?? null;
            if ($tcgdexSetId === null) {
                $unmatched[] = new UnmatchedImportLine(rawLine: $rawLine, reason: 'unknown_set');

                continue;
            }

            // Both mapped sets (me05, mee) zero-pad to 3 digits; a future
            // mapping entry with a different width legitimately surfaces as
            // card_not_found below rather than silently mismatching.
            $localId = str_pad($localRaw, 3, '0', STR_PAD_LEFT);
            $tcgdexCardId = "{$tcgdexSetId}-{$localId}";

            if (isset($candidates[$tcgdexCardId])) {
                $candidates[$tcgdexCardId]['qty'] += $qty;
            } else {
                $candidates[$tcgdexCardId] = ['qty' => $qty, 'rawLine' => $rawLine];
            }
        }

        $matched = [];

        foreach ($candidates as $tcgdexCardId => $candidate) {
            try {
                $card = $this->provider->findCard($tcgdexCardId);
            } catch (CardNotFoundException) {
                $unmatched[] = new UnmatchedImportLine(rawLine: $candidate['rawLine'], reason: 'card_not_found');

                continue;
            }

            $matched[] = new MatchedImportLine(qty: $candidate['qty'], tcgdexId: $tcgdexCardId, name: $card->name);
        }

        return new ParsedImport(matched: Collection::make($matched), unmatched: Collection::make($unmatched));
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/sail test tests/Unit/Modules/Collection/TcgplayerImportParserTest.php`
Expected: PASS — 8 tests.

- [ ] **Step 7: Commit**

```bash
git add config/tcgvault.php app/Modules/Collection/Data/MatchedImportLine.php \
  app/Modules/Collection/Data/UnmatchedImportLine.php app/Modules/Collection/Data/ParsedImport.php \
  app/Modules/Collection/Services/TcgplayerImportParser.php \
  tests/Unit/Modules/Collection/TcgplayerImportParserTest.php
git commit -m "feat(import): add TCGplayer export parser and set-code mapping"
```

---

### Task 2: `/admin/import` screen wired to `CollectionService::addItem()`

**Files:**
- Create: `app/Livewire/Admin/Import.php`
- Create: `resources/views/livewire/admin/import.blade.php`
- Modify: `routes/web.php` (register `/admin/import`)
- Create: `tests/Fixtures/tcgplayer-export.txt`
- Test: `tests/Feature/Livewire/Admin/ImportTest.php`

**Interfaces:**
- Consumes: `TcgplayerImportParser::parse(string $text): ParsedImport` (Task 1); `App\Modules\Collection\Services\CollectionService::addItem(Collection $collection, string $tcgdexCardId, array{condition: string, quantity?: int} $itemData): CollectionItem` (existing, unchanged); `App\Modules\Collection\Models\Collection::firstOrCreate(...)` (existing pattern, copied from `app/Livewire/Admin/AddCollectionItem.php:` `save()`).
- Produces: route `admin.collection.import` at `/admin/import`.

- [ ] **Step 1: Register the route**

In `routes/web.php`, right after the existing `/admin/add` route (before `require __DIR__.'/auth.php';` — literal top-level paths must stay ahead of that line per the file's existing route-order comment):

```php
Route::get('/admin/import', \App\Livewire\Admin\Import::class)
    ->middleware(['auth'])
    ->name('admin.collection.import');
```

Add `use App\Livewire\Admin\Import;` to the top of the file alongside the other `App\Livewire\Admin\*` imports if the file uses short class names elsewhere (match whatever the existing `AddCollectionItem`/`CollectionItems` imports do).

- [ ] **Step 2: Create the export fixture**

Create `tests/Fixtures/tcgplayer-export.txt` with exactly this content (Carlos's real export, verified this session as 56 lines / 52 unique cards / 66 total copies against live tcgdex data):

```
1 Toucannon - 068/084 [PBL] 068/084
1 Malamar [PBL] 052/084
1 Mankey [PBL] 042/084
2 Lampent [PBL] 037/084
1 Inkay [PBL] 051/084
1 Bastiodon - 093/084 [PBL] 093/084
2 Inkay [PBL] 051/084
2 Drilbur [PBL] 046/084
2 Relicanth [PBL] 017/084
2 Bombirdier [PBL] 071/084
1 Basic Darkness Energy - 007 [MEE] 7
1 Charcadet [PBL] 011/084
1 Fomantis - 003/084 [PBL] 003/084
1 Backtrack Badge [PBL] 074/084
1 Dhelmise - 039/084 [PBL] 039/084
1 Charjabug [PBL] 025/084
1 Basic Fire Energy - 002 [MEE] 2
1 Brionne [PBL] 019/084
1 Maschiff [PBL] 057/084
1 Shuppet [PBL] 033/084
1 Sinistcha [PBL] 006/084
1 Misty's Vitality - 080/084 [PBL] 080/084
1 Miraidon [PBL] 028/084
1 Mega Darkrai ex - 116/084 [PBL] 116/084
1 Tremendous Bomb - 082/084 [PBL] 082/084
1 Rust Syndicate Grunt - 081/084 [PBL] 081/084
1 Jett [PBL] 079/084
1 Fossil Quarry [PBL] 076/084
1 Dark Bell - 075/084 [PBL] 075/084
1 Antique Armor Fossil [PBL] 072/084
1 Type: Null [PBL] 069/084
1 Mega Excadrill ex - 065/084 [PBL] 065/084
1 Bronzong [PBL] 064/084
1 Bastiodon - 062/084 [PBL] 062/084
1 Zarude [PBL] 056/084
1 Thievul - 054/084 [PBL] 054/084
2 Mandibuzz [PBL] 050/084
1 Primeape [PBL] 043/084
1 Marshadow [PBL] 040/084
1 Lampent [PBL] 037/084
1 Litwick [PBL] 036/084
1 Spiritomb [PBL] 035/084
1 Banette [PBL] 034/084
2 Jynx [PBL] 032/084
1 Miraidon [PBL] 028/084
1 Primarina - 020/084 [PBL] 020/084
2 Popplio [PBL] 018/084
1 Wailmer [PBL] 015/084
1 Wailmer [PBL] 015/084
1 Centiskorch [PBL] 010/084
2 Heatran [PBL] 007/084
2 Grubbin [PBL] 002/084
1 Basic Metal Energy - 008 [MEE] 8
1 Basic Fighting Energy - 006 [MEE] 6
1 Basic Psychic Energy - 005 [MEE] 5
1 Basic Water Energy - 003 [MEE] 3
```

- [ ] **Step 3: Write the failing feature test**

Create `tests/Feature/Livewire/Admin/ImportTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Collection\Models\Collection;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
});

function fakeCatalogProviderForImport(): CardCatalogProvider
{
    $provider = Mockery::mock(CardCatalogProvider::class);

    $provider->shouldReceive('findCard')->andReturnUsing(function (string $tcgdexId) {
        [$setId, $localId] = explode('-', $tcgdexId, 2);

        return CardDetailData::from([
            'tcgdexId' => $tcgdexId,
            'setTcgdexId' => $setId,
            'localId' => $localId,
            'name' => "Card {$tcgdexId}",
            'rarity' => 'Common',
            'variants' => [],
            'officialImageUrl' => null,
            'prices' => [],
            'raw' => [],
        ]);
    });

    $provider->shouldReceive('findSet')->andReturnUsing(
        fn (string $setTcgdexId) => SetSummaryData::from([
            'tcgdexId' => $setTcgdexId,
            'name' => "Set {$setTcgdexId}",
            'series' => null,
            'releasedOn' => null,
            'cardCount' => null,
            'logoUrl' => null,
        ])
    );

    return $provider;
}

test('previewing and confirming the real TCGplayer export adds the expected cards and quantities', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->app->instance(CardCatalogProvider::class, fakeCatalogProviderForImport());

    $export = file_get_contents(base_path('tests/Fixtures/tcgplayer-export.txt'));

    Livewire::test(\App\Livewire\Admin\Import::class)
        ->set('text', $export)
        ->call('preview')
        ->assertSet('matched', fn (array $matched) => count($matched) === 52)
        ->assertSet('unmatched', fn (array $unmatched) => count($unmatched) === 0)
        ->call('confirm')
        ->assertSet('summary', '52 cartas agregadas, 66 copias totales.')
        ->assertSet('matched', [])
        ->assertSet('text', '');

    $collection = Collection::where('user_id', $user->id)->firstOrFail();
    expect($collection->items()->count())->toBe(52);
    expect($collection->items()->sum('quantity'))->toBe(66);
});

test('unmatched lines are reported but do not block confirming the matched ones', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->app->instance(CardCatalogProvider::class, fakeCatalogProviderForImport());

    Livewire::test(\App\Livewire\Admin\Import::class)
        ->set('text', "1 Toucannon - 068/084 [PBL] 068/084\n1 Something Weird [ZZZ] 001/100")
        ->call('preview')
        ->assertSet('matched', fn (array $matched) => count($matched) === 1)
        ->assertSet('unmatched', fn (array $unmatched) => count($unmatched) === 1 && $unmatched[0]->reason === 'unknown_set')
        ->call('confirm')
        ->assertSet('summary', '1 cartas agregadas, 1 copias totales.');

    $collection = Collection::where('user_id', $user->id)->firstOrFail();
    expect($collection->items()->count())->toBe(1);
});

test('a card the catalog rejects during confirmation does not abort the rest of the batch', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $provider = fakeCatalogProviderForImport();
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(\App\Livewire\Admin\Import::class)
        ->set('text', "1 Toucannon - 068/084 [PBL] 068/084\n1 Malamar [PBL] 052/084")
        ->call('preview')
        ->assertSet('matched', fn (array $matched) => count($matched) === 2);

    // Re-bind the provider so the second card fails only during confirm(),
    // proving one bad card mid-batch doesn't sink the rest.
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-068')->andReturnUsing(fakeCatalogProviderForImport()->findCard(...));
    $provider->shouldReceive('findCard')->with('me05-052')->andThrow(CardNotFoundException::forTcgdexId('me05-052'));
    $provider->shouldReceive('findSet')->andReturnUsing(
        fn (string $setTcgdexId) => SetSummaryData::from([
            'tcgdexId' => $setTcgdexId, 'name' => "Set {$setTcgdexId}", 'series' => null,
            'releasedOn' => null, 'cardCount' => null, 'logoUrl' => null,
        ])
    );
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(\App\Livewire\Admin\Import::class)
        ->set('text', "1 Toucannon - 068/084 [PBL] 068/084\n1 Malamar [PBL] 052/084")
        ->call('preview')
        ->call('confirm')
        ->assertSet('summary', '1 cartas agregadas, 1 copias totales.');

    $collection = Collection::where('user_id', $user->id)->firstOrFail();
    expect($collection->items()->count())->toBe(1);
});
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/Livewire/Admin/ImportTest.php`
Expected: FAIL — `App\Livewire\Admin\Import` class not found.

- [ ] **Step 5: Implement the Livewire component**

`app/Livewire/Admin/Import.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Services\CollectionService;
use App\Modules\Collection\Services\TcgplayerImportParser;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
final class Import extends Component
{
    public string $text = '';

    /** @var array<int, \App\Modules\Collection\Data\MatchedImportLine> */
    public array $matched = [];

    /** @var array<int, \App\Modules\Collection\Data\UnmatchedImportLine> */
    public array $unmatched = [];

    public ?string $summary = null;

    public function preview(TcgplayerImportParser $parser): void
    {
        $this->summary = null;

        $result = $parser->parse($this->text);
        $this->matched = $result->matched->all();
        $this->unmatched = $result->unmatched->all();
    }

    public function confirm(CollectionService $service): void
    {
        if ($this->matched === []) {
            return;
        }

        $collection = Collection::firstOrCreate(
            ['user_id' => auth()->id(), 'slug' => 'my-collection'],
            ['name' => 'My Collection', 'is_public' => false],
        );

        $addedCards = 0;
        $addedCopies = 0;

        foreach ($this->matched as $line) {
            try {
                $service->addItem($collection, $line->tcgdexId, [
                    'condition' => 'NM',
                    'quantity' => $line->qty,
                ]);
                $addedCards++;
                $addedCopies += $line->qty;
            } catch (Throwable $e) {
                // One bad card during confirmation shouldn't sink the rest
                // of the batch — same catch-and-continue philosophy as
                // ImportSetJob.
                report($e);
            }
        }

        $this->summary = "{$addedCards} cartas agregadas, {$addedCopies} copias totales.";
        $this->text = '';
        $this->matched = [];
        $this->unmatched = [];
    }

    public function render()
    {
        return view('livewire.admin.import');
    }
}
```

- [ ] **Step 6: Write the view**

`resources/views/livewire/admin/import.blade.php`:

```blade
<div class="max-w-3xl mx-auto py-10 px-4">
    <div class="nw-card p-6 space-y-6">
        <div>
            <h1 class="text-xl font-semibold">Importar desde TCGplayer</h1>
            <p class="text-sm text-ink-muted">
                Pega el export de la app de TCGplayer (una carta por línea) y revisa el preview antes de confirmar.
            </p>
        </div>

        @if ($summary)
            <div class="rounded border border-signal/40 bg-signal/10 px-4 py-3 text-sm">
                {{ $summary }}
            </div>
        @endif

        <textarea
            wire:model="text"
            rows="10"
            class="w-full font-mono text-sm border rounded p-3"
            placeholder="1 Toucannon - 068/084 [PBL] 068/084"
        ></textarea>

        <button wire:click="preview" class="nw-button">
            Preview
        </button>

        @if ($matched !== [] || $unmatched !== [])
            <div class="space-y-4">
                @if ($matched !== [])
                    <div>
                        <h2 class="font-medium mb-2">{{ count($matched) }} cartas reconocidas</h2>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-ink-muted">
                                    <th>Cant.</th>
                                    <th>Carta</th>
                                    <th>ID tcgdex</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($matched as $line)
                                    <tr>
                                        <td>{{ $line->qty }}</td>
                                        <td>{{ $line->name }}</td>
                                        <td class="font-mono text-xs">{{ $line->tcgdexId }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($unmatched !== [])
                    <div>
                        <h2 class="font-medium mb-2 text-danger">{{ count($unmatched) }} no reconocidas</h2>
                        <ul class="text-sm space-y-1">
                            @foreach ($unmatched as $line)
                                <li>
                                    <span class="font-mono text-xs">{{ $line->rawLine }}</span>
                                    <span class="text-ink-muted">({{ $line->reason }})</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($matched !== [])
                    <button wire:click="confirm" class="nw-button-primary">
                        Confirmar import
                    </button>
                @endif
            </div>
        @endif
    </div>
</div>
```

If `nw-button`/`nw-button-primary`/`nw-card` don't match the exact class names used elsewhere in the project (check `resources/views/livewire/admin/add-collection-item.blade.php` for the real names), use those instead — this view must match the established `design.md` visual system, not invent new class names.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/Livewire/Admin/ImportTest.php`
Expected: PASS — 3 tests.

- [ ] **Step 8: Run the full test suite**

Run: `./vendor/bin/sail test`
Expected: PASS — no regressions.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/Admin/Import.php resources/views/livewire/admin/import.blade.php \
  routes/web.php tests/Fixtures/tcgplayer-export.txt tests/Feature/Livewire/Admin/ImportTest.php
git commit -m "feat(import): add /admin/import screen for bulk TCGplayer imports"
```
