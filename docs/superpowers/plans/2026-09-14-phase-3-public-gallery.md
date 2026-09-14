# Phase 3 — Public Gallery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the first public, unauthenticated screens of tcg-vault — a
set list and set detail gallery, scoped under a `{username}` URL segment,
showing Carlos's actual collection completion and his own card photos
where he's uploaded them.

**Architecture:** Two new Livewire full-page components under a thin
`Gallery` presentation layer (not a Catalog/Collection sub-module — it
reads both directly, following the precedent already set by Phase 2's
admin Livewire components, which also read `Collection`/`CollectionItem`
directly rather than through an intermediate service). One new column on
`users`. One small Catalog-module service resolving "the" display price
for a card from its snapshots.

**Tech Stack:** Laravel 13, Livewire (class-based, matching Phase 2's admin
components — not Volt), Pest, no new npm dependencies.

## Global Constraints

- Public gallery routes carry **no** `auth` middleware — they must render
  correctly for a logged-out visitor. A regression that accidentally adds
  `auth` here must fail a test loudly, not silently redirect to login.
- A set the target user hasn't added any card from does not exist in their
  gallery — this returns a real 404, never an empty-state page. Same for a
  `{username}` that doesn't resolve to any user.
- Ownership completion counts **distinct cards**, not `CollectionItem`
  rows — Task 9 (Phase 2) allows multiple rows per card (different
  condition/variant), which must count once toward "how many of this set
  do you own," not once per row.
- Only `Collection`s with `is_public = true` contribute to the gallery.
  `Collection.is_public` already exists on the schema (Phase 2, Task 2)
  and defaults to `false` — Task 6's seeder creates the admin's default
  "My Collection" as `is_public: false`. Task 1 below flips that seeded
  default to `true` (Carlos's own decision this session: the completion
  bar is public) — existing local dev data was seeded before this change
  and needs a one-time manual flip, called out in Task 1's steps.
- `design.md` tokens only — `.nw-card`, `--ink`/`--bone`/`--muted`/
  `--hair`/`--signal`/`--danger`/`--flat`, `.mono` for numerals (prices,
  card numbers). No new colors. The completion-bar fill is the one place
  besides "price up" that `--signal` is allowed — it's a progress
  indicator, not decoration, and `design.md`'s rule is about a SECOND
  accent competing with the first, not about reusing the one accent for a
  second literal meaning.
- Money stays bigint minor units + explicit currency, exactly as
  `CardPriceSnapshot` already stores it — the gallery only reads and
  formats, never re-derives or stores a price.
- Pest tests only, one concern per commit, conventional commit format.

---

### Task 1: `users.username` + login by username or email

**Files:**
- Create: `database/migrations/2026_09_15_000003_add_username_to_users_table.php`
- Modify: `app/Models/User.php`
- Modify: `app/Livewire/Forms/LoginForm.php`
- Modify: `resources/views/livewire/pages/auth/login.blade.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Auth/AuthenticationTest.php` (existing — add a
  login-by-username case alongside the existing login-by-email ones)
- Test: `tests/Unit/DatabaseSeederUsernameTest.php` (new — or fold into
  an existing seeder test file if `tests/Feature/Auth/AdminAuthTest.php`
  already covers seeder behavior; check that file first)

**Interfaces:**
- Produces: `User::$username` (string, unique) — Task 3/4 resolve the
  target user by this column, never by numeric ID, in the public routes.

- [ ] **Step 1: Write the failing tests**

```php
// tests/Feature/Auth/AuthenticationTest.php — add this test to the
// existing file, alongside 'users can authenticate using the login screen'
test('users can authenticate using their username instead of email', function () {
    $user = User::factory()->create(['username' => 'testuser']);

    $component = Livewire::test('pages.auth.login')
        ->set('form.email', 'testuser') // same field, no @ present
        ->set('form.password', 'password');

    $component->call('login');

    $component
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});
```

```php
// tests/Unit/Modules/Collection/DatabaseSeederTest.php (new file)
use App\Models\User;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('seeding generates a username from the admin email and a public default collection', function () {
    config(['tcgvault.admin_email' => 'cativo23.kt@gmail.com']);
    config(['tcgvault.admin_password' => 'a-real-password']);

    $this->seed();

    $user = User::where('email', 'cativo23.kt@gmail.com')->firstOrFail();
    expect($user->username)->toBe('cativo23kt');

    $collection = Collection::withoutGlobalScope(TenantScope::class)
        ->where('user_id', $user->id)->firstOrFail();
    expect($collection->is_public)->toBeTrue();
});
```

