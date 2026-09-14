# Phase 2 — Collection Module & Admin UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A logged-in admin (Carlos, single user) can search tcgdex by card
name, pick the right printing, add it to his collection with condition/
grade/quantity/notes and an optional personal photo, and see/edit/delete
what he's added — the first real screens of tcg-vault, styled per the
locked `design.md` system.

**Architecture:** Adds the `Collection` module (tenant-scoped, global
Eloquent scope, never a hand-written `where('user_id', ...)`) alongside
Phase 1's `Catalog` module (global, never tenant-scoped). Admin auth via
Laravel Breeze's Livewire stack. Admin screens are hand-built Livewire
components consuming `design.md`'s tokens directly (no Filament — confirmed
with Carlos: Filament's own theme would fight the locked design system).

**Tech Stack:** Laravel 13, Livewire (Breeze `livewire` stack), Postgres 17,
`spatie/laravel-data`, Pest.

## Global Constraints

- PHP `^8.3`, Laravel `^13.0`. Every new PHP file starts with `declare(strict_types=1);`.
- Postgres 17. Tests use Pest, real Postgres (never SQLite) — `tests/Pest.php` already binds `RefreshDatabase` correctly per Phase 1's final fix; any NEW test directory added in this phase must be checked against the existing `uses(...)->in(...)` bindings in `tests/Pest.php` before assuming it inherits DB access — read that file first in every task that adds a DB-touching test.
- `Collection` module code lives under `app/Modules/Collection/`. Never a hand-written `where('user_id', ...)` — always the tenant global scope defined in Task 2.
- `Catalog` module code (from Phase 1) stays under `app/Modules/Catalog/` — Task 3 extends its existing `CardCatalogProvider` contract, it does not create a parallel one.
- Money stays `bigint` minor units + explicit currency wherever price is touched (unchanged from Phase 1 — this phase mostly reads existing `CardPriceSnapshot` rows, doesn't mint new money logic).
- tcgdex image URLs use the zero-padded `localId` string, never cast through `(int)` — same rule as Phase 1, now also relevant to the new search-result DTO.
- No purchase-price / profit tracking anywhere in this phase (explicitly descoped in the design spec).
- Design tokens: reuse the `:root` custom properties from `design.md` verbatim (bone/ink palette, Archivo variable-width font, Martian Mono for numerals, one signal-green accent meaning "positive/success," never a second accent color) — don't invent new colors or fonts for admin screens.
- Local dev runs via Sail on **port 8090**, not the default 80 (port 80 is taken by another local service on this machine) — commands in this plan that need the app to actually respond over HTTP use `APP_PORT=8090 ./vendor/bin/sail up -d` and `http://localhost:8090`.

---

### Task 1: Auth scaffold (Breeze/Livewire) + design.md base layout + seeded admin user

**Files:**
- Installs Breeze's Livewire stack (creates `app/Livewire/Auth/*`, `resources/views/livewire/auth/*`, `resources/views/layouts/*`, `routes/auth.php`, modifies `routes/web.php`)
- Modify: `resources/css/app.css` — add `design.md`'s token block
- Modify: `resources/views/layouts/app.blade.php`, `resources/views/layouts/guest.blade.php` — swap default Breeze styling for the design.md palette
- Modify: `database/seeders/DatabaseSeeder.php` — seed exactly one admin user from env vars, not a hardcoded password
- Test: `tests/Feature/Auth/AdminAuthTest.php`

**Interfaces:**
- Produces: a working `/login` route, `/dashboard` route gated behind `auth` middleware, and one seeded user Carlos can actually log in as locally.

- [ ] **Step 1: Install Breeze with the Livewire stack**

```bash
./vendor/bin/sail composer require laravel/breeze --dev
./vendor/bin/sail artisan breeze:install livewire --pest --no-interaction
```

This generates the auth scaffold and, with `--pest`, Pest-flavored auth
tests. Run `npm install` inside the container if the installer doesn't do
it automatically:

```bash
./vendor/bin/sail npm install
```

- [ ] **Step 2: Add the design.md token block to `resources/css/app.css`**

Breeze's installer will have written its own Tailwind entry content at the
top of this file — **do not delete it**, append below it:

```css
/* ─── design.md tokens — the locked visual system, ported verbatim ─── */
:root {
  --bone: #f2efe6;
  --bone-2: #e9e5d8;
  --bone-3: #ded9c9;
  --ink: #141412;
  --ink-2: #23231f;
  --muted: #6d6c62;
  --hair: rgba(20, 20, 18, 0.14);
  --signal: #37d17f;
  --flat: #9a988c;
  --ease: cubic-bezier(0.2, 0.8, 0.2, 1);
}

@import url('https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=Martian+Mono:wdth,wght@75..112.5,300..800&display=swap');

body {
  background: var(--bone);
  color: var(--ink);
  font-family: 'Archivo', system-ui, -apple-system, 'Segoe UI', sans-serif;
  font-variation-settings: 'wdth' 100;
  -webkit-font-smoothing: antialiased;
}

.mono {
  font-family: 'Martian Mono', 'SFMono-Regular', ui-monospace, monospace;
  font-variation-settings: 'wdth' 87.5, 'wght' 600;
  letter-spacing: -0.04em;
}

.nw-topbar {
  background: var(--ink);
  color: var(--bone);
}

.nw-btn-primary {
  background: var(--ink);
  color: var(--bone);
  border-radius: 8px;
  padding: 0.6rem 1.1rem;
  font-weight: 600;
  transition: opacity 160ms var(--ease);
}
.nw-btn-primary:hover {
  opacity: 0.85;
}

.nw-card {
  background: #fbf9f3;
  border-radius: 8px;
  box-shadow: 0 0 0 1px var(--ink);
}
```

- [ ] **Step 3: Swap `resources/views/layouts/guest.blade.php`'s body background**

Find the outer wrapping `<div>` (Breeze typically emits
`class="min-h-screen flex flex-col items-center pt-6 sm:justify-center
sm:pt-0 bg-gray-100 dark:bg-gray-900"`) and replace the background utility
classes with a plain inline style so it doesn't fight Tailwind's default
gray:

```html
<div class="min-h-screen flex flex-col items-center pt-6 sm:justify-center sm:pt-0" style="background: var(--bone)">
```

Remove any `dark:` variant classes on this wrapper — the design system is
light-mode-only (bone paper), not a light/dark toggle.

- [ ] **Step 4: Seed exactly one admin user from env vars**

Edit `database/seeders/DatabaseSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => config('tcgvault.admin_email')],
            [
                'name' => 'Carlos',
                'password' => bcrypt(config('tcgvault.admin_password')),
                'email_verified_at' => now(),
            ],
        );
    }
}
```

Create `config/tcgvault.php`:

```php
<?php

declare(strict_types=1);

return [
    'admin_email' => env('TCGVAULT_ADMIN_EMAIL', 'admin@tcg-vault.test'),
    'admin_password' => env('TCGVAULT_ADMIN_PASSWORD', 'password'),
];
```

Add to `.env.example` (append, don't remove existing lines):

```env
TCGVAULT_ADMIN_EMAIL=admin@tcg-vault.test
TCGVAULT_ADMIN_PASSWORD=password
```

- [ ] **Step 5: Write the auth test**

```php
<?php

declare(strict_types=1);

use App\Models\User;

test('guest is redirected to login when visiting the dashboard', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('seeded admin can log in and reach the dashboard', function () {
    $user = User::factory()->create([
        'email' => 'admin@tcg-vault.test',
        'password' => bcrypt('password'),
    ]);

    $this->post('/login', [
        'email' => 'admin@tcg-vault.test',
        'password' => 'password',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

test('database seeder creates exactly one admin user matching config', function () {
    config(['tcgvault.admin_email' => 'seed-test@tcg-vault.test', 'tcgvault.admin_password' => 'seed-password']);

    $this->seed();

    $user = User::where('email', 'seed-test@tcg-vault.test')->first();
    expect($user)->not->toBeNull();
    expect(\Illuminate\Support\Facades\Hash::check('seed-password', $user->password))->toBeTrue();
});
```

- [ ] **Step 6: Run the tests**

```bash
./vendor/bin/sail artisan test --filter=AdminAuthTest
```

Expected: 3 passed. Also run the full suite once to confirm Breeze's own
generated auth tests (from `--pest`) pass too:

```bash
./vendor/bin/sail artisan test
```

- [ ] **Step 7: Verify manually in the browser**

```bash
./vendor/bin/sail artisan db:seed
```

Then visit `http://localhost:8090/login` (with `APP_PORT=8090` already
running per the Global Constraints) and confirm you can log in with the
seeded credentials and land on `/dashboard`. Take a screenshot or describe
what you see — this is the first real page Carlos will look at.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(auth): add Breeze/Livewire auth scaffold, design.md base tokens, seeded admin user

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 2: Collection module schema, models, and tenant scope

**Files:**
- Create: `database/migrations/2026_09_15_000001_create_collections_table.php`
- Create: `database/migrations/2026_09_15_000002_create_collection_items_table.php`
- Create: `app/Modules/Collection/Models/Collection.php`
- Create: `app/Modules/Collection/Models/CollectionItem.php`
- Create: `app/Modules/Collection/Scopes/TenantScope.php`
- Test: `tests/Feature/Modules/Collection/CollectionSchemaTest.php`

**Interfaces:**
- Produces: `Collection` (belongs to `User`, has many `CollectionItem`),
  `CollectionItem` (belongs to `Collection`, belongs to `App\Modules\Catalog\Models\Card`),
  both with the `TenantScope` global scope applied to `Collection` so every
  query is automatically filtered to the authenticated user — later tasks
  never write `where('user_id', ...)` by hand.

- [ ] **Step 1: Write the `collections` migration**

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
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collections');
    }
};
```

- [ ] **Step 2: Write the `collection_items` migration**

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
        Schema::create('collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained('cards')->restrictOnDelete();
            $table->string('card_tcgdex_id'); // denormalised: the future extraction seam
            $table->string('variant')->nullable();
            $table->string('condition');
            $table->string('grade_company')->nullable();
            $table->string('grade_value')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamps();

            $table->index(['collection_id', 'card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_items');
    }
};
```

