# Phase 1 — Scaffold & Catalog Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up a fresh Laravel 13 app and a working `Catalog` module that
can pull a set's cards + current pricing from tcgdex.dev and persist them,
via a tested artisan command — no UI, no Collection/admin, no scheduled job
yet (those are later phases).

**Architecture:** Laravel 13 modular monolith. `Catalog` is the first of the
modules defined in the design spec — global, never tenant-scoped, and the
only module allowed to talk to tcgdex, behind a `CardCatalogProvider`
interface so the rest of the app never sees a raw tcgdex payload.

**Tech Stack:** Laravel 13, PHP 8.3+, Laravel Sail (Postgres 17 + Redis),
`spatie/laravel-data` for DTOs, Pest for tests.

## Global Constraints

- PHP `^8.3`, Laravel `^13.0`. Every new PHP file starts with `declare(strict_types=1);`.
- Postgres 17 (via Sail locally). No SQLite, no MySQL — the design spec picked Postgres specifically for JSONB + future RLS.
- DTOs use `spatie/laravel-data` (`Spatie\LaravelData\Data`), not plain arrays or stdClass, for anything crossing a module boundary.
- Tests use Pest (`pestphp/pest`), not PHPUnit's `TestCase` class syntax.
- Money is always a `bigint` **minor-unit** integer column + an explicit `currency` column — never a float, never a decimal column.
- All code for this module lives under `app/Modules/Catalog/` — no `app/Models/Card.php` at the app root, no cross-module Eloquent relationships (there's only one module in this phase, but the convention starts here).
- tcgdex image URLs require the **zero-padded** `localId` string exactly as the API returns it (`007`, never `7`) — every place that builds an image URL must use the string field, never `(string) (int) $localId`.
- tcgdex base URL: `https://api.tcgdex.net/v2/en` (no API key required).

---

### Task 1: Scaffold the Laravel app with Sail, Pest, and spatie/laravel-data

**Files:**
- Create: whole new Laravel 13 project at the repo root (`composer.json`, `artisan`, `app/`, `database/`, `tests/`, `docker-compose.yml` from Sail, `.env.example`)
- Modify: `.env.example`, `phpunit.xml` (Pest uses this under the hood)

**Interfaces:**
- Produces: a runnable `sail up`, a passing `sail artisan test` with the default example tests, `spatie/laravel-data` installed and autoloading.

- [ ] **Step 1: Create the Laravel project**

```bash
cd /home/cativo23/projects/personal/tcg-vault
composer create-project laravel/laravel:^13.0 . --prefer-dist
```

This installs into the current directory (which already holds `design.md`
and `docs/` from the brainstorming phase — Composer will not touch those).

- [ ] **Step 2: Install Sail with Postgres + Redis, and start it**

```bash
composer require laravel/sail --dev
php artisan sail:install --with=pgsql,redis
```

Answer `pgsql` and `redis` when prompted for services. This writes
`docker-compose.yml` at the project root.

- [ ] **Step 3: Point `.env` at Postgres and start the stack**

Edit `.env` (created by `sail:install`) so the DB block reads:

```env
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=tcg_vault
DB_USERNAME=sail
DB_PASSWORD=password
```

```bash
./vendor/bin/sail up -d
```

Expected: `sail ps` shows `laravel.test`, `pgsql`, and `redis` containers as `running`.

- [ ] **Step 4: Run the default migrations to confirm the DB connection works**

```bash
./vendor/bin/sail artisan migrate
```

Expected: `Migration table created successfully.` followed by the default
Laravel migrations (users, cache, jobs) running with no errors.

- [ ] **Step 5: Install Pest and spatie/laravel-data**

```bash
./vendor/bin/sail composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies
./vendor/bin/sail artisan pest:install
./vendor/bin/sail composer require spatie/laravel-data
```

When `pest:install` asks to remove PHPUnit, confirm yes — this project uses
Pest exclusively per the Global Constraints.

- [ ] **Step 6: Run the test suite to confirm Pest works end to end**

```bash
./vendor/bin/sail artisan test
```

Expected: `PASS` on the default `Tests\Feature\ExampleTest` and
`Tests\Unit\ExampleTest`, 0 failures.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "chore: scaffold Laravel 13 app with Sail, Pest, spatie/laravel-data

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 2: Catalog migrations and Eloquent models

**Files:**
- Create: `database/migrations/2026_09_14_000001_create_sets_table.php`
- Create: `database/migrations/2026_09_14_000002_create_cards_table.php`
- Create: `database/migrations/2026_09_14_000003_create_card_price_snapshots_table.php`
- Create: `app/Modules/Catalog/Models/Set.php`
- Create: `app/Modules/Catalog/Models/Card.php`
- Create: `app/Modules/Catalog/Models/CardPriceSnapshot.php`
- Test: `tests/Feature/Modules/Catalog/CatalogSchemaTest.php`

**Interfaces:**
- Produces: `Set`, `Card`, `CardPriceSnapshot` Eloquent models under
  `App\Modules\Catalog\Models`, with the relationships `Set::cards()`,
  `Card::set()`, `Card::priceSnapshots()`.

- [ ] **Step 1: Write the `sets` migration**

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
        Schema::create('sets', function (Blueprint $table) {
            $table->id();
            $table->string('tcgdex_id')->unique();
            $table->string('name');
            $table->string('series')->nullable();
            $table->date('released_on')->nullable();
            $table->unsignedInteger('card_count')->nullable();
            $table->string('logo_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sets');
    }
};
```

- [ ] **Step 2: Write the `cards` migration**

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
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->string('tcgdex_id')->unique();
            $table->foreignId('set_id')->constrained('sets')->cascadeOnDelete();
            $table->string('local_id'); // zero-padded as tcgdex returns it, e.g. "007"
            $table->string('name');
            $table->string('rarity')->nullable();
            $table->jsonb('variants')->nullable();
            $table->string('official_image_url')->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['set_id', 'local_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
```

- [ ] **Step 3: Write the `card_price_snapshots` migration**

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
        Schema::create('card_price_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->string('source'); // 'cardmarket' | 'tcgplayer'
            $table->string('variant'); // e.g. 'default', 'normal', 'holofoil', 'reverse-holofoil'
            $table->date('captured_on');
            $table->char('currency', 3);
            $table->bigInteger('market_minor')->nullable();
            $table->bigInteger('low_minor')->nullable();
            $table->bigInteger('trend_minor')->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['card_id', 'source', 'variant', 'captured_on'], 'card_price_snapshots_unique_capture');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_price_snapshots');
    }
};
```

- [ ] **Step 4: Write the models**

`app/Modules/Catalog/Models/Set.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Set extends Model
{
    use HasFactory;

    protected $fillable = [
        'tcgdex_id',
        'name',
        'series',
        'released_on',
        'card_count',
        'logo_url',
    ];

    protected function casts(): array
    {
        return [
            'released_on' => 'date',
            'card_count' => 'integer',
        ];
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }
}
```

`app/Modules/Catalog/Models/Card.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Card extends Model
{
    use HasFactory;

    protected $fillable = [
        'tcgdex_id',
        'set_id',
        'local_id',
        'name',
        'rarity',
        'variants',
        'official_image_url',
        'raw',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'raw' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function set(): BelongsTo
    {
        return $this->belongsTo(Set::class);
    }

    public function priceSnapshots(): HasMany
    {
        return $this->hasMany(CardPriceSnapshot::class);
    }
}
```

`app/Modules/Catalog/Models/CardPriceSnapshot.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CardPriceSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'card_id',
        'source',
        'variant',
        'captured_on',
        'currency',
        'market_minor',
        'low_minor',
        'trend_minor',
        'raw',
        'source_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'captured_on' => 'date',
            'market_minor' => 'integer',
            'low_minor' => 'integer',
            'trend_minor' => 'integer',
            'raw' => 'array',
            'source_updated_at' => 'datetime',
        ];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