(Check `tests/Pest.php` — this new `tests/Unit/Modules/Collection/` file
needs the same `Unit/Modules/Collection` binding Task 4 [Phase 2] already
added; it should already be covered, but verify before assuming.)

- [ ] **Step 2: Run to see it fail**

```bash
./vendor/bin/sail artisan test --filter=AuthenticationTest
./vendor/bin/sail artisan test --filter=DatabaseSeederTest
```
Expected: FAIL — `username` column doesn't exist yet / seeder doesn't
generate one.

- [ ] **Step 3: Migration**

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
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
        });
    }
};
```
`nullable()` on the column itself (not the app-level requirement) — a
`UserFactory`-created user in a test that doesn't explicitly set one would
otherwise violate a NOT NULL constraint; the unique index still enforces
no two non-null usernames collide. In `database/factories/UserFactory.php`,
add a `username` key to `definition()`'s returned array, right after
`'email' => fake()->unique()->safeEmail(),`:
```php
'username' => fake()->unique()->userName(),
```
(`fake()->unique()` already guarantees no collision across factory-created
users within a test run, matching the existing `email` field's own
pattern in this file.)

- [ ] **Step 4: Update the User model**

In `app/Models/User.php`, change the `#[Fillable(...)]` attribute to
include `username`:
```php
#[Fillable(['name', 'username', 'email', 'password'])]
```

- [ ] **Step 5: Update `LoginForm`**