- [ ] **Step 3: Write the tenant scope**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Collection\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (auth()->check()) {
            $builder->where($model->getTable() . '.user_id', auth()->id());
        }
    }
}
```

- [ ] **Step 4: Write the models**

`app/Modules/Collection/Models/Collection.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Collection\Models;

use App\Models\User;
use App\Modules\Collection\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Collection extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'slug', 'is_public'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CollectionItem::class);
    }
}
```

`app/Modules/Collection/Models/CollectionItem.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Collection\Models;

use App\Modules\Catalog\Models\Card;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CollectionItem extends Model
{
    use HasFactory;

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
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
```

Note: `CollectionItem` does NOT get the `TenantScope` directly — it's
reached only through `Collection::items()`, and `Collection` is already
scoped. Applying the scope twice (once via the parent, once directly on
the child using a column the child table doesn't have) would break every
query.

- [ ] **Step 5: Write the schema test**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;

test('a collection belongs to a user and the tenant scope filters by the authenticated user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    Collection::factory()->for($owner)->create(['name' => "Owner's", 'slug' => 'owners']);
    Collection::factory()->for($other)->create(['name' => "Other's", 'slug' => 'others']);

    $this->actingAs($owner);
    expect(Collection::count())->toBe(1);
    expect(Collection::first()->name)->toBe("Owner's");
});

test('an unauthenticated context sees no tenant filtering applied (used only by console/seeders)', function () {
    $owner = User::factory()->create();
    Collection::factory()->for($owner)->create(['name' => 'Any', 'slug' => 'any']);

    expect(Collection::count())->toBe(1);
});

test('a collection has many items, and an item belongs to a card', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create([
        'tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex',
    ]);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $item = CollectionItem::create([
        'collection_id' => $collection->id,
        'card_id' => $card->id,
        'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM',
        'quantity' => 1,
    ]);

    expect($collection->items)->toHaveCount(1);
    expect($item->card->name)->toBe('Mega Darkrai ex');
});
```

- [ ] **Step 6: Create the `Collection` factory** (needed by the tests above)

`database/factories/CollectionFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Collection\Models\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;

final class CollectionFactory extends Factory
{
    protected $model = Collection::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'My Collection',
            'slug' => 'my-collection',
            'is_public' => false,
        ];
    }
}
```

- [ ] **Step 7: Run migrations and the test file**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan test --filter=CollectionSchemaTest
```

Expected: 3 passed.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(collection): add collections/collection_items schema, models, tenant scope

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 3: `searchCardsByName` — extend the Catalog provider for admin search

**Files:**
- Create: `app/Modules/Catalog/Data/CardSummaryData.php`
- Modify: `app/Modules/Catalog/Contracts/CardCatalogProvider.php`
- Modify: `app/Modules/Catalog/Providers/TcgdexCardCatalogProvider.php`
- Modify: `tests/Unit/Modules/Catalog/TcgdexCardCatalogProviderTest.php`

**Interfaces:**
- Produces: `CardCatalogProvider::searchCardsByName(string $query): array<int, CardSummaryData>`.
  Task 5's admin search Livewire component calls this exact method.

- [ ] **Step 1: Write `CardSummaryData`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use Spatie\LaravelData\Data;

final class CardSummaryData extends Data
{
    public function __construct(
        public string $tcgdexId,
        public string $setTcgdexId,
        public string $localId,
        public string $name,
        public ?string $imageUrl,
    ) {}
}
```

- [ ] **Step 2: Add the method to the `CardCatalogProvider` interface**

In `app/Modules/Catalog/Contracts/CardCatalogProvider.php`, add (alongside
the existing three methods, import `CardSummaryData` at the top):

```php
    /**
     * Search tcgdex by card name (brief results only — no pricing; call
     * findCard() on a chosen result for full detail + current price).
     *
     * @return array<int, CardSummaryData>
     */
    public function searchCardsByName(string $query): array;
```

- [ ] **Step 3: Write the failing test**

Append to `tests/Unit/Modules/Catalog/TcgdexCardCatalogProviderTest.php`
(read the file first — match its existing helper-function and `Http::fake`
conventions exactly):

```php
test('searchCardsByName maps tcgdex brief results into CardSummaryData', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/cards*' => Http::response([
            ['id' => 'me05-048', 'localId' => '048', 'name' => 'Mega Darkrai ex', 'image' => 'https://assets.tcgdex.net/en/me/me05/048'],
            ['id' => 'me05-101', 'localId' => '101', 'name' => 'Mega Darkrai ex', 'image' => 'https://assets.tcgdex.net/en/me/me05/101'],
        ], 200),
    ]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));
    $results = $provider->searchCardsByName('Mega Darkrai');

    expect($results)->toHaveCount(2);
    expect($results[0]->tcgdexId)->toBe('me05-048');
    expect($results[0]->setTcgdexId)->toBe('me05');
    expect($results[0]->localId)->toBe('048');
    expect($results[0]->imageUrl)->toBe('https://assets.tcgdex.net/en/me/me05/048/high.webp');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.tcgdex.net/v2/en/cards?name=Mega+Darkrai';
    });
});

test('searchCardsByName sends the query as a URL parameter, never string-interpolated into the path', function () {
    Http::fake(['api.tcgdex.net/v2/en/cards*' => Http::response([], 200)]);

    $provider = new TcgdexCardCatalogProvider(config('tcgdex.base_url'));
    // A query containing "://" must never be able to redirect the request —
    // because this goes through Http::get('cards', ['name' => $query]) as a
    // query-string parameter (Guzzle-encoded), not string interpolation into
    // the path, there is no absolute-URL-override risk here at all.
    $provider->searchCardsByName('https://evil.example/x');

    Http::assertSent(function ($request) {
        return str_starts_with($request->url(), 'https://api.tcgdex.net/v2/en/cards?name=');
    });
});
```

- [ ] **Step 4: Run to see it fail**

```bash
./vendor/bin/sail artisan test --filter=TcgdexCardCatalogProviderTest
```

Expected: FAIL — `searchCardsByName` not implemented (interface violation /
missing method).

- [ ] **Step 5: Implement it in `TcgdexCardCatalogProvider`**

Add this method to the class (read the existing file first for exact
surrounding structure/imports):

```php
    public function searchCardsByName(string $query): array
    {
        $response = Http::baseUrl($this->baseUrl)->get('cards', ['name' => $query]);

        $response->throw();

        return array_map(
            fn (array $card): CardSummaryData => new CardSummaryData(
                tcgdexId: $card['id'],
                setTcgdexId: explode('-', $card['id'])[0],
                localId: $card['localId'],
                name: $card['name'],
                imageUrl: isset($card['image']) ? "{$card['image']}/high.webp" : null,
            ),
            $response->json(),
        );
    }
```

Note this deliberately uses `Http::get('cards', ['name' => $query])` (query
parameters as an array), NOT string interpolation into the path — Laravel's
HTTP client encodes array-form query parameters safely, so the SSRF
guard (`assertValidTcgdexId`) that the other three methods need for their
path-interpolated IDs does not apply here; do not add it to this method,
and do not change this method to build the URL via string interpolation.

Import `CardSummaryData` at the top of the file if not already present.

- [ ] **Step 6: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=TcgdexCardCatalogProviderTest
```

Expected: all passed (existing tests + 2 new ones).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(catalog): add searchCardsByName for admin card lookup

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 4: `CollectionService` — add a card to the collection

**Files:**
- Create: `app/Modules/Collection/Services/CollectionService.php`
- Test: `tests/Unit/Modules/Collection/CollectionServiceTest.php`

**Interfaces:**
- Consumes: `App\Modules\Catalog\Services\CatalogSyncService::syncCard()` (Phase 1).
- Produces: `CollectionService::addItem(Collection $collection, string $tcgdexCardId, array $itemData): CollectionItem`.
  `$itemData` keys: `variant` (nullable string), `condition` (string), `grade_company` (nullable string), `grade_value` (nullable string), `quantity` (int), `notes` (nullable string), `photo_path` (nullable string). Task 5's Livewire component calls this exact method with this exact shape.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Services\CollectionService;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\DataCollection;

test('addItem syncs the card into the Catalog and creates a CollectionItem', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-116')->once()->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'Special Illustration Rare', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: ['id' => 'me05-116'],
    ));
    $provider->shouldReceive('findSet')->with('me05')->once()->andReturn(new \App\Modules\Catalog\Data\SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    $service = app(CollectionService::class);
    $item = $service->addItem($collection, 'me05-116', [
        'variant' => 'holofoil',
        'condition' => 'NM',
        'grade_company' => null,
        'grade_value' => null,
        'quantity' => 1,
        'notes' => 'Pulled at a local shop',
        'photo_path' => null,
    ]);

    expect($item->collection_id)->toBe($collection->id);
    expect($item->card_tcgdex_id)->toBe('me05-116');
    expect($item->card->name)->toBe('Mega Darkrai ex');
    expect($item->condition)->toBe('NM');
    expect($item->notes)->toBe('Pulled at a local shop');
});

test('addItem defaults quantity to 1 when not provided', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->andReturn(new CardDetailData(
        tcgdexId: 'me05-007', setTcgdexId: 'me05', localId: '007', name: 'Heatran',
        rarity: 'Rare Holo', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $provider->shouldReceive('findSet')->andReturn(new \App\Modules\Catalog\Data\SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    $service = app(CollectionService::class);
    $item = $service->addItem($collection, 'me05-007', ['condition' => 'LP']);

    expect($item->quantity)->toBe(1);
});
```

- [ ] **Step 2: Run to see it fail**

```bash
./vendor/bin/sail artisan test --filter=CollectionServiceTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement `CollectionService`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Services\CatalogSyncService;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;

final class CollectionService
{
    public function __construct(private readonly CatalogSyncService $catalogSyncService) {}

    /**
     * @param array{variant?: ?string, condition: string, grade_company?: ?string, grade_value?: ?string, quantity?: int, notes?: ?string, photo_path?: ?string} $itemData
     */
    public function addItem(Collection $collection, string $tcgdexCardId, array $itemData): CollectionItem
    {
        $card = $this->catalogSyncService->syncCard($tcgdexCardId);

        return $collection->items()->create([
            'card_id' => $card->id,
            'card_tcgdex_id' => $tcgdexCardId,
            'variant' => $itemData['variant'] ?? null,
            'condition' => $itemData['condition'],
            'grade_company' => $itemData['grade_company'] ?? null,
            'grade_value' => $itemData['grade_value'] ?? null,
            'quantity' => $itemData['quantity'] ?? 1,
            'notes' => $itemData['notes'] ?? null,
            'photo_path' => $itemData['photo_path'] ?? null,
        ]);
    }
}
```

- [ ] **Step 4: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=CollectionServiceTest
```

Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(collection): add CollectionService::addItem (syncs Catalog, creates item)

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 5: Admin Livewire — search tcgdex and add a card to the collection

**Files:**
- Create: `app/Livewire/Admin/AddCollectionItem.php`
- Create: `resources/views/livewire/admin/add-collection-item.blade.php`
- Modify: `config/filesystems.php` — add a `collection-photos` disk
- Modify: `routes/web.php` — add the admin route, behind `auth`
- Test: `tests/Feature/Livewire/Admin/AddCollectionItemTest.php`

**Interfaces:**
- Consumes: `CardCatalogProvider::searchCardsByName()` (Task 3),
  `CollectionService::addItem()` (Task 4).
- Produces: the `/admin/add` page — the first screen where Carlos actually
  adds a real card.

- [ ] **Step 1: Add the `collection-photos` disk**

In `config/filesystems.php`, inside the `'disks'` array, add:

```php
        'collection-photos' => [
            'driver' => 'local',
            'root' => storage_path('app/public/collection-photos'),
            'url' => env('APP_URL') . '/storage/collection-photos',
            'visibility' => 'public',
            'throw' => false,
        ],
```

Then create the public symlink (idempotent, safe to run even if it exists):

```bash
./vendor/bin/sail artisan storage:link
```

- [ ] **Step 2: Write the failing Livewire test**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\CardSummaryData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\LaravelData\DataCollection;

test('a logged-in admin can search tcgdex and see results', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->with('Darkrai')->andReturn([
        new CardSummaryData(tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex', imageUrl: 'https://assets.tcgdex.net/en/me/me05/116/high.webp'),
    ]);
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(\App\Livewire\Admin\AddCollectionItem::class)
        ->set('search', 'Darkrai')
        ->call('runSearch')
        ->assertSet('results.0.name', 'Mega Darkrai ex');
});

test('a logged-in admin can select a result and save it to the collection', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('searchCardsByName')->andReturn([
        new CardSummaryData(tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex', imageUrl: null),
    ]);
    $provider->shouldReceive('findCard')->with('me05-116')->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'SIR', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $provider->shouldReceive('findSet')->with('me05')->andReturn(new \App\Modules\Catalog\Data\SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(\App\Livewire\Admin\AddCollectionItem::class, ['collectionId' => $collection->id])
        ->set('search', 'Darkrai')
        ->call('runSearch')
        ->call('selectCard', 'me05-116')
        ->set('condition', 'NM')
        ->set('quantity', 1)
        ->call('save')
        ->assertRedirect();

    expect(CollectionItem::where('card_tcgdex_id', 'me05-116')->exists())->toBeTrue();
});

test('an uploaded photo is stored and its path saved on the item', function () {
    Storage::fake('collection-photos');
    $user = User::factory()->create();
    $this->actingAs($user);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->andReturn(new CardDetailData(
        tcgdexId: 'me05-116', setTcgdexId: 'me05', localId: '116', name: 'Mega Darkrai ex',
        rarity: 'SIR', variants: [], officialImageUrl: null,
        prices: new DataCollection(PriceEntryData::class, []), raw: [],
    ));
    $provider->shouldReceive('findSet')->andReturn(new \App\Modules\Catalog\Data\SetSummaryData(
        tcgdexId: 'me05', name: 'Pitch Black', series: null, releasedOn: null, cardCount: null, logoUrl: null,
    ));
    $this->app->instance(CardCatalogProvider::class, $provider);

    Livewire::test(\App\Livewire\Admin\AddCollectionItem::class, ['collectionId' => $collection->id])
        ->call('selectCard', 'me05-116')
        ->set('condition', 'NM')
        ->set('photo', UploadedFile::fake()->image('card.jpg'))
        ->call('save');

    $item = CollectionItem::where('card_tcgdex_id', 'me05-116')->firstOrFail();
    expect($item->photo_path)->not->toBeNull();
    Storage::disk('collection-photos')->assertExists(basename($item->photo_path));
});
```

- [ ] **Step 3: Run to see it fail**

```bash
./vendor/bin/sail artisan test --filter=AddCollectionItemTest
```

Expected: FAIL — component class not found.

- [ ] **Step 4: Implement the Livewire component**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardSummaryData;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Services\CollectionService;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

final class AddCollectionItem extends Component
{
    use WithFileUploads;

    public ?int $collectionId = null;

    public string $search = '';

    /** @var array<int, CardSummaryData> */
    public array $results = [];

    public ?string $selectedTcgdexId = null;

    public ?string $selectedName = null;

    #[Validate('nullable|string|max:64')]
    public ?string $variant = null;

    #[Validate('required|string|max:16')]
    public string $condition = 'NM';

    #[Validate('nullable|string|max:32')]
    public ?string $gradeCompany = null;

    #[Validate('nullable|string|max:16')]
    public ?string $gradeValue = null;

    #[Validate('required|integer|min:1')]
    public int $quantity = 1;

    #[Validate('nullable|string|max:2000')]
    public ?string $notes = null;

    public $photo = null;

    public function runSearch(CardCatalogProvider $provider): void
    {
        $this->results = $this->search !== ''
            ? $provider->searchCardsByName($this->search)
            : [];
    }

    public function selectCard(string $tcgdexId): void
    {
        $this->selectedTcgdexId = $tcgdexId;
        $match = collect($this->results)->first(fn (CardSummaryData $c) => $c->tcgdexId === $tcgdexId);
        $this->selectedName = $match?->name;
    }

    public function save(CollectionService $service): mixed
    {
        $this->validate();

        if ($this->selectedTcgdexId === null) {
            $this->addError('selectedTcgdexId', 'Choose a card from the search results first.');

            return null;
        }

        $collection = $this->collectionId !== null
            ? Collection::findOrFail($this->collectionId)
            : Collection::firstOrFail();

        $photoPath = null;
        if ($this->photo) {
            $storedPath = $this->photo->store('/', 'collection-photos');
            $photoPath = basename($storedPath);
        }

        $service->addItem($collection, $this->selectedTcgdexId, [
            'variant' => $this->variant,
            'condition' => $this->condition,
            'grade_company' => $this->gradeCompany,
            'grade_value' => $this->gradeValue,
            'quantity' => $this->quantity,
            'notes' => $this->notes,
            'photo_path' => $photoPath,
        ]);

        return redirect()->route('admin.collection.index');
    }

    public function render()
    {
        return view('livewire.admin.add-collection-item');
    }
}
```

- [ ] **Step 5: Write the view**

`resources/views/livewire/admin/add-collection-item.blade.php`:

```blade
<div class="max-w-2xl mx-auto py-10 px-4">
    <div class="nw-card p-6">
        <h1 class="text-xl font-semibold mb-4" style="color: var(--ink)">Add a card</h1>

        <div class="mb-4">
            <label class="block text-sm font-medium mb-1">Search tcgdex by name</label>
            <input type="text" wire:model.live.debounce.400ms="search" wire:keyup="runSearch"
                   class="w-full border rounded px-3 py-2" placeholder="e.g. Mega Darkrai ex">
        </div>

        @if (count($results) > 0)
            <div class="grid grid-cols-3 gap-3 mb-6">
                @foreach ($results as $result)
                    <button type="button" wire:click="selectCard('{{ $result->tcgdexId }}')"
                            class="border rounded p-2 text-left text-sm {{ $selectedTcgdexId === $result->tcgdexId ? 'ring-2' : '' }}"
                            style="{{ $selectedTcgdexId === $result->tcgdexId ? 'box-shadow: 0 0 0 2px var(--ink)' : '' }}">
                        @if ($result->imageUrl)
                            <img src="{{ $result->imageUrl }}" alt="{{ $result->name }}" class="w-full rounded mb-1">
                        @endif
                        <div class="font-medium">{{ $result->name }}</div>
                        <div class="mono text-xs" style="color: var(--muted)">{{ $result->tcgdexId }}</div>
                    </button>
                @endforeach
            </div>
        @endif

        @error('selectedTcgdexId') <p class="text-red-600 text-sm mb-3">{{ $message }}</p> @enderror

        @if ($selectedTcgdexId)
            <div class="mb-4 p-3 rounded" style="background: var(--bone-2)">
                Selected: <strong>{{ $selectedName }}</strong> ({{ $selectedTcgdexId }})
            </div>
        @endif

        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium mb-1">Condition</label>
                <select wire:model="condition" class="w-full border rounded px-3 py-2">
                    <option value="NM">Near Mint</option>
                    <option value="LP">Lightly Played</option>
                    <option value="MP">Moderately Played</option>
                    <option value="HP">Heavily Played</option>
                    <option value="DMG">Damaged</option>
                </select>
                @error('condition') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Quantity</label>
                <input type="number" min="1" wire:model="quantity" class="w-full border rounded px-3 py-2">
                @error('quantity') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Grading company (optional)</label>
                <input type="text" wire:model="gradeCompany" class="w-full border rounded px-3 py-2" placeholder="PSA, BGS...">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Grade (optional)</label>
                <input type="text" wire:model="gradeValue" class="w-full border rounded px-3 py-2" placeholder="9, 10...">
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium mb-1">Notes (optional)</label>
            <textarea wire:model="notes" rows="3" class="w-full border rounded px-3 py-2"></textarea>
        </div>

        <div class="mb-6">
            <label class="block text-sm font-medium mb-1">Your own photo (optional — falls back to tcgdex's official image)</label>
            <input type="file" wire:model="photo" accept="image/*">
            @if ($photo) <img src="{{ $photo->temporaryUrl() }}" class="mt-2 w-32 rounded"> @endif
        </div>

        <button type="button" wire:click="save" class="nw-btn-primary">Save to collection</button>
    </div>
</div>
```

- [ ] **Step 6: Wire the route**

In `routes/web.php`, add inside the `auth` middleware group (create one if
Breeze didn't already, matching its own convention):

```php
Route::get('/admin/add', \App\Livewire\Admin\AddCollectionItem::class)
    ->middleware(['auth'])
    ->name('admin.collection.add');
```

Note: `admin.collection.index` (the redirect target in `save()`) does not
exist until Task 6 — that's expected; the test for `save()` only asserts
`assertRedirect()` without following it, so this is fine to leave
temporarily unresolved until Task 6 defines that route.

- [ ] **Step 7: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=AddCollectionItemTest
```

Expected: 3 passed.

- [ ] **Step 8: Verify manually in the browser**

With `APP_PORT=8090 ./vendor/bin/sail up -d` running and logged in per
Task 1, visit `http://localhost:8090/admin/add`, search for a real card
(e.g. "Darkrai"), pick one, fill the form, and save. Confirm no errors.
Report what you see.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat(admin): add search-and-add-card Livewire screen

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 6: Admin Livewire — collection items list (view, edit notes, delete)

**Files:**
- Create: `app/Livewire/Admin/CollectionItems.php`
- Create: `resources/views/livewire/admin/collection-items.blade.php`
- Modify: `routes/web.php` — add `admin.collection.index`
- Modify: `database/seeders/DatabaseSeeder.php` — ensure the seeded admin user has a default "My Collection" (Task 5's `save()` falls back to `Collection::firstOrFail()` when no `collectionId` prop is passed — this needs that default row to exist)
- Test: `tests/Feature/Livewire/Admin/CollectionItemsTest.php`

**Interfaces:**
- Consumes: `Collection::items()` (Task 2).
- Produces: the `/admin` (dashboard) page — replaces Breeze's stock
  dashboard placeholder with the actual collection table.

- [ ] **Step 1: Give the seeded admin a default collection**

Edit `database/seeders/DatabaseSeeder.php` — extend the `run()` method
(read Task 1's version of this file first, don't duplicate the `User`
creation):

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Scopes\TenantScope;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => config('tcgvault.admin_email')],
            [
                'name' => 'Carlos',
                'password' => bcrypt(config('tcgvault.admin_password')),
                'email_verified_at' => now(),
            ],
        );

        // Console context has no authenticated user, so TenantScope's
        // fail-closed default (see app/Modules/Collection/Scopes/TenantScope.php)
        // would filter this query to `where user_id is null` and never find
        // the row on a re-seed — hitting the unique [user_id, slug]
        // constraint on every run after the first. withoutGlobalScope() is
        // the explicit, auditable opt-out this exact situation exists for.
        Collection::withoutGlobalScope(TenantScope::class)->firstOrCreate(
            ['user_id' => $user->id, 'slug' => 'my-collection'],
            ['name' => 'My Collection', 'is_public' => false],
        );
    }
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Livewire\Livewire;