```

- [ ] **Step 5: Write the schema test**

```php
<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;

test('a set can have many cards, and a card belongs to a set', function () {
    $set = Set::create([
        'tcgdex_id' => 'me05',
        'name' => 'Pitch Black',
        'series' => 'Mega Evolution',
        'card_count' => 84,
    ]);

    $card = Card::create([
        'tcgdex_id' => 'me05-116',
        'set_id' => $set->id,
        'local_id' => '116',
        'name' => 'Mega Darkrai ex',
        'rarity' => 'Special Illustration Rare',
    ]);

    expect($set->cards)->toHaveCount(1);
    expect($card->set->tcgdex_id)->toBe('me05');
});

test('a card can have many price snapshots, unique per source+variant+day', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create([
        'tcgdex_id' => 'me05-116',
        'set_id' => $set->id,
        'local_id' => '116',
        'name' => 'Mega Darkrai ex',
    ]);

    CardPriceSnapshot::create([
        'card_id' => $card->id,
        'source' => 'tcgplayer',
        'variant' => 'holofoil',
        'captured_on' => '2026-09-14',
        'currency' => 'USD',
        'market_minor' => 19524,
    ]);

    expect($card->priceSnapshots)->toHaveCount(1);
    expect($card->priceSnapshots->first()->market_minor)->toBe(19524);
});