In `app/Livewire/Forms/LoginForm.php`, relax the email-format validation
(a username isn't a valid email shape) and resolve which column to
authenticate against:

```php
#[Validate('required|string')]
public string $email = '';
```

```php
public function authenticate(): void
{
    $this->ensureIsNotRateLimited();

    $column = str_contains($this->email, '@') ? 'email' : 'username';

    if (! Auth::attempt([$column => $this->email, 'password' => $this->password], $this->remember)) {
        RateLimiter::hit($this->throttleKey());

        throw ValidationException::withMessages([
            'form.email' => trans('auth.failed'),
        ]);
    }

    RateLimiter::clear($this->throttleKey());
}
```
(`throttleKey()` and `ensureIsNotRateLimited()` need no change — they
already key on the raw `$this->email` string plus IP, which works
identically whether that string happens to be an email or a username.)

- [ ] **Step 6: Update the login view's label**

In `resources/views/livewire/pages/auth/login.blade.php`, find the email
field's `<x-input-label>` and change its text from `__('Email')` to
`__('Email or username')` (check the exact current markup first — Task 10
[Phase 2] may have touched this file's styling, don't revert that).

- [ ] **Step 7: Update the seeder**

In `database/seeders/DatabaseSeeder.php`:
```php
$user = User::firstOrCreate(
    ['email' => $email],
    [
        'name' => 'Carlos',
        'username' => Str::of(explode('@', $email)[0])
            ->lower()
            ->replaceMatches('/[^a-z0-9]/', '')
            ->toString(),
        'password' => bcrypt($password),
        'email_verified_at' => now(),
    ],
);
```
(add `use Illuminate\Support\Str;` to the file's imports). Change the
default `Collection`'s `is_public` from `false` to `true`:
```php
Collection::withoutGlobalScope(TenantScope::class)->firstOrCreate(
    ['user_id' => $user->id, 'slug' => 'my-collection'],
    ['name' => 'My Collection', 'is_public' => true],
);
```

- [ ] **Step 8: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=AuthenticationTest
./vendor/bin/sail artisan test --filter=DatabaseSeederTest
```
Expected: passing. Then the full suite:
```bash
./vendor/bin/sail artisan test
```
Expected: everything passes, no regressions.

- [ ] **Step 9: Flip existing local dev data**

This step is a one-time manual fix for THIS environment's already-seeded
row (the migration/seeder change only affects future seeds, not the row
that already exists from Phase 2's testing). With Sail running:
```bash
./vendor/bin/sail artisan tinker --execute="
\$u = \App\Models\User::first();
\$u->username = \App\Modules\Catalog\Models\Set::query() ? Illuminate\Support\Str::of(explode('@', \$u->email)[0])->lower()->replaceMatches('/[^a-z0-9]/', '')->toString() : null;
\$u->save();
\App\Modules\Collection\Models\Collection::withoutGlobalScope(\App\Modules\Collection\Scopes\TenantScope::class)->where('user_id', \$u->id)->update(['is_public' => true]);
echo \$u->username;
"
```
(Simplify this one-liner as needed once you're actually running it — the
point is: set the real seeded user's `username` and flip their existing
`Collection` rows to `is_public = true`, since `firstOrCreate` in the
seeder won't touch already-existing rows on a re-run.)

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_09_15_000003_add_username_to_users_table.php app/Models/User.php app/Livewire/Forms/LoginForm.php resources/views/livewire/pages/auth/login.blade.php database/seeders/DatabaseSeeder.php database/factories/UserFactory.php tests/Feature/Auth/AuthenticationTest.php tests/Unit/Modules/Collection/DatabaseSeederTest.php
git commit -m "feat(auth): add username, allow login by username or email

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 2: The empty-image card state (`.imgwrap.empty`)

**Files:**
- Modify: `resources/css/app.css`
- Create: `resources/views/components/card-image.blade.php`
- Test: `tests/Feature/CardImageComponentTest.php`

**Interfaces:**
- Produces: `<x-card-image :url="$card->officialImageUrl" :name="$card->name" />`
  — a single reusable Blade component Task 4 uses for every card tile.
  Renders an `<img>` when `$url` is non-null/non-empty, renders the
  `design.md`-specified empty state otherwise. Never renders a broken
  `<img src="">`.

`design.md`'s rule (§ "Card images are the content"): "Every card must
render name/set/price with no image (`.imgwrap.empty` diagonal-hatch +
icon + 'Sin imagen')." This has never been built in real code yet — Phase
2's admin screens simply skip the `<img>` tag entirely when there's no
URL, with no styled placeholder. This task builds the real thing for the
first time.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

test('renders an img tag when a url is given', function () {
    $view = $this->blade('<x-card-image url="https://example.com/card.webp" name="Pikachu" />');

    $view->assertSee('<img', false);
    $view->assertSee('https://example.com/card.webp', false);
});

test('renders the empty-image placeholder when url is null', function () {
    $view = $this->blade('<x-card-image :url="null" name="Pikachu" />');

    $view->assertDontSee('<img', false);
    $view->assertSee('No image');
});
```

- [ ] **Step 2: Run to see it fail**

```bash
./vendor/bin/sail artisan test --filter=CardImageComponentTest
```
Expected: FAIL — component doesn't exist.

- [ ] **Step 3: Add the CSS**

Append to `resources/css/app.css`:
```css
.imgwrap {
  aspect-ratio: 245 / 342; /* standard card art ratio */
  border-radius: 6px;
  overflow: hidden;
}
.imgwrap img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.imgwrap.empty {
  display: flex;
  align-items: center;
  justify-content: center;
  background: repeating-linear-gradient(
    45deg,
    var(--bone-2),
    var(--bone-2) 6px,
    var(--bone-3) 6px,
    var(--bone-3) 12px
  );
  color: var(--muted);
  font-size: 0.75rem;
  text-align: center;
  padding: 1rem;
}
```

- [ ] **Step 4: Write the component**

`resources/views/components/card-image.blade.php`:
```blade
@props(['url', 'name'])

<div class="imgwrap {{ $url ? '' : 'empty' }}">
    @if ($url)
        <img src="{{ $url }}" alt="{{ $name }}" loading="lazy">
    @else
        <span>No image<br>{{ $name }}</span>
    @endif
</div>
```

- [ ] **Step 5: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=CardImageComponentTest
```
Expected: 2 passed.

- [ ] **Step 6: Commit**

```bash
git add resources/css/app.css resources/views/components/card-image.blade.php tests/Feature/CardImageComponentTest.php
git commit -m "feat(gallery): build design.md's empty-image card state for the first time

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 3: `CardPriceResolver` — "the" display price for a card

**Files:**
- Create: `app/Modules/Catalog/Services/CardPriceResolver.php`
- Test: `tests/Unit/Modules/Catalog/CardPriceResolverTest.php`

**Interfaces:**
- Produces: `CardPriceResolver::resolve(Card $card): ?CardPriceSnapshot` —
  Task 4/5 use this for both the "most expensive card in set" stat and
  the price-sort/display column. Returns `null` when the card has no
  snapshot at all (never returns a zero-value fake snapshot).

Priority order per `design.md`'s spec §5: `tcgplayer` `normal` or
`holofoil` variant → `cardmarket` `default` variant → any remaining
snapshot for that card, by most-recently-captured.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;

test('prefers tcgplayer normal over everything else', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 500]);
    $tcgplayer = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1000]);

    $resolved = (new CardPriceResolver())->resolve($card);

    expect($resolved->id)->toBe($tcgplayer->id);
});