test('the collection index lists the authenticated users items', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->assertSee('Mega Darkrai ex')
        ->assertSee('NM');
});

test('an admin can update an items notes', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingNotes', $item->id)
        ->set('editingNotes', 'Bought at a con')
        ->call('saveNotes');

    expect($item->fresh()->notes)->toBe('Bought at a con');
});

test('an admin can delete an item', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('delete', $item->id);

    expect(CollectionItem::find($item->id))->toBeNull();
});

test('a user only sees their own items, never another users', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    CollectionItem::create([
        'collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->assertDontSee('Mega Darkrai ex');
});

test('a user cannot delete another users item by guessing its ID (IDOR)', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    $otherItem = CollectionItem::create([
        'collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('delete', $otherItem->id)
        ->assertStatus(404);

    expect(CollectionItem::find($otherItem->id))->not->toBeNull();
});

test('a user cannot edit another users item notes by guessing its ID (IDOR)', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    $otherItem = CollectionItem::create([
        'collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'notes' => 'original',
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingNotes', $otherItem->id)
        ->assertStatus(404);

    expect($otherItem->fresh()->notes)->toBe('original');
});
```

- [ ] **Step 3: Run to see it fail**

```bash
./vendor/bin/sail artisan test --filter=CollectionItemsTest
```

Expected: FAIL — component class not found.

- [ ] **Step 4: Implement the component**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Livewire\Component;

final class CollectionItems extends Component
{
    public ?int $editingItemId = null;

    public string $editingNotes = '';

    /**
     * `CollectionItem` has no `user_id` column, so it can never carry
     * `TenantScope` directly (Task 2's design). That means
     * `CollectionItem::findOrFail($itemId)` alone is an IDOR: any logged-in
     * user could pass any item ID, including another tenant's, and read or
     * mutate it. This helper closes that — it walks through `Collection`
     * (which DOES carry the scope) so an item belonging to a collection
     * that isn't the authenticated user's throws a 404 `ModelNotFoundException`
     * exactly like a real not-found, not a 403 that would confirm the ID
     * exists. Every lookup by raw item ID in this class MUST go through
     * this method — never call `CollectionItem::find()`/`findOrFail()`
     * directly.
     */
    private function ownedItemOrFail(int $itemId): CollectionItem
    {
        $item = CollectionItem::findOrFail($itemId);
        Collection::findOrFail($item->collection_id); // throws if not the caller's collection

        return $item;
    }

    public function startEditingNotes(int $itemId): void
    {
        $item = $this->ownedItemOrFail($itemId);
        $this->editingItemId = $itemId;
        $this->editingNotes = (string) $item->notes;
    }

    public function saveNotes(): void
    {
        if ($this->editingItemId === null) {
            return;
        }

        $this->ownedItemOrFail($this->editingItemId)->update(['notes' => $this->editingNotes]);
        $this->editingItemId = null;
    }

    public function delete(int $itemId): void
    {
        $this->ownedItemOrFail($itemId)->delete();
    }

    public function render()
    {
        // Reached only through Collection::items(), which is scoped via
        // Collection's TenantScope — never query CollectionItem::query()
        // directly here, that would bypass the tenant filter entirely.
        $items = Collection::with(['items.card.set'])
            ->get()
            ->flatMap(fn (Collection $c) => $c->items);

        return view('livewire.admin.collection-items', ['items' => $items]);
    }
}
```

- [ ] **Step 5: Write the view**

`resources/views/livewire/admin/collection-items.blade.php`:

```blade
<div class="max-w-4xl mx-auto py-10 px-4">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-semibold" style="color: var(--ink)">My Collection</h1>
        <a href="{{ route('admin.collection.add') }}" class="nw-btn-primary">+ Add card</a>
    </div>

    <div class="nw-card overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="nw-topbar text-left">
                    <th class="p-3">Card</th>
                    <th class="p-3">Set</th>
                    <th class="p-3">Condition</th>
                    <th class="p-3">Qty</th>
                    <th class="p-3">Notes</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr class="border-t" style="border-color: var(--hair)">
                        <td class="p-3 font-medium">{{ $item->card->name }}</td>
                        <td class="p-3" style="color: var(--muted)">{{ $item->card->set->name }}</td>
                        <td class="p-3 mono">{{ $item->condition }}</td>
                        <td class="p-3 mono">{{ $item->quantity }}</td>
                        <td class="p-3">
                            @if ($editingItemId === $item->id)
                                <input type="text" wire:model="editingNotes" wire:keydown.enter="saveNotes" class="border rounded px-2 py-1 w-full">
                            @else
                                <span wire:click="startEditingNotes({{ $item->id }})" class="cursor-pointer">{{ $item->notes ?: '—' }}</span>
                            @endif
                        </td>
                        <td class="p-3 text-right">
                            <button wire:click="delete({{ $item->id }})" wire:confirm="Remove this card from your collection?" class="text-red-600 text-xs">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-6 text-center" style="color: var(--muted)">No cards yet — add your first one.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
```

- [ ] **Step 6: Wire the route**

In `routes/web.php`:

```php
Route::get('/admin', \App\Livewire\Admin\CollectionItems::class)
    ->middleware(['auth'])
    ->name('admin.collection.index');
```

Breeze's installer generates a `/dashboard` route in `routes/web.php` as
the post-login landing page. Rather than hunting down and changing
whatever internal redirect constant Breeze wired for that, add one
deterministic line to `routes/web.php`, after Breeze's own dashboard route
definition, so `/dashboard` simply forwards to the real landing page:

```php
Route::redirect('/dashboard', '/admin');
```

This keeps Breeze's scaffolding untouched and gives a single, obvious
place (this one line) to change later if the redirect target ever moves.

- [ ] **Step 7: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=CollectionItemsTest
```

Expected: 6 passed (the original 4 plus the two IDOR regression tests). Then run the full suite:

```bash
./vendor/bin/sail artisan test
```

Expected: everything passes, no regressions from Phase 1 or earlier Phase
2 tasks.

- [ ] **Step 8: Verify manually in the browser, end to end**

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

Then, with `APP_PORT=8090 ./vendor/bin/sail up -d` running: log in at
`http://localhost:8090/login`, land on `/admin` (empty state), click
"+ Add card", search for a real card, save it, and confirm it now appears
in the list with correct name/set/condition. Edit its notes inline. Delete
it and confirm it disappears. This is the first true end-to-end proof of
Phase 2 — describe or screenshot what you see at each step.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat(admin): add collection items list with inline notes edit and delete

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 7: Admin Livewire — full item edit (condition, quantity, variant, grade)

> Added after Task 6 shipped, at Carlos's explicit request ("si deberia poder
> editar los demas, por que si me equivoque") — Task 6 only let notes be
> edited inline; everything else set at add-time (condition, quantity,
> variant, grade company/value) was otherwise permanent. This task closes
> that gap using the exact same `ownedItemOrFail()` IDOR guard Task 6
> already built and proved with regression tests — no new tenant-scope
> pattern, just reusing the load-bearing one that exists.

**Files:**
- Modify: `app/Livewire/Admin/CollectionItems.php`
- Modify: `resources/views/livewire/admin/collection-items.blade.php`
- Test: `tests/Feature/Livewire/Admin/CollectionItemsTest.php`

**Interfaces:**
- Consumes: `ownedItemOrFail()` (Task 6, already in `CollectionItems.php` —
  do not duplicate or re-derive it; every lookup below must go through it).
- `collection_items` columns available to edit (from
  `database/migrations/2026_09_15_000002_create_collection_items_table.php`):
  `variant` (nullable string), `condition` (required string), `grade_company`
  (nullable string), `grade_value` (nullable string), `quantity` (unsigned
  int, default 1). `card_id`/`card_tcgdex_id`/`photo_path` are NOT editable
  here — changing which card an item points to is a delete-and-re-add, not
  an edit; photo replacement is out of scope for this task.

- [ ] **Step 1: Write the failing test**

```php
test('an admin can edit an items full details', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $item->id)
        ->set('editingCondition', 'LP')
        ->set('editingQuantity', 3)
        ->set('editingVariant', 'Reverse Holo')
        ->set('editingGradeCompany', 'PSA')
        ->set('editingGradeValue', '9')
        ->call('saveItem');

    $fresh = $item->fresh();
    expect($fresh->condition)->toBe('LP');
    expect($fresh->quantity)->toBe(3);
    expect($fresh->variant)->toBe('Reverse Holo');
    expect($fresh->grade_company)->toBe('PSA');
    expect($fresh->grade_value)->toBe('9');
});

test('editing an items condition rejects a value outside the allowed set', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $item->id)
        ->set('editingCondition', 'NOT_A_REAL_CONDITION')
        ->call('saveItem')
        ->assertHasErrors('editingCondition');

    expect($item->fresh()->condition)->toBe('NM');
});

test('a user cannot edit another users item full details by guessing its ID (IDOR)', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $otherCollection = Collection::factory()->for($otherUser)->create(['name' => 'Not mine', 'slug' => 'not-mine']);
    $otherItem = CollectionItem::create([
        'collection_id' => $otherCollection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $otherItem->id)
        ->assertStatus(404);

    expect($otherItem->fresh()->condition)->toBe('NM');
});
```

- [ ] **Step 2: Run to see it fail**

```bash
./vendor/bin/sail artisan test --filter=CollectionItemsTest
```

Expected: FAIL — `startEditingItem`/`saveItem` methods don't exist yet.

- [ ] **Step 3: Add the editing state and methods to `CollectionItems.php`**

Add alongside the existing `editingItemId`/`editingNotes` properties (do not
remove or rename those — the notes-only inline edit from Task 6 stays):

```php
    public ?int $editingFullItemId = null;

    #[Validate('required|in:NM,LP,MP,HP,DMG')]
    public string $editingCondition = 'NM';

    #[Validate('required|integer|min:1')]
    public int $editingQuantity = 1;

    #[Validate('nullable|string|max:64')]
    public ?string $editingVariant = null;

    #[Validate('nullable|string|max:32')]
    public ?string $editingGradeCompany = null;

    #[Validate('nullable|string|max:16')]
    public ?string $editingGradeValue = null;

    public function startEditingItem(int $itemId): void
    {
        $item = $this->ownedItemOrFail($itemId);
        $this->editingFullItemId = $itemId;
        $this->editingCondition = $item->condition;
        $this->editingQuantity = $item->quantity;
        $this->editingVariant = $item->variant;
        $this->editingGradeCompany = $item->grade_company;
        $this->editingGradeValue = $item->grade_value;
    }

    public function cancelEditingItem(): void
    {
        $this->editingFullItemId = null;
        $this->resetValidation();
    }

    public function saveItem(): void
    {
        if ($this->editingFullItemId === null) {
            return;
        }

        $this->validate();

        $this->ownedItemOrFail($this->editingFullItemId)->update([
            'condition' => $this->editingCondition,
            'quantity' => $this->editingQuantity,
            'variant' => $this->editingVariant,
            'grade_company' => $this->editingGradeCompany,
            'grade_value' => $this->editingGradeValue,
        ]);
        $this->editingFullItemId = null;
    }
```

Add the `use Livewire\Attributes\Validate;` import.

Note: `startEditingItem()` must go through `ownedItemOrFail()` exactly like
`startEditingNotes()` does — this is the same IDOR guard, reused, not a new
one. The `editingCondition` validation (`in:NM,LP,MP,HP,DMG`) also closes a
minor gap Task 5's review flagged on the add-card form (no enum constraint
on condition) — apply the same fix here since this task touches the same
field.

- [ ] **Step 4: Add an edit control to the view**

In `resources/views/livewire/admin/collection-items.blade.php`, add an
"Edit" button/link next to the existing "Delete" button in the actions
column, and a small inline edit panel (shown when
`$editingFullItemId === $item->id`) with `wire:model` bindings for
`editingCondition` (a `<select>` matching the add-card form's 5 options),
`editingQuantity`, `editingVariant`, `editingGradeCompany`,
`editingGradeValue`, a "Save" button (`wire:click="saveItem"`) and a
"Cancel" button (`wire:click="cancelEditingItem"`). Follow the same
`.nw-card`/token-variable styling as the rest of this view and Task 5's
add-card form — no new colors/fonts outside `design.md`.

- [ ] **Step 5: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=CollectionItemsTest
```

Expected: 9 passed (Task 6's original 6 plus this task's 3).

```bash
./vendor/bin/sail artisan test
```

Expected: everything passes, no regressions.

- [ ] **Step 6: Verify manually in the browser**

With Sail running on port 8090, log in, go to `/admin`, click "Edit" on an
existing item, change its condition/quantity/grade, save, and confirm the
table reflects the change. Try saving an invalid condition (if reachable
through the UI, e.g. by temporarily editing the `<select>` options in
devtools) and confirm the validation error shows instead of persisting bad
data.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Admin/CollectionItems.php resources/views/livewire/admin/collection-items.blade.php tests/Feature/Livewire/Admin/CollectionItemsTest.php
git commit -m "feat(admin): allow editing an item's full details, not just notes

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 8: Edit as an animated modal + real variant options

> Added at Carlos's explicit request: the inline edit panel felt bolted-on,
> and "variant" as a bare text input was confusing with no hint of what to
> type. Confirmed against the live tcgdex/tcgplayer API (`api.tcgdex.net`)
> which variant keys actually occur: `normal`, `holofoil`, `reverse-holofoil`
> — those are the values to offer, not invented ones.

**Files:**
- Modify: `app/Livewire/Admin/CollectionItems.php`
- Modify: `resources/views/livewire/admin/collection-items.blade.php`
- Test: `tests/Feature/Livewire/Admin/CollectionItemsTest.php`

**Interfaces:**
- Consumes: `ownedItemOrFail()`, `startEditingItem()`/`saveItem()`/
  `cancelEditingItem()` (Task 7 — reuse the state machine, don't rewrite
  it; only the PRESENTATION moves from an inline table row to a modal, and
  `editingVariant` moves from an `<input type="text">` to a `<select>`).
- No motion library is installed in this project (`package.json` has no
  `framer-motion`/`gsap`/etc.) — animation must be plain CSS
  (`transition`/`@keyframes`), matching `design.md`'s existing "Motion
  stance" section (short eased transitions, `prefers-reduced-motion`
  fallback to instant/no animation).

- [ ] **Step 1: Change `editingVariant` from free text to a constrained select**

In `app/Livewire/Admin/CollectionItems.php`, change the `editingVariant`
property's validation from `nullable|string|max:64` to
`nullable|in:normal,holofoil,reverse-holofoil` (an out-of-list value,
including the empty string, must be rejected the same way `editingCondition`
already rejects an invalid value — check how that field's `#[Validate]`
attribute and `saveItem()`'s `$this->validate()` call work today and mirror
that exactly, don't invent a different validation flow for this one field).

In the view, replace the `<input type="text" wire:model="editingVariant">`
with:
```blade
<select wire:model="editingVariant" class="w-full border rounded px-2 py-1">
    <option value="">— not specified —</option>
    <option value="normal">Normal</option>
    <option value="holofoil">Holofoil</option>
    <option value="reverse-holofoil">Reverse Holofoil</option>
</select>
```
Do the same replacement on the ADD form (`app/Livewire/Admin/AddCollectionItem.php`
+ `resources/views/livewire/admin/add-collection-item.blade.php`) for
consistency — both forms edit the same column, they should offer the same
options. `AddCollectionItem::$variant`'s validation moves from
`nullable|string|max:64` to the same `nullable|in:normal,holofoil,reverse-holofoil`.

- [ ] **Step 2: Write the failing tests**

Add to `tests/Feature/Livewire/Admin/CollectionItemsTest.php`:
```php
test('editing an items variant only accepts the known values', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1,
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $item->id)
        ->set('editingVariant', 'reverse-holofoil')
        ->call('saveItem');

    expect($item->fresh()->variant)->toBe('reverse-holofoil');
});

test('an invalid variant value is rejected', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $item = CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'variant' => 'normal',
    ]);

    Livewire::test(\App\Livewire\Admin\CollectionItems::class)
        ->call('startEditingItem', $item->id)
        ->set('editingVariant', 'first-edition-ultra-rainbow-secret')
        ->call('saveItem')
        ->assertHasErrors('editingVariant');

    expect($item->fresh()->variant)->toBe('normal');
});
```

- [ ] **Step 3: Turn the edit panel into a modal**

Replace the inline `@if ($editingFullItemId === $item->id)` table row
(currently rendered directly under the item's row) with a modal overlay,
rendered ONCE outside the `@forelse` loop (not once per row — a modal is a
single overlay, its content just needs to know which item is being edited).
Structure:

```blade
@if ($editingFullItemId !== null)
    <div class="fixed inset-0 z-40 flex items-center justify-center p-4"
         style="background: rgba(20,20,18,.5)"
         wire:click.self="cancelEditingItem">
        <div class="nw-card w-full max-w-md p-5" style="animation: modal-in 180ms var(--ease)">
            <h2 class="text-lg font-semibold mb-4" style="color: var(--ink)">Edit item</h2>
            {{-- move the existing grid of Condition/Quantity/Variant/Grading fields here verbatim,
                 same wire:model bindings, same @error blocks --}}
            <div class="mt-4 flex gap-2">
                <button wire:click="saveItem" class="nw-btn-primary text-sm px-4 py-2">Save</button>
                <button wire:click="cancelEditingItem" class="text-sm px-4 py-2" style="color: var(--muted)">Cancel</button>
            </div>
        </div>
    </div>
@endif
```

Add to `resources/css/app.css` (not inline — this project keeps animation
keyframes in the stylesheet, check how `design.md`'s "Motion stance"
section describes the existing card entrance animation and follow the same
convention):
```css
@keyframes modal-in {
    from { opacity: 0; transform: scale(.96) translateY(8px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
    .nw-card[style*="modal-in"] { animation: none !important; }
}
```
(If a cleaner selector than the attribute-contains hack is available given
how `.nw-card` is used elsewhere, use that instead — the requirement is
"no animation when the user has reduced motion enabled," not this exact
selector.)

The `Edit` button (`wire:click="startEditingItem({{ $item->id }})"`) and
the table row stay exactly as they are — only what renders when
`editingFullItemId` is set changes, from an inline row to this overlay.
Pressing `Escape` should also close the modal if that's a quick addition
(a `wire:keydown.escape.window="cancelEditingItem"` on the outer div is
enough) — nice-to-have, not blocking if it turns out to need more than a
few minutes.

- [ ] **Step 4: Run the tests**

```bash
./vendor/bin/sail artisan test --filter=CollectionItemsTest
./vendor/bin/sail artisan test --filter=AddCollectionItemTest
```
Expected: all passing, no regressions. Then the full suite:
```bash
./vendor/bin/sail artisan test
```

- [ ] **Step 5: Rebuild frontend assets**

```bash
./vendor/bin/sail npm run build
```
This project has no Vite dev-server/watcher running in this environment —
a stale `public/build` was the direct cause of a real bug earlier this
session (Tailwind's `grid-cols-3` silently missing from the compiled CSS
because the last build predated the blade file that used it). Always
rebuild after changing any Blade/CSS file's classes, and confirm the new
build hash differs from the old one before verifying manually.

- [ ] **Step 6: Verify manually in the browser**

With Sail running on port 8090, log in, go to `/admin`, click Edit on an
existing item. Confirm: the modal fades/scales in (not an instant snap),
clicking outside the modal or Cancel closes it, the Variant field is now a
dropdown with the 3 real options, saving persists correctly, and the
underlying table doesn't visually break while the modal is open (no
double-scrollbars, no layout shift). Describe what you see.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Admin/CollectionItems.php app/Livewire/Admin/AddCollectionItem.php resources/views/livewire/admin/collection-items.blade.php resources/views/livewire/admin/add-collection-item.blade.php resources/css/app.css tests/Feature/Livewire/Admin/CollectionItemsTest.php
git commit -m "feat(admin): turn item edit into an animated modal, constrain variant to real values

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 9: Adding an identical printing increments quantity instead of duplicating the row

> Added at Carlos's explicit request, and matches a non-blocking finding the
> final whole-branch review already surfaced: `collection_items` has no
> uniqueness constraint on `(collection_id, card_id, variant, condition,
> grade_company, grade_value)`, so adding the same printing twice today
> creates two separate rows instead of bumping `quantity` on the existing
> one. This task makes `CollectionService::addItem()` an upsert.

**Files:**
- Modify: `app/Modules/Collection/Services/CollectionService.php`
- Test: `tests/Unit/Modules/Collection/CollectionServiceTest.php`

**Interfaces:**
- No change to `addItem()`'s public signature
  (`addItem(Collection $collection, string $tcgdexCardId, array $itemData): CollectionItem`)
  or its `$itemData` shape — only its internal behavior changes. Callers
  (`AddCollectionItem::save()`, Task 5) need no changes.

- [ ] **Step 1: Write the failing test**

```php
test('adding an identical printing again increments quantity instead of creating a new row', function () {
    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    $catalogSync = Mockery::mock(CatalogSyncService::class);
    $catalogSync->shouldReceive('syncCard')->twice()->with('me05-116')->andReturn($card);
    $service = new CollectionService($catalogSync);

    $first = $service->addItem($collection, 'me05-116', ['condition' => 'NM', 'quantity' => 1]);
    $second = $service->addItem($collection, 'me05-116', ['condition' => 'NM', 'quantity' => 2]);

    expect($second->id)->toBe($first->id);
    expect($first->fresh()->quantity)->toBe(3);
    expect(CollectionItem::where('collection_id', $collection->id)->count())->toBe(1);
});

test('a different variant or condition of the same card creates a separate row, not a merge', function () {
    $user = User::factory()->create();
    $collection = Collection::factory()->for($user)->create(['name' => 'Main', 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    $catalogSync = Mockery::mock(CatalogSyncService::class);
    $catalogSync->shouldReceive('syncCard')->twice()->with('me05-116')->andReturn($card);
    $service = new CollectionService($catalogSync);

    $service->addItem($collection, 'me05-116', ['condition' => 'NM', 'quantity' => 1]);
    $service->addItem($collection, 'me05-116', ['condition' => 'LP', 'quantity' => 1]); // different condition

    expect(CollectionItem::where('collection_id', $collection->id)->count())->toBe(2);
});
```
(Check the existing `CollectionServiceTest.php` for how it currently mocks
`CatalogSyncService` and imports its test doubles — match that exact
pattern rather than introducing a different mocking style in the same
file.)

- [ ] **Step 2: Run to see it fail**

```bash
./vendor/bin/sail artisan test --filter=CollectionServiceTest
```
Expected: FAIL on the "increments quantity" test (creates 2 rows, not 1).

- [ ] **Step 3: Implement the upsert**

In `app/Modules/Collection/Services/CollectionService.php`, before creating
a new row, look for an existing one matching every identity column
(`card_id`, `variant`, `condition`, `grade_company`, `grade_value` — NOT
`notes`/`photo_path`, those aren't part of the printing's identity). Treat
`null` and `null` as equal (a card added with no variant/grade specified,
added again with no variant/grade specified, is the same printing) — Eloquent's
`where('column', null)` does NOT match SQL `IS NULL` correctly for a
plain `where()`, so use `whereNull()` for any `$itemData` field that's
null rather than a bare `where('grade_value', null)`. If a match is found,
increment its `quantity` by the incoming `quantity` (default 1) and leave
`notes`/`photo_path` untouched (don't overwrite existing notes with null,
don't overwrite an existing photo). If no match, create a new row exactly
as today.

- [ ] **Step 4: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=CollectionServiceTest
```
Expected: passing. Then the full suite — this touches a Task 4 file
consumed by Task 5's `AddCollectionItem::save()`, so re-run the Livewire
tests too:

```bash
./vendor/bin/sail artisan test
```
Expected: all passing, no regressions (check `AddCollectionItemTest`
specifically — if any existing test there asserts "a second identical add
creates a second row," that assertion is now intentionally wrong and needs
updating to match the new behavior, not treated as a regression to
preserve).

- [ ] **Step 5: Verify manually in the browser**

With Sail running on port 8090: add a card via `/admin/add`, then add the
exact same card again with the same condition (and same variant, once Task
8 lands). Confirm `/admin` shows ONE row with quantity 2, not two rows.
Then add it again with a DIFFERENT condition and confirm that DOES create
a second row.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Collection/Services/CollectionService.php tests/Unit/Modules/Collection/CollectionServiceTest.php
git commit -m "feat(collection): merge quantity into an existing row instead of duplicating identical printings

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

## What Phase 2 deliberately does NOT include

- No public gallery — that's Phase 3.
- No daily price-snapshot scheduled job — that's Phase 4 (this phase's
  `CollectionService::addItem()` does sync current pricing at add-time via
  `CatalogSyncService`, but nothing here re-syncs prices on a schedule).
- No Sets/Movimientos admin screens (those were public-gallery mockups from
  the brainstorm, not admin screens) — Phase 3.
- No deploy to polaris2 — Phase 5.
- No English translation of UI copy — per `design.md`, that's a single pass
  done once the whole UI is built, not incrementally per phase.