test('duplicate snapshot for the same card+source+variant+day is rejected', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create([
        'tcgdex_id' => 'me05-116',
        'set_id' => $set->id,
        'local_id' => '116',
        'name' => 'Mega Darkrai ex',
    ]);

    CardPriceSnapshot::create([
        'card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil',
        'captured_on' => '2026-09-14', 'currency' => 'USD', 'market_minor' => 19524,
    ]);

    expect(fn () => CardPriceSnapshot::create([
        'card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil',
        'captured_on' => '2026-09-14', 'currency' => 'USD', 'market_minor' => 20000,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 6: Run migrations and the test file**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan test --filter=CatalogSchemaTest
```

Expected: 3 passed.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(catalog): add sets/cards/card_price_snapshots schema and models

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 3: Catalog DTOs and the `CardCatalogProvider` interface

**Files:**
- Create: `app/Modules/Catalog/Data/PriceEntryData.php`
- Create: `app/Modules/Catalog/Data/CardDetailData.php`
- Create: `app/Modules/Catalog/Data/SetSummaryData.php`
- Create: `app/Modules/Catalog/Contracts/CardCatalogProvider.php`

**Interfaces:**
- Consumes: nothing new (pure data-shape definitions).
- Produces: `PriceEntryData`, `CardDetailData`, `SetSummaryData` (all
  `Spatie\LaravelData\Data` subclasses), and the `CardCatalogProvider`
  contract with methods `findCard(string $tcgdexId): CardDetailData`,
  `findSet(string $tcgdexId): SetSummaryData`, `listSetCardIds(string $setTcgdexId): array<string>`.
  Later tasks (the tcgdex implementation, the sync service) depend on these
  exact names and shapes — do not rename without updating this doc.

- [ ] **Step 1: Write `PriceEntryData`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class PriceEntryData extends Data
{
    public function __construct(
        public string $source, // 'cardmarket' | 'tcgplayer'
        public string $variant, // 'default' | 'normal' | 'holofoil' | 'reverse-holofoil' | ...
        public string $currency, // ISO 4217, e.g. 'USD', 'EUR'
        public ?int $marketMinor,
        public ?int $lowMinor,
        public ?int $trendMinor,
        public ?CarbonImmutable $sourceUpdatedAt,
        /** @var array<string, mixed> */
        public array $raw,
    ) {}
}
```

- [ ] **Step 2: Write `CardDetailData`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class CardDetailData extends Data
{
    public function __construct(
        public string $tcgdexId,
        public string $setTcgdexId,
        public string $localId, // zero-padded exactly as tcgdex returns it
        public string $name,
        public ?string $rarity,
        /** @var array<string, mixed> */
        public array $variants,
        public ?string $officialImageUrl,
        /** @var DataCollection<int, PriceEntryData> */
        public DataCollection $prices,
        /** @var array<string, mixed> */
        public array $raw,
    ) {}
}
```

- [ ] **Step 3: Write `SetSummaryData`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class SetSummaryData extends Data
{
    public function __construct(
        public string $tcgdexId,
        public string $name,
        public ?string $series,
        public ?CarbonImmutable $releasedOn,
        public ?int $cardCount,
        public ?string $logoUrl,
    ) {}
}
```

- [ ] **Step 4: Write the `CardCatalogProvider` contract**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\SetSummaryData;

interface CardCatalogProvider
{
    /**
     * Fetch full detail + current pricing for one card.
     *
     * @throws \App\Modules\Catalog\Exceptions\CardNotFoundException
     */
    public function findCard(string $tcgdexId): CardDetailData;

    /**
     * Fetch metadata for one set (no card list).
     *
     * @throws \App\Modules\Catalog\Exceptions\SetNotFoundException
     */
    public function findSet(string $tcgdexId): SetSummaryData;

    /**
     * List the tcgdex card IDs belonging to a set, e.g. ['me05-001', 'me05-002', ...].
     * Does NOT include pricing — tcgdex has no bulk-pricing endpoint, so callers
     * must call findCard() per ID to get prices.
     *
     * @return array<int, string>
     */
    public function listSetCardIds(string $setTcgdexId): array;
}
```

- [ ] **Step 5: Add the two exception classes the contract references**

`app/Modules/Catalog/Exceptions/CardNotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use RuntimeException;

final class CardNotFoundException extends RuntimeException
{
    public static function forTcgdexId(string $tcgdexId): self
    {
        return new self("No card found on tcgdex for ID [{$tcgdexId}].");
    }
}
```

`app/Modules/Catalog/Exceptions/SetNotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use RuntimeException;

final class SetNotFoundException extends RuntimeException
{
    public static function forTcgdexId(string $tcgdexId): self
    {
        return new self("No set found on tcgdex for ID [{$tcgdexId}].");
    }
}
```

- [ ] **Step 6: Verify autoloading with a throwaway tinker check**

```bash
./vendor/bin/sail artisan tinker --execute="echo App\Modules\Catalog\Data\PriceEntryData::class;"
```

Expected: prints `App\Modules\Catalog\Data\PriceEntryData` with no error.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(catalog): add Catalog DTOs and CardCatalogProvider contract

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 4: `TcgdexCardCatalogProvider` implementation + tests

**Files:**
- Create: `config/tcgdex.php`
- Create: `app/Modules/Catalog/Providers/TcgdexCardCatalogProvider.php`
- Test: `tests/Unit/Modules/Catalog/TcgdexCardCatalogProviderTest.php`

**Interfaces:**
- Consumes: `CardCatalogProvider`, `CardDetailData`, `SetSummaryData`,
  `PriceEntryData` from Task 3.
- Produces: a concrete `TcgdexCardCatalogProvider implements CardCatalogProvider`.

This is the module's only point of contact with a real HTTP dependency —
every other Catalog class only ever sees the DTOs above.

- [ ] **Step 1: Add the tcgdex config file**

```php
<?php

declare(strict_types=1);

return [
    'base_url' => env('TCGDEX_BASE_URL', 'https://api.tcgdex.net/v2/en'),
];
```

- [ ] **Step 2: Write the failing test for `findCard()`, using a real captured payload shape**

```php
<?php

declare(strict_types=1);

use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Exceptions\SetNotFoundException;
use App\Modules\Catalog\Providers\TcgdexCardCatalogProvider;
use Illuminate\Support\Facades\Http;

function fakeTcgdexCardPayload(): array
{
    // Trimmed but structurally real response for me05-116 (Mega Darkrai ex),
    // captured against the live API during design — see design spec §3.
    return [
        'id' => 'me05-116',
        'localId' => '116',
        'name' => 'Mega Darkrai ex',
        'rarity' => 'Special Illustration Rare',
        'image' => 'https://assets.tcgdex.net/en/me/me05/116',
        'set' => ['id' => 'me05', 'name' => 'Pitch Black'],
        'variants' => ['holo' => true, 'normal' => false, 'reverse' => false],
        'pricing' => [
            'cardmarket' => [
                'updated' => '2026-09-14T17:09:10.051Z',
                'unit' => 'EUR',
                'avg' => 178.40,
                'low' => 134.99,
                'trend' => 182.10,
            ],
            'tcgplayer' => [
                'unit' => 'USD',
                'updated' => '2026-09-14T17:11:20.936Z',
                'holofoil' => [
                    'lowPrice' => 190.16,
                    'midPrice' => 218.50,
                    'highPrice' => 999.99,
                    'marketPrice' => 195.24,
                ],
            ],
        ],
    ];
}

test('findCard maps a tcgdex payload into a CardDetailData with normalized prices', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/cards/me05-116' => Http::response(fakeTcgdexCardPayload(), 200),
    ]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));
    $card = $provider->findCard('me05-116');

    expect($card->tcgdexId)->toBe('me05-116');
    expect($card->setTcgdexId)->toBe('me05');
    expect($card->localId)->toBe('116'); // zero-padded string preserved, not cast to int
    expect($card->officialImageUrl)->toBe('https://assets.tcgdex.net/en/me/me05/116/high.webp');

    expect($card->prices)->toHaveCount(2);

    $cardmarket = $card->prices->first(fn ($p) => $p->source === 'cardmarket');
    expect($cardmarket->variant)->toBe('default');
    expect($cardmarket->currency)->toBe('EUR');
    expect($cardmarket->marketMinor)->toBe(17840); // 178.40 EUR -> minor units

    $tcgplayer = $card->prices->first(fn ($p) => $p->source === 'tcgplayer');
    expect($tcgplayer->variant)->toBe('holofoil');
    expect($tcgplayer->currency)->toBe('USD');
    expect($tcgplayer->marketMinor)->toBe(19524); // 195.24 USD -> minor units
    expect($tcgplayer->lowMinor)->toBe(19016);
    expect($tcgplayer->trendMinor)->toBeNull(); // tcgplayer payload has no trend field
});