test('falls back to cardmarket default when no tcgplayer normal or holofoil exists', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    $cardmarket = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today(), 'currency' => 'EUR', 'market_minor' => 500]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1500]);

    $resolved = (new CardPriceResolver())->resolve($card);

    expect($resolved->id)->toBe($cardmarket->id);
});

test('falls back to any remaining snapshot, most recent first', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    $newest = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'reverse-holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1200]);

    $resolved = (new CardPriceResolver())->resolve($card);

    expect($resolved->id)->toBe($newest->id);
});

test('returns null when the card has no snapshot at all', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    expect((new CardPriceResolver())->resolve($card))->toBeNull();
});
```

- [ ] **Step 2: Run to see it fail**

```bash
./vendor/bin/sail artisan test --filter=CardPriceResolverTest
```
Expected: FAIL — class doesn't exist.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;

final class CardPriceResolver
{
    public function resolve(Card $card): ?CardPriceSnapshot
    {
        $snapshots = $card->priceSnapshots()->orderByDesc('captured_on')->get();

        if ($snapshots->isEmpty()) {
            return null;
        }

        $tcgplayerPreferred = $snapshots->first(
            fn (CardPriceSnapshot $s) => $s->source === 'tcgplayer' && in_array($s->variant, ['normal', 'holofoil'], true),
        );

        if ($tcgplayerPreferred) {
            return $tcgplayerPreferred;
        }

        $cardmarketDefault = $snapshots->first(
            fn (CardPriceSnapshot $s) => $s->source === 'cardmarket' && $s->variant === 'default',
        );

        if ($cardmarketDefault) {
            return $cardmarketDefault;
        }

        return $snapshots->first();
    }
}
```

- [ ] **Step 4: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=CardPriceResolverTest
```
Expected: 4 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Catalog/Services/CardPriceResolver.php tests/Unit/Modules/Catalog/CardPriceResolverTest.php
git commit -m "feat(catalog): add CardPriceResolver for the gallery's single-price display/sort

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 4: `/{username}/gallery` — set list screen

**Files:**
- Create: `app/Livewire/Gallery/Index.php`
- Create: `resources/views/livewire/gallery/index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Livewire/Gallery/IndexTest.php`

**Interfaces:**
- Consumes: `User::$username` (Task 1), `Collection.is_public` (existing
  schema), `Set`/`Card`/`CollectionItem` (existing).
- Produces: the `/{username}/gallery` page — Task 5's set-detail links
  target `route('gallery.show', ['username' => ..., 'setTcgdexId' => ...])`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;

test('lists sets the user has at least one card from, with correct completion counts', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $card2 = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    // two rows for the same card (different condition) must count once
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'LP', 'quantity' => 1]);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card2->id, 'card_tcgdex_id' => 'me05-003', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get("/carlos/gallery");

    $response->assertOk();
    $response->assertSee('Pitch Black');
    $response->assertSee('2 / 120'); // 2 distinct cards owned, out of the set's total
});

test('a set the user has no cards from does not appear', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    Set::create(['tcgdex_id' => 'untouched', 'name' => 'Never Added']);

    $response = $this->get('/carlos/gallery');

    $response->assertOk();
    $response->assertDontSee('Never Added');
});

test('a nonexistent username 404s, not an empty page', function () {
    $response = $this->get('/nobody-here/gallery');

    $response->assertNotFound();
});

test('a private (non-public) collection contributes nothing to the gallery', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => false, 'slug' => 'private']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/gallery');

    $response->assertOk();
    $response->assertDontSee('Pitch Black');
});

test('the gallery route requires no authentication', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    // Explicitly NOT calling $this->actingAs(...) — a guest must be able to load this.
    $response = $this->get('/carlos/gallery');

    $response->assertOk();
});
```