test('findCard throws CardNotFoundException on a 404', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/cards/does-not-exist' => Http::response(null, 404),
    ]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));

    expect(fn () => $provider->findCard('does-not-exist'))
        ->toThrow(CardNotFoundException::class);
});

test('findSet maps a tcgdex set payload into SetSummaryData', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/sets/me05' => Http::response([
            'id' => 'me05',
            'name' => 'Pitch Black',
            'serie' => ['name' => 'Mega Evolution'],
            'cardCount' => ['total' => 120, 'official' => 84],
            'logo' => 'https://assets.tcgdex.net/en/me/me05/logo',
        ], 200),
    ]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));
    $set = $provider->findSet('me05');

    expect($set->tcgdexId)->toBe('me05');
    expect($set->name)->toBe('Pitch Black');
    expect($set->series)->toBe('Mega Evolution');
    expect($set->cardCount)->toBe(84);
    expect($set->logoUrl)->toBe('https://assets.tcgdex.net/en/me/me05/logo.png');
});

test('findSet throws SetNotFoundException on a 404', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/sets/does-not-exist' => Http::response(null, 404),
    ]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));

    expect(fn () => $provider->findSet('does-not-exist'))
        ->toThrow(SetNotFoundException::class);
});

test('listSetCardIds returns the zero-padded local card IDs prefixed with the set ID', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/sets/me05' => Http::response([
            'id' => 'me05',
            'name' => 'Pitch Black',
            'cards' => [
                ['id' => 'me05-006', 'localId' => '006', 'name' => 'Sinistcha'],
                ['id' => 'me05-007', 'localId' => '007', 'name' => 'Heatran'],
            ],
        ], 200),
    ]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));
    $ids = $provider->listSetCardIds('me05');

    expect($ids)->toBe(['me05-006', 'me05-007']);
});
```

- [ ] **Step 3: Run it to see it fail**

```bash
./vendor/bin/sail artisan test --filter=TcgdexCardCatalogProviderTest
```

Expected: FAIL — `Class "App\Modules\Catalog\Providers\TcgdexCardCatalogProvider" not found`.

- [ ] **Step 4: Implement `TcgdexCardCatalogProvider`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Exceptions\SetNotFoundException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Spatie\LaravelData\DataCollection;

final class TcgdexCardCatalogProvider implements CardCatalogProvider
{
    public function __construct(private readonly string $baseUrl) {}

    public function findCard(string $tcgdexId): CardDetailData
    {
        $response = Http::baseUrl($this->baseUrl)->get("cards/{$tcgdexId}");

        if ($response->status() === 404) {
            throw CardNotFoundException::forTcgdexId($tcgdexId);
        }

        $response->throw();

        $json = $response->json();

        return new CardDetailData(
            tcgdexId: $json['id'],
            setTcgdexId: $json['set']['id'],
            localId: $json['localId'], // never cast to int — zero-padding must survive
            name: $json['name'],
            rarity: $json['rarity'] ?? null,
            variants: $json['variants'] ?? [],
            officialImageUrl: isset($json['image']) ? "{$json['image']}/high.webp" : null,
            prices: new DataCollection(PriceEntryData::class, $this->extractPrices($json['pricing'] ?? [])),
            raw: $json,
        );
    }

    public function findSet(string $tcgdexId): SetSummaryData
    {
        $response = Http::baseUrl($this->baseUrl)->get("sets/{$tcgdexId}");

        if ($response->status() === 404) {
            throw SetNotFoundException::forTcgdexId($tcgdexId);
        }

        $response->throw();

        $json = $response->json();

        return new SetSummaryData(
            tcgdexId: $json['id'],
            name: $json['name'],
            series: $json['serie']['name'] ?? null,
            releasedOn: isset($json['releaseDate']) ? CarbonImmutable::parse($json['releaseDate']) : null,
            cardCount: $json['cardCount']['official'] ?? null,
            logoUrl: isset($json['logo']) ? "{$json['logo']}.png" : null,
        );
    }

    public function listSetCardIds(string $setTcgdexId): array
    {
        $response = Http::baseUrl($this->baseUrl)->get("sets/{$setTcgdexId}");

        if ($response->status() === 404) {
            throw SetNotFoundException::forTcgdexId($setTcgdexId);
        }

        $response->throw();

        $cards = $response->json('cards', []);

        return array_map(static fn (array $card): string => $card['id'], $cards);
    }

    /**
     * @param array<string, mixed> $pricing
     * @return array<int, PriceEntryData>
     */
    private function extractPrices(array $pricing): array
    {
        $entries = [];

        if (isset($pricing['cardmarket'])) {
            $cm = $pricing['cardmarket'];
            $updated = isset($cm['updated']) ? CarbonImmutable::parse($cm['updated']) : null;

            $entries[] = new PriceEntryData(
                source: 'cardmarket',
                variant: 'default',
                currency: $cm['unit'] ?? 'EUR',
                marketMinor: $this->toMinorUnits($cm['avg'] ?? null),
                lowMinor: $this->toMinorUnits($cm['low'] ?? null),
                trendMinor: $this->toMinorUnits($cm['trend'] ?? null),
                sourceUpdatedAt: $updated,
                raw: $cm,
            );

            if (isset($cm['avg-holo'])) {
                $entries[] = new PriceEntryData(
                    source: 'cardmarket',
                    variant: 'holofoil',
                    currency: $cm['unit'] ?? 'EUR',
                    marketMinor: $this->toMinorUnits($cm['avg-holo'] ?? null),
                    lowMinor: $this->toMinorUnits($cm['low-holo'] ?? null),
                    trendMinor: $this->toMinorUnits($cm['trend-holo'] ?? null),
                    sourceUpdatedAt: $updated,
                    raw: $cm,
                );
            }
        }

        if (isset($pricing['tcgplayer'])) {
            $tp = $pricing['tcgplayer'];
            $updated = isset($tp['updated']) ? CarbonImmutable::parse($tp['updated']) : null;
            $currency = $tp['unit'] ?? 'USD';

            foreach ($tp as $variantKey => $variantData) {
                if (! is_array($variantData) || ! isset($variantData['marketPrice'])) {
                    continue; // skip 'unit' / 'updated' scalar keys
                }

                $entries[] = new PriceEntryData(
                    source: 'tcgplayer',
                    variant: $variantKey,
                    currency: $currency,
                    marketMinor: $this->toMinorUnits($variantData['marketPrice'] ?? null),
                    lowMinor: $this->toMinorUnits($variantData['lowPrice'] ?? null),
                    trendMinor: null, // tcgplayer's per-variant payload has no trend figure
                    sourceUpdatedAt: $updated,
                    raw: $variantData,
                );
            }
        }

        return $entries;
    }

    private function toMinorUnits(?float $amount): ?int
    {
        return $amount === null ? null : (int) round($amount * 100);
    }
}
```

- [ ] **Step 5: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=TcgdexCardCatalogProviderTest
```

Expected: 5 passed.

- [ ] **Step 6: Bind the interface in a service provider**

Create `app/Modules/Catalog/CatalogServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Providers\TcgdexCardCatalogProvider;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            CardCatalogProvider::class,
            fn () => new TcgdexCardCatalogProvider(config('tcgdex.base_url')),
        );
    }
}
```

Register it in `bootstrap/providers.php` by adding
`App\Modules\Catalog\CatalogServiceProvider::class` to the returned array.

- [ ] **Step 7: Verify the binding resolves**

```bash
./vendor/bin/sail artisan tinker --execute="echo get_class(app(App\Modules\Catalog\Contracts\CardCatalogProvider::class));"
```

Expected: prints `App\Modules\Catalog\Providers\TcgdexCardCatalogProvider`.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(catalog): implement TcgdexCardCatalogProvider with price normalization

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 5: `CatalogSyncService` — persist a tcgdex card into the database

**Files:**
- Create: `app/Modules/Catalog/Services/CatalogSyncService.php`
- Test: `tests/Unit/Modules/Catalog/CatalogSyncServiceTest.php`

**Interfaces:**
- Consumes: `CardCatalogProvider` (Task 3/4), `Set`/`Card`/`CardPriceSnapshot` models (Task 2).
- Produces: `CatalogSyncService::syncCard(string $tcgdexCardId): Card`.
  Later tasks (the import command, and Phase 4's scheduled snapshot job)
  call this exact method.

- [ ] **Step 1: Write the failing test, using a fake `CardCatalogProvider`**

```php
<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CatalogSyncService;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\DataCollection;