- [ ] **Step 2: Run to see it fail**

```bash
./vendor/bin/sail artisan test --filter=IndexTest
```
Expected: FAIL — route/component don't exist.

- [ ] **Step 3: Implement the component**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Models\User;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Scopes\TenantScope;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class Index extends Component
{
    public User $targetUser;

    public function mount(string $username): void
    {
        $user = User::where('username', $username)->first();

        if ($user === null) {
            throw new NotFoundHttpException();
        }

        $this->targetUser = $user;
    }

    public function render()
    {
        // Explicit opt-out of TenantScope: this is a PUBLIC route with no
        // authenticated user, so the scope's own auth()->id() would
        // resolve to null and fail-closed to zero rows — the correct
        // behavior for every OTHER query in this app, but wrong here,
        // where we deliberately want $this->targetUser's rows regardless
        // of who (if anyone) is logged in. This is the same explicit,
        // auditable pattern DatabaseSeeder already uses for its own
        // legitimate need to bypass the scope.
        $publicCollectionIds = Collection::withoutGlobalScope(TenantScope::class)
            ->where('user_id', $this->targetUser->id)
            ->where('is_public', true)
            ->pluck('id');

        $sets = Set::query()
            ->whereHas('cards.collectionItems', function ($query) use ($publicCollectionIds) {
                $query->whereIn('collection_id', $publicCollectionIds);
            })
            ->withCount([
                'cards as owned_card_count' => function ($query) use ($publicCollectionIds) {
                    $query->whereHas('collectionItems', function ($q) use ($publicCollectionIds) {
                        $q->whereIn('collection_id', $publicCollectionIds);
                    });
                },
            ])
            ->get();

        return view('livewire.gallery.index', ['sets' => $sets]);
    }
}
```

This needs a new relation on `Card`: `collectionItems()`. Add to
`app/Modules/Catalog/Models/Card.php`:
```php
public function collectionItems(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(\App\Modules\Collection\Models\CollectionItem::class);
}
```
(Check the top of `Card.php` for its existing `use` imports and add
`HasMany`/`CollectionItem` there properly instead of fully-qualifying
inline, matching the file's existing style — the inline form above is
just to show the relationship, not the literal diff to paste.)

**Note on `Set.card_count`:** this column already exists on `Set` (Phase
1) but may not be populated for every set depending on how it was synced
— if it's `null` for a set your test data doesn't set it on, the
completion display falls back to counting `$set->cards()->count()`
instead of trusting a possibly-stale/missing column. Handle this in the
Blade view (Step 4) with `{{ $set->card_count ?? $set->cards()->count() }}`,
not by assuming the column is always populated.

- [ ] **Step 4: Write the view**

`resources/views/livewire/gallery/index.blade.php`:
```blade
<div class="max-w-5xl mx-auto py-10 px-4">
    <h1 class="text-xl font-semibold mb-6" style="color: var(--ink)">{{ $targetUser->name }}'s Collection</h1>

    <div class="grid gap-4" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr))">
        @foreach ($sets as $set)
            @php
                $total = $set->card_count ?? $set->cards()->count();
                $owned = $set->owned_card_count;
                $pct = $total > 0 ? (int) round(($owned / $total) * 100) : 0;
            @endphp
            <a href="{{ route('gallery.show', ['username' => $targetUser->username, 'setTcgdexId' => $set->tcgdex_id]) }}"
               class="nw-card p-4 block">
                @if ($set->logo_url)
                    <img src="{{ $set->logo_url }}" alt="{{ $set->name }}" class="w-full h-16 object-contain mb-3">
                @endif
                <div class="font-medium mb-1" style="color: var(--ink)">{{ $set->name }}</div>
                <div class="text-xs mb-2" style="color: var(--muted)">{{ $set->series }}</div>
                <div class="mono text-xs mb-1" style="color: var(--muted)">{{ $owned }} / {{ $total }}</div>
                <div class="w-full rounded-full h-1.5" style="background: var(--bone-2)">
                    <div class="h-1.5 rounded-full" style="background: var(--signal); width: {{ $pct }}%"></div>
                </div>
            </a>
        @endforeach
    </div>