function fakeCardDetail(): CardDetailData
{
    return new CardDetailData(
        tcgdexId: 'me05-116',
        setTcgdexId: 'me05',
        localId: '116',
        name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare',
        variants: ['holo' => true],
        officialImageUrl: 'https://assets.tcgdex.net/en/me/me05/116/high.webp',
        prices: new DataCollection(PriceEntryData::class, [
            new PriceEntryData(
                source: 'tcgplayer', variant: 'holofoil', currency: 'USD',
                marketMinor: 19524, lowMinor: 19016, trendMinor: null,
                sourceUpdatedAt: CarbonImmutable::parse('2026-09-14T17:11:20Z'),
                raw: [],
            ),
        ]),
        raw: ['id' => 'me05-116'],
    );
}

test('syncCard creates the set, the card, and its price snapshots on first sync', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->andReturn(fakeCardDetail());
    $provider->shouldReceive('findSet')->with('me05')->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: 'Mega Evolution',
        releasedOn: null, cardCount: 84, logoUrl: 'https://assets.tcgdex.net/en/me/me05/logo.png',
    ));

    $service = new CatalogSyncService($provider);
    $card = $service->syncCard('me05-116');

    expect(Set::where('tcgdex_id', 'me05')->exists())->toBeTrue();
    expect($card->name)->toBe('Mega Darkrai ex');
    expect($card->local_id)->toBe('116');
    expect($card->priceSnapshots)->toHaveCount(1);

    $snapshot = $card->priceSnapshots->first();
    expect($snapshot->source)->toBe('tcgplayer');
    expect($snapshot->variant)->toBe('holofoil');
    expect($snapshot->market_minor)->toBe(19524);
    expect($snapshot->captured_on->toDateString())->toBe(CarbonImmutable::today()->toDateString());
});

test('syncing the same card twice on the same day updates the card but does not duplicate the snapshot', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->twice()->andReturn(fakeCardDetail());
    $provider->shouldReceive('findSet')->with('me05')->twice()->andReturn(new SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));

    $service = new CatalogSyncService($provider);
    $service->syncCard('me05-116');
    $service->syncCard('me05-116');

    expect(Card::where('tcgdex_id', 'me05-116')->count())->toBe(1);
    expect(CardPriceSnapshot::count())->toBe(1);
});
```

- [ ] **Step 2: Run it to see it fail**

```bash
./vendor/bin/sail artisan test --filter=CatalogSyncServiceTest
```

Expected: FAIL — `Class "App\Modules\Catalog\Services\CatalogSyncService" not found`.

- [ ] **Step 3: Implement `CatalogSyncService`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use Carbon\CarbonImmutable;

final class CatalogSyncService
{
    public function __construct(private readonly CardCatalogProvider $provider) {}

    public function syncCard(string $tcgdexCardId): Card
    {
        $cardDetail = $this->provider->findCard($tcgdexCardId);
        $set = $this->syncSet($cardDetail->setTcgdexId);

        $card = Card::updateOrCreate(
            ['tcgdex_id' => $cardDetail->tcgdexId],
            [
                'set_id' => $set->id,
                'local_id' => $cardDetail->localId,
                'name' => $cardDetail->name,
                'rarity' => $cardDetail->rarity,
                'variants' => $cardDetail->variants,
                'official_image_url' => $cardDetail->officialImageUrl,
                'raw' => $cardDetail->raw,
                'synced_at' => CarbonImmutable::now(),
            ],
        );

        $this->storePriceSnapshots($card, $cardDetail);

        return $card->fresh(['priceSnapshots']);
    }

    private function syncSet(string $setTcgdexId): Set
    {
        $setSummary = $this->provider->findSet($setTcgdexId);

        return Set::updateOrCreate(
            ['tcgdex_id' => $setSummary->tcgdexId],
            [
                'name' => $setSummary->name,
                'series' => $setSummary->series,
                'released_on' => $setSummary->releasedOn,
                'card_count' => $setSummary->cardCount,
                'logo_url' => $setSummary->logoUrl,
            ],
        );
    }

    private function storePriceSnapshots(Card $card, CardDetailData $cardDetail): void
    {
        $today = CarbonImmutable::today()->toDateString();

        foreach ($cardDetail->prices as $price) {
            CardPriceSnapshot::updateOrCreate(
                [
                    'card_id' => $card->id,
                    'source' => $price->source,
                    'variant' => $price->variant,
                    'captured_on' => $today,
                ],
                [
                    'currency' => $price->currency,
                    'market_minor' => $price->marketMinor,
                    'low_minor' => $price->lowMinor,
                    'trend_minor' => $price->trendMinor,
                    'raw' => $price->raw,
                    'source_updated_at' => $price->sourceUpdatedAt,
                ],
            );
        }
    }
}
```

- [ ] **Step 4: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=CatalogSyncServiceTest
```

Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(catalog): add CatalogSyncService to persist tcgdex cards and price snapshots

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 6: `catalog:import-set` artisan command

**Files:**
- Create: `app/Console/Commands/ImportCatalogSet.php`
- Test: `tests/Feature/Modules/Catalog/ImportCatalogSetCommandTest.php`

**Interfaces:**
- Consumes: `CardCatalogProvider::listSetCardIds()` (Task 4),
  `CatalogSyncService::syncCard()` (Task 5).
- Produces: `php artisan catalog:import-set {setId}` — the first
  end-to-end, runnable proof of the Catalog module. This is what Carlos
  runs locally to seed real Pitch Black data for the Phase 2/3 UI work.

- [ ] **Step 1: Write the failing feature test**

```php
<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use Illuminate\Support\Facades\Http;

test('catalog:import-set imports every card in a set with its current price', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/sets/me05' => Http::response([
            'id' => 'me05',
            'name' => 'Pitch Black',
            'serie' => ['name' => 'Mega Evolution'],
            'cardCount' => ['official' => 2],
            'logo' => 'https://assets.tcgdex.net/en/me/me05/logo',
            'cards' => [
                ['id' => 'me05-006', 'localId' => '006', 'name' => 'Sinistcha'],
                ['id' => 'me05-007', 'localId' => '007', 'name' => 'Heatran'],
            ],
        ], 200),
        'api.tcgdex.net/v2/en/cards/me05-006' => Http::response([
            'id' => 'me05-006', 'localId' => '006', 'name' => 'Sinistcha', 'rarity' => 'Common',
            'image' => 'https://assets.tcgdex.net/en/me/me05/006',
            'set' => ['id' => 'me05'], 'variants' => [],
            'pricing' => ['tcgplayer' => ['unit' => 'USD', 'normal' => ['marketPrice' => 0.90]]],
        ], 200),
        'api.tcgdex.net/v2/en/cards/me05-007' => Http::response([
            'id' => 'me05-007', 'localId' => '007', 'name' => 'Heatran', 'rarity' => 'Rare Holo',
            'image' => 'https://assets.tcgdex.net/en/me/me05/007',
            'set' => ['id' => 'me05'], 'variants' => [],
            'pricing' => ['tcgplayer' => ['unit' => 'USD', 'holofoil' => ['marketPrice' => 1.20]]],
        ], 200),
    ]);

    $this->artisan('catalog:import-set', ['setId' => 'me05'])
        ->expectsOutputToContain('Imported 2 / 2 cards for set [me05].')
        ->assertExitCode(0);

    expect(Set::where('tcgdex_id', 'me05')->exists())->toBeTrue();
    expect(Card::count())->toBe(2);
    expect(Card::where('tcgdex_id', 'me05-007')->first()->priceSnapshots)->toHaveCount(1);
});