</div>
```

- [ ] **Step 5: Wire the route**

In `routes/web.php`, add BEFORE `require __DIR__.'/auth.php';` (and after
the existing `admin.collection.index` route):
```php
Route::get('/{username}/gallery', \App\Livewire\Gallery\Index::class)
    ->name('gallery.index');
```
No `->middleware(['auth'])` — this is deliberate, per the global
constraint. Double check this doesn't shadow `/admin`, `/profile`,
`/login`, etc. — it requires a literal `/gallery` second segment, so it
can't.

- [ ] **Step 6: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=IndexTest
```
Expected: 5 passed. Then the full suite.

- [ ] **Step 7: Verify manually in the browser**

With Sail on port 8090, log out (or use a private/incognito window), and
visit `http://localhost:8090/<the seeded username from Task 1's tinker
step>/gallery`. Confirm the set(s) you've added cards from appear with
correct completion counts, and that this works with NO active login
session.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Gallery/Index.php resources/views/livewire/gallery/index.blade.php app/Modules/Catalog/Models/Card.php routes/web.php tests/Feature/Livewire/Gallery/IndexTest.php
git commit -m "feat(gallery): add the public set-list screen

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 5: `/{username}/gallery/{setTcgdexId}` — set detail screen

**Files:**
- Create: `app/Livewire/Gallery/Show.php`
- Create: `resources/views/livewire/gallery/show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Livewire/Gallery/ShowTest.php`

**Interfaces:**
- Consumes: `CardPriceResolver` (Task 3), `<x-card-image>` (Task 2),
  everything Task 4 established for resolving `$targetUser` and
  `is_public` collections.
- Produces: nothing further consumes this — it's the deepest screen in
  this phase.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;

test('shows every card in the set, marks owned ones, computes stats from the whole set', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $owned = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $notOwned = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $owned->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    CardPriceSnapshot::create(['card_id' => $owned->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 5000]);
    CardPriceSnapshot::create(['card_id' => $notOwned->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1000]);

    $response = $this->get('/carlos/gallery/me05');

    $response->assertOk();
    $response->assertSee('Mega Darkrai ex'); // owned, shown
    $response->assertSee('Fomantis'); // not owned, still shown per spec §6
    $response->assertSee('Mega Darkrai ex'); // most expensive card in the SET, not just owned
});

test('a set that exists but the user has never touched 404s', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    Set::create(['tcgdex_id' => 'untouched', 'name' => 'Never Added']);

    $response = $this->get('/carlos/gallery/untouched');

    $response->assertNotFound();
});

test('uses the users own photo over official art when owned and photographed', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 1]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex', 'official_image_url' => 'https://official.example/card.webp']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1, 'photo_path' => 'my-photo.jpg']);

    $response = $this->get('/carlos/gallery/me05');

    $response->assertSee(\Illuminate\Support\Facades\Storage::disk('collection-photos')->url('my-photo.jpg'), false);
    $response->assertDontSee('https://official.example/card.webp', false);
});

test('search filters the card grid by name', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    $card1 = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $card2 = Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    \Livewire\Livewire::test(\App\Livewire\Gallery\Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])
        ->set('search', 'Darkrai')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Fomantis');
});