test('catalog:import-set reports a failed card without aborting the whole run', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/sets/me05' => Http::response([
            'id' => 'me05', 'name' => 'Pitch Black',
            'cards' => [
                ['id' => 'me05-006', 'localId' => '006', 'name' => 'Sinistcha'],
                ['id' => 'me05-999', 'localId' => '999', 'name' => 'Broken'],
            ],
        ], 200),
        'api.tcgdex.net/v2/en/cards/me05-006' => Http::response([
            'id' => 'me05-006', 'localId' => '006', 'name' => 'Sinistcha',
            'image' => 'https://assets.tcgdex.net/en/me/me05/006',
            'set' => ['id' => 'me05'], 'variants' => [], 'pricing' => [],
        ], 200),
        'api.tcgdex.net/v2/en/cards/me05-999' => Http::response(null, 404),
    ]);

    $this->artisan('catalog:import-set', ['setId' => 'me05'])
        ->expectsOutputToContain('Failed: me05-999')
        ->expectsOutputToContain('Imported 1 / 2 cards for set [me05].')
        ->assertExitCode(0);

    expect(Card::count())->toBe(1); // the broken card is skipped, not fatal
});
```

- [ ] **Step 2: Run it to see it fail**

```bash
./vendor/bin/sail artisan test --filter=ImportCatalogSetCommandTest
```

Expected: FAIL — command `catalog:import-set` does not exist.

- [ ] **Step 3: Implement the command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Services\CatalogSyncService;
use Illuminate\Console\Command;

final class ImportCatalogSet extends Command
{
    protected $signature = 'catalog:import-set {setId : The tcgdex set ID, e.g. me05}';

    protected $description = 'Import every card in a tcgdex set, with current pricing, into the local Catalog.';

    public function handle(CardCatalogProvider $provider, CatalogSyncService $syncService): int
    {
        $setId = $this->argument('setId');
        $cardIds = $provider->listSetCardIds($setId);

        $imported = 0;

        $this->withProgressBar($cardIds, function (string $cardId) use ($syncService, &$imported) {
            try {
                $syncService->syncCard($cardId);
                $imported++;
            } catch (CardNotFoundException) {
                $this->newLine();
                $this->warn("Failed: {$cardId}");
            }
        });

        $this->newLine(2);
        $this->info(sprintf('Imported %d / %d cards for set [%s].', $imported, count($cardIds), $setId));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=ImportCatalogSetCommandTest
```

Expected: 2 passed.

- [ ] **Step 5: Run the full test suite for the module**

```bash
./vendor/bin/sail artisan test
```

Expected: all tests passed (schema, provider, sync service, command — plus
the untouched Laravel default tests from Task 1).

- [ ] **Step 6: Prove it against the real tcgdex API (not `Http::fake`), manually, once**

```bash
./vendor/bin/sail artisan catalog:import-set me05
./vendor/bin/sail artisan tinker --execute="echo App\Modules\Catalog\Models\Card::count();"
```

Expected: a progress bar, then `Imported 84 / 84 cards for set [me05].`
(some individual cards may legitimately fail if tcgdex is rate-limiting —
that's fine, the command reports and continues). The tinker count should
roughly match.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(catalog): add catalog:import-set command, closes Phase 1

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

## What Phase 1 deliberately does NOT include

- No `Collection` module, no admin UI, no auth — that's Phase 2.
- No Livewire, no public gallery screens — that's Phase 3.
- No scheduled/queued daily snapshot job — Phase 1's command is a manual,
  synchronous, one-off import for seeding local dev data. The real daily
  job (queued, per-card, with retry/backoff, running against *every card a
  user owns* rather than a whole set) is Phase 4, and will reuse
  `CatalogSyncService::syncCard()` unchanged as its per-job unit of work —
  that reuse is the reason this phase built the service instead of putting
  the logic directly in the command.
- No deploy — Phase 5.