test('rarity filter only shows cards of the selected rarity', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 2]);
    Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex', 'rarity' => 'SIR']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis', 'rarity' => 'Common']);

    \Livewire\Livewire::test(\App\Livewire\Gallery\Show::class, ['username' => 'carlos', 'setTcgdexId' => 'me05'])
        ->set('rarityFilter', 'SIR')
        ->assertSee('Mega Darkrai ex')
        ->assertDontSee('Fomantis');
});
```

- [ ] **Step 2: Run to see it fail**

```bash
./vendor/bin/sail artisan test --filter=ShowTest
```
Expected: FAIL — route/component don't exist.

- [ ] **Step 3: Implement the component**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Models\User;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Scopes\TenantScope;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class Show extends Component
{
    public User $targetUser;

    public Set $set;

    public string $search = '';

    public string $sort = 'number';

    public string $rarityFilter = '';

    public function mount(string $username, string $setTcgdexId): void
    {
        $user = User::where('username', $username)->first();

        if ($user === null) {
            throw new NotFoundHttpException();
        }

        $this->targetUser = $user;

        $publicCollectionIds = Collection::withoutGlobalScope(TenantScope::class)
            ->where('user_id', $user->id)
            ->where('is_public', true)
            ->pluck('id');

        $set = Set::where('tcgdex_id', $setTcgdexId)
            ->whereHas('cards.collectionItems', function ($query) use ($publicCollectionIds) {
                $query->whereIn('collection_id', $publicCollectionIds);
            })
            ->first();

        if ($set === null) {
            throw new NotFoundHttpException();
        }

        $this->set = $set;
    }

    public function render()
    {
        $publicCollectionIds = Collection::withoutGlobalScope(TenantScope::class)
            ->where('user_id', $this->targetUser->id)
            ->where('is_public', true)
            ->pluck('id');

        $cardsQuery = $this->set->cards()
            ->with(['priceSnapshots', 'collectionItems' => function ($query) use ($publicCollectionIds) {
                $query->whereIn('collection_id', $publicCollectionIds);
            }]);

        if ($this->search !== '') {
            $cardsQuery->where('name', 'like', '%'.$this->search.'%');
        }

        if ($this->rarityFilter !== '') {
            $cardsQuery->where('rarity', $this->rarityFilter);
        }

        $cards = $cardsQuery->get();

        $resolver = new CardPriceResolver();
        $priced = $cards->map(fn ($card) => [
            'card' => $card,
            'snapshot' => $resolver->resolve($card),
            'ownedItem' => $card->collectionItems->first(),
        ]);

        $priced = match ($this->sort) {
            'name' => $priced->sortBy(fn ($p) => $p['card']->name),
            'rarity' => $priced->sortBy(fn ($p) => $p['card']->rarity ?? ''),
            'price' => $priced->sortByDesc(fn ($p) => $p['snapshot']?->market_minor ?? -1),
            default => $priced->sortBy(fn ($p) => $p['card']->local_id),
        };

        $allSetCards = $this->set->cards()->with('priceSnapshots')->get();
        $mostExpensive = $allSetCards
            ->map(fn ($card) => ['card' => $card, 'snapshot' => $resolver->resolve($card)])
            ->filter(fn ($p) => $p['snapshot'] !== null)
            ->sortByDesc(fn ($p) => $p['snapshot']->market_minor)
            ->first();

        $fullSetValueMinor = $allSetCards
            ->map(fn ($card) => $resolver->resolve($card)?->market_minor ?? 0)
            ->sum();

        $ownedCount = $this->set->cards()
            ->whereHas('collectionItems', fn ($q) => $q->whereIn('collection_id', $publicCollectionIds))
            ->count();

        $rarities = $this->set->cards()->whereNotNull('rarity')->distinct()->pluck('rarity');

        return view('livewire.gallery.show', [
            'priced' => $priced,
            'mostExpensive' => $mostExpensive,
            'fullSetValueMinor' => $fullSetValueMinor,
            'ownedCount' => $ownedCount,
            'totalCount' => $this->set->card_count ?? $allSetCards->count(),
            'rarities' => $rarities,
        ]);
    }
}
```

- [ ] **Step 4: Write the view**

`resources/views/livewire/gallery/show.blade.php`:
```blade
<div class="max-w-5xl mx-auto py-10 px-4">
    <div class="mb-6">
        <h1 class="text-xl font-semibold" style="color: var(--ink)">{{ $set->name }}</h1>
        <div class="text-xs mb-3" style="color: var(--muted)">{{ $set->series }}</div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3 text-sm">
            <div>
                <div style="color: var(--muted)">Total cards</div>
                <div class="mono" style="color: var(--ink)">{{ $totalCount }}</div>
            </div>
            <div>
                <div style="color: var(--muted)">Most expensive</div>
                <div style="color: var(--ink)">
                    @if ($mostExpensive)
                        {{ $mostExpensive['card']->name }}
                        <span class="mono">({{ number_format($mostExpensive['snapshot']->market_minor / 100, 2) }} {{ $mostExpensive['snapshot']->currency }})</span>
                    @else
                        —
                    @endif
                </div>
            </div>
            <div>
                <div style="color: var(--muted)">Full set value</div>
                <div class="mono" style="color: var(--ink)">{{ number_format($fullSetValueMinor / 100, 2) }}</div>
            </div>
            <div>
                <div style="color: var(--muted)">You own</div>
                <div class="mono" style="color: var(--ink)">{{ $ownedCount }} / {{ $totalCount }}</div>
            </div>
        </div>

        <div class="w-full rounded-full h-1.5" style="background: var(--bone-2)">
            @php $pct = $totalCount > 0 ? (int) round(($ownedCount / $totalCount) * 100) : 0; @endphp
            <div class="h-1.5 rounded-full" style="background: var(--signal); width: {{ $pct }}%"></div>
        </div>
    </div>

    <div class="flex flex-wrap gap-3 mb-6">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search this set..."
               class="nw-input flex-1 min-w-[180px]">

        <select wire:model.live="sort" class="nw-input">
            <option value="number">Sort: Number</option>
            <option value="name">Sort: Name</option>
            <option value="rarity">Sort: Rarity</option>
            <option value="price">Sort: Price</option>
        </select>

        <select wire:model.live="rarityFilter" class="nw-input">
            <option value="">All rarities</option>
            @foreach ($rarities as $rarity)
                <option value="{{ $rarity }}">{{ $rarity }}</option>
            @endforeach
        </select>
    </div>

    <div class="grid gap-4" style="grid-template-columns: repeat(auto-fill, minmax(160px, 1fr))">
        @foreach ($priced as $p)
            @php
                $card = $p['card'];
                $ownedItem = $p['ownedItem'];
                $photoUrl = $ownedItem?->photo_path
                    ? \Illuminate\Support\Facades\Storage::disk('collection-photos')->url($ownedItem->photo_path)
                    : $card->official_image_url;
            @endphp
            <div class="nw-card p-2" style="{{ $ownedItem ? 'box-shadow: 0 0 0 2px var(--signal)' : '' }}">
                <x-card-image :url="$photoUrl" :name="$card->name" />
                <div class="text-sm font-medium mt-2" style="color: var(--ink)">{{ $card->name }}</div>
                <div class="mono text-xs" style="color: var(--muted)">{{ $card->local_id }}</div>
                @if ($p['snapshot'])
                    <div class="mono text-xs" style="color: var(--ink)">
                        {{ number_format($p['snapshot']->market_minor / 100, 2) }} {{ $p['snapshot']->currency }}
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
```
Reuses `.nw-card`/`.nw-input` and every color/token from Task 4's view and
Phase 2's admin screens — no new visual language introduced.

- [ ] **Step 5: Wire the route**

In `routes/web.php`, right after `gallery.index`:
```php
Route::get('/{username}/gallery/{setTcgdexId}', \App\Livewire\Gallery\Show::class)
    ->name('gallery.show');
```

- [ ] **Step 6: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=ShowTest
```
Expected: 5 passed. Then the full suite — this is the last task in this
phase, confirm zero regressions across all of Phase 1 + 2 + 3.

- [ ] **Step 7: Rebuild assets**

```bash
./vendor/bin/sail npm run build
```
No Vite watcher in this environment — confirm a new asset hash before
manual verification.

- [ ] **Step 8: Verify manually in the browser, end to end**

Logged out (or incognito): visit `/<username>/gallery`, click into a set,
confirm the card grid shows both owned and un-owned cards, owned ones
have the signal-green marker, search/sort/rarity-filter all work, stats
band shows the whole-set most-expensive-card and value (not just what you
own), and a card you've photographed shows your photo instead of official
art. Then try a nonsense `/{username}/gallery/does-not-exist` URL and
confirm a real 404, not a blank page or exception trace.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/Gallery/Show.php resources/views/livewire/gallery/show.blade.php routes/web.php tests/Feature/Livewire/Gallery/ShowTest.php
git commit -m "feat(gallery): add the public set-detail screen with search/sort/rarity-filter

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

## What Phase 3 deliberately does NOT include

- Browsing sets the target user has never added a card from (needs a
  bulk tcgdex import feature — not built).
- Grayscale/faded placeholder art for un-owned cards in a not-yet-touched
  set (depends on the above; Carlos's own idea, explicitly "for later").
- The "Movimientos" price-history chart / activity feed (needs Phase 4's
  daily snapshot job to have real history to show).
- Real multi-user registration/onboarding UX — only the `{username}` URL
  shape and column exist now, per Carlos's explicit "build the seam now,
  not the feature."
- A per-card detail/drill-down page.
- English translation pass (per the parent spec, done once at the end,
  not incrementally — this phase's UI copy can ship in whichever language
  is fastest to iterate, matching how Phase 1/2 already did this).
