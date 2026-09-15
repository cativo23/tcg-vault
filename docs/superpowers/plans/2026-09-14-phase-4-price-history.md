# Phase 4 — Daily Price History + Movimientos Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A daily queued job that refreshes pricing for every already-synced
card, and the "Movimientos" public gallery screen (price deltas + activity
feed) the nav header has been showing as inert since Phase 3.

**Architecture:** One new queued Job + one new artisan command + one
scheduler entry (Task 1). One extension to the existing
`CardPriceResolver` + one new Livewire full-page component reusing the
exact `is_public`/`TenantScope` pattern Tasks 4/5 already established
(Task 2).

**Tech Stack:** Laravel queues (database or sync driver — whatever's
already configured, no new infrastructure), Pest, no new npm dependencies
(explicit non-goal, see spec §3).

## Global Constraints

- No new schema. `CardPriceSnapshot`'s existing `(card_id, source,
  variant, captured_on)` primary key already IS the history mechanism —
  syncing the same card on a later date just adds another row.
- `SyncCardPricingJob` must not let one card's failure abort the whole
  day's run — retry that ONE card up to 3 times, then log and move on.
- Movimientos reachability follows the SAME `is_public`/`TenantScope`
  pattern as `Gallery\Index`/`Gallery\Show` (Phase 3, Tasks 4/5) — read
  `app/Livewire/Gallery/Show.php` first and reuse it exactly, don't
  reinvent.
- `--signal` (green) = price up, `--flat` (neutral gray) = price down or
  unchanged — this is `design.md`'s ORIGINAL, primary meaning for both
  tokens (predates Phase 2/3's later reuse of `--signal` for progress
  bars/ownership markers) — Movimientos is simply the first screen that
  actually needs it for its original purpose.
- Route registration ORDER matters: `/{username}/gallery/movimientos`
  MUST be registered in `routes/web.php` BEFORE
  `/{username}/gallery/{setTcgdexId}` — Laravel matches route patterns in
  registration order, and `{setTcgdexId}` is a wildcard that would
  otherwise swallow the literal `movimientos` segment first, making the
  new screen permanently unreachable (silently 404ing as if "movimientos"
  were a nonexistent set).
- Pest tests only, one concern per commit, conventional commit format.

---

### Task 1: Daily card-pricing refresh job

**Files:**
- Create: `app/Jobs/SyncCardPricingJob.php`
- Create: `app/Console/Commands/RefreshCardPricing.php`
- Modify: `routes/console.php`
- Test: `tests/Unit/Jobs/SyncCardPricingJobTest.php`
- Test: `tests/Feature/Console/RefreshCardPricingTest.php`

**Interfaces:**
- Consumes: `CatalogSyncService::syncCard(string $tcgdexCardId): Card`
  (Phase 1, unchanged).
- Produces: nothing new consumed elsewhere in this phase — Task 2 reads
  `CardPriceSnapshot` rows this job's daily runs will eventually create,
  not the job itself.

- [ ] **Step 1: Write the failing tests**

```php
// tests/Unit/Jobs/SyncCardPricingJobTest.php
<?php

declare(strict_types=1);

use App\Jobs\SyncCardPricingJob;
use App\Modules\Catalog\Services\CatalogSyncService;

test('the job calls CatalogSyncService::syncCard with the given tcgdex id', function () {
    $syncService = Mockery::mock(CatalogSyncService::class);
    $syncService->shouldReceive('syncCard')->once()->with('me05-116');

    (new SyncCardPricingJob('me05-116'))->handle($syncService);
});

test('the job does not rethrow when the card sync fails — it logs and lets the queue retry mechanism handle it', function () {
    $syncService = Mockery::mock(CatalogSyncService::class);
    $syncService->shouldReceive('syncCard')
        ->with('me05-116')
        ->andThrow(new \App\Modules\Catalog\Exceptions\CardNotFoundException('gone'));

    // A genuinely-gone card should fail immediately (not retry 3 times
    // uselessly against an ID that will never resolve) — handle() must
    // catch CardNotFoundException/CatalogIdentityMismatchException
    // specifically and just log, while letting any OTHER exception
    // (a transient HTTP failure) propagate so the queue's own retry
    // mechanism (`$tries`) kicks in.
    (new SyncCardPricingJob('me05-116'))->handle($syncService);
})->throwsNoExceptions();
```

```php
// tests/Feature/Console/RefreshCardPricingTest.php
<?php

declare(strict_types=1);

use App\Jobs\SyncCardPricingJob;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use Illuminate\Support\Facades\Queue;

test('dispatches one SyncCardPricingJob per existing card, with no HTTP calls made', function () {
    Queue::fake();

    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Fomantis']);

    $this->artisan('catalog:refresh-prices')->assertSuccessful();

    Queue::assertPushed(SyncCardPricingJob::class, 2);
    Queue::assertPushed(fn (SyncCardPricingJob $job) => $job->tcgdexCardId === 'me05-116');
    Queue::assertPushed(fn (SyncCardPricingJob $job) => $job->tcgdexCardId === 'me05-003');
});

test('an empty Catalog dispatches nothing and still succeeds', function () {
    Queue::fake();

    $this->artisan('catalog:refresh-prices')->assertSuccessful();

    Queue::assertNothingPushed();
});
```

- [ ] **Step 2: Run to see it fail**

```bash
./vendor/bin/sail artisan test --filter=SyncCardPricingJobTest
./vendor/bin/sail artisan test --filter=RefreshCardPricingTest
```
Expected: FAIL — the job/command classes don't exist yet.

- [ ] **Step 3: Implement the job**

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Exceptions\CatalogIdentityMismatchException;
use App\Modules\Catalog\Services\CatalogSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class SyncCardPricingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 3 attempts with backoff — a transient tcgdex hiccup shouldn't drop
     * a card from today's refresh, but a card that's actually gone
     * (see handle()'s catch below) fails fast instead of burning all 3.
     */
    public int $tries = 3;

    public function __construct(public readonly string $tcgdexCardId) {}

    public function backoff(): array
    {
        return [10, 30, 60]; // seconds
    }

    public function handle(CatalogSyncService $syncService): void
    {
        try {
            $syncService->syncCard($this->tcgdexCardId);
        } catch (CardNotFoundException|CatalogIdentityMismatchException $e) {
            // Not transient — retrying this exact ID won't help. Log and
            // let this ONE card's failure end here; the day's other jobs
            // are unaffected (each SyncCardPricingJob is independent).
            Log::warning('SyncCardPricingJob: card sync failed permanently, not retrying', [
                'tcgdex_card_id' => $this->tcgdexCardId,
                'reason' => $e->getMessage(),
            ]);
        }
        // Any other exception (network/HTTP failure) propagates
        // uncaught, so the queue's own retry/backoff actually applies.
    }
}
```

- [ ] **Step 4: Implement the command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncCardPricingJob;
use App\Modules\Catalog\Models\Card;
use Illuminate\Console\Command;

final class RefreshCardPricing extends Command
{
    protected $signature = 'catalog:refresh-prices';

    protected $description = 'Dispatch a pricing-refresh job for every card already in the Catalog.';

    public function handle(): int
    {
        $count = 0;

        Card::query()->select('tcgdex_id')->chunk(200, function ($cards) use (&$count) {
            foreach ($cards as $card) {
                SyncCardPricingJob::dispatch($card->tcgdex_id);
                $count++;
            }
        });

        $this->info("Dispatched {$count} pricing-refresh job(s).");

        return self::SUCCESS;
    }
}
```
(`chunk(200, ...)` rather than `Card::all()` — this command will
eventually run against every card Carlos has ever added across every
set, and loading all of them into memory at once doesn't scale the same
way a single admin action does.)

- [ ] **Step 5: Schedule it daily**

In `routes/console.php`, add:
```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('catalog:refresh-prices')->daily();
```

- [ ] **Step 6: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=SyncCardPricingJobTest
./vendor/bin/sail artisan test --filter=RefreshCardPricingTest
```
Expected: passing. Then the full suite (should be 127+, all passing).

- [ ] **Step 7: Verify manually**

```bash
./vendor/bin/sail artisan catalog:refresh-prices
```
With `QUEUE_CONNECTION=sync` (check `.env` — if it's `sync`, jobs run
inline immediately; if it's `database` or similar, also run
`./vendor/bin/sail artisan queue:work --once` per dispatched job or
`--stop-when-empty` to drain the queue). Confirm via tinker that a
card's `CardPriceSnapshot` rows now include today's `captured_on` date
for at least one already-synced card.

- [ ] **Step 8: Commit**

```bash
git add app/Jobs/SyncCardPricingJob.php app/Console/Commands/RefreshCardPricing.php routes/console.php tests/Unit/Jobs/SyncCardPricingJobTest.php tests/Feature/Console/RefreshCardPricingTest.php
git commit -m "feat(catalog): add a daily queued job to refresh pricing for every synced card

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

### Task 2: The Movimientos screen

**Files:**
- Modify: `app/Modules/Catalog/Services/CardPriceResolver.php`
- Create: `app/Livewire/Gallery/Movimientos.php`
- Create: `resources/views/livewire/gallery/movimientos.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/public.blade.php`
- Test: `tests/Unit/Modules/Catalog/CardPriceResolverTest.php` (existing —
  add cases here)
- Test: `tests/Feature/Livewire/Gallery/MovimientosTest.php`

**Interfaces:**
- Consumes: `CardPriceSnapshot` rows Task 1's job populates over time (a
  fresh Catalog with only one day's snapshots simply shows no deltas yet
  — that's correct, not a bug to work around).
- Consumes: the exact `is_public`/`TenantScope`/`User::where('username',
  ...)` pattern `Gallery\Show::mount()` already established — read that
  file first, copy its shape.

- [ ] **Step 1: Extend `CardPriceResolver`**

Read the current file in full before editing — refactor `resolve()`'s
body into a private helper both the existing method and two new ones
share, without changing `resolve()`'s existing behavior/signature (Task
5's gallery screen already depends on it unchanged):

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CardPriceResolver
{
    public function resolve(Card $card): ?CardPriceSnapshot
    {
        return $this->resolveFrom($card->priceSnapshots);
    }

    /**
     * Same priority-order resolution as resolve(), but only considering
     * snapshots captured on or before $asOf — lets a caller ask "what
     * was the price as of THIS date," not just "the latest."
     */
    public function resolveAsOf(Card $card, CarbonInterface $asOf): ?CardPriceSnapshot
    {
        $eligible = $card->priceSnapshots->filter(
            fn (CardPriceSnapshot $s) => $s->captured_on->lte($asOf),
        );

        return $this->resolveFrom($eligible);
    }

    /**
     * The distinct dates this card has ANY snapshot for, most recent
     * first. A card synced only once has exactly one date (no prior day
     * to compare against for a delta); a card synced on 2+ different
     * days has one entry per day regardless of how many source/variant
     * rows exist on each day.
     *
     * @return Collection<int, \Carbon\CarbonImmutable>
     */
    public function distinctSnapshotDates(Card $card): Collection
    {
        return $card->priceSnapshots
            ->pluck('captured_on')
            ->unique(fn ($date) => $date->toDateString())
            ->sortByDesc(fn ($date) => $date->toDateString())
            ->values();
    }

    /**
     * @param Collection<int, CardPriceSnapshot> $snapshots
     */
    private function resolveFrom(Collection $snapshots): ?CardPriceSnapshot
    {
        $snapshots = $snapshots->sortByDesc('captured_on')->values();

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

Add tests to the existing `CardPriceResolverTest.php`:
```php
test('resolveAsOf ignores snapshots captured after the given date', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays(2), 'currency' => 'USD', 'market_minor' => 1000]);
    $newer = CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1200]);

    $resolved = (new CardPriceResolver())->resolveAsOf($card, today()->subDays(2));

    expect($resolved->market_minor)->toBe(1000);
    expect($resolved->id)->not->toBe($newer->id);
});

test('distinctSnapshotDates returns one entry per day, most recent first, regardless of how many source rows exist per day', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'cardmarket', 'variant' => 'default', 'captured_on' => today()->subDay(), 'currency' => 'EUR', 'market_minor' => 900]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1100]);

    $dates = (new CardPriceResolver())->distinctSnapshotDates($card);

    expect($dates)->toHaveCount(2);
    expect($dates->first()->toDateString())->toBe(today()->toDateString());
});
```

- [ ] **Step 2: Write the failing screen tests**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;

test('shows a price delta for a card with two snapshot days', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDay(), 'currency' => 'USD', 'market_minor' => 1000]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1200]);

    $response = $this->get('/carlos/gallery/movimientos');

    $response->assertOk();
    $response->assertSee('Mega Darkrai ex');
});

test('a card with only one snapshot day shows no delta, not a fake one', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1000]);

    $response = $this->get('/carlos/gallery/movimientos');

    $response->assertOk();
    // The card shouldn't be listed among the deltas at all (no comparison possible).
    $response->assertDontSee('+$', false);
    $response->assertDontSee('-$', false);
});

test('shows recently added items in the activity feed', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/gallery/movimientos');

    $response->assertOk();
    $response->assertSee('Mega Darkrai ex');
});

test('a private (non-public) collection contributes nothing', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => false, 'slug' => 'private']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $response = $this->get('/carlos/gallery/movimientos');

    $response->assertOk();
    $response->assertDontSee('Mega Darkrai ex');
});

test('the movimientos route requires no authentication', function () {
    $user = User::factory()->create(['username' => 'carlos']);
    Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);

    $response = $this->get('/carlos/gallery/movimientos');

    $response->assertOk();
});

test('a nonexistent username 404s', function () {
    $response = $this->get('/nobody-here/gallery/movimientos');

    $response->assertNotFound();
});
```

- [ ] **Step 3: Run to see it fail**

```bash
./vendor/bin/sail artisan test --filter=MovimientosTest
./vendor/bin/sail artisan test --filter=CardPriceResolverTest
```
Expected: FAIL — route/component don't exist, new resolver methods don't
exist.

- [ ] **Step 4: Implement the component**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Scopes\TenantScope;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Layout('layouts.public')]
final class Movimientos extends Component
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
        $publicCollectionIds = Collection::withoutGlobalScope(TenantScope::class)
            ->where('user_id', $this->targetUser->id)
            ->where('is_public', true)
            ->pluck('id');

        $resolver = new CardPriceResolver();

        $cards = Card::whereHas('collectionItems', function ($query) use ($publicCollectionIds) {
            $query->whereIn('collection_id', $publicCollectionIds);
        })->with('priceSnapshots')->get();

        $deltas = $cards
            ->map(function (Card $card) use ($resolver) {
                $dates = $resolver->distinctSnapshotDates($card);

                if ($dates->count() < 2) {
                    return null;
                }

                $latest = $resolver->resolveAsOf($card, $dates[0]);
                $previous = $resolver->resolveAsOf($card, $dates[1]);

                if ($latest === null || $previous === null) {
                    return null;
                }

                return [
                    'card' => $card,
                    'latest' => $latest,
                    'deltaMinor' => $latest->market_minor - $previous->market_minor,
                ];
            })
            ->filter()
            ->values();

        $recentItems = CollectionItem::whereIn('collection_id', $publicCollectionIds)
            ->with('card')
            ->latest()
            ->take(20)
            ->get();

        return view('livewire.gallery.movimientos', [
            'deltas' => $deltas,
            'recentItems' => $recentItems,
        ]);
    }
}
```

- [ ] **Step 5: Write the view**

`resources/views/livewire/gallery/movimientos.blade.php`:
```blade
<div class="max-w-5xl mx-auto py-10 px-4">
    <h1 class="text-xl font-semibold mb-6" style="color: var(--ink)">{{ $targetUser->name }}'s Movimientos</h1>

    <section class="mb-10">
        <h2 class="text-sm font-semibold uppercase tracking-wide mb-3" style="color: var(--muted)">Price changes</h2>
        <div class="nw-card divide-y" style="border-color: var(--hair)">
            @forelse ($deltas as $d)
                @php $up = $d['deltaMinor'] > 0; @endphp
                <div class="flex items-center justify-between p-3">
                    <span style="color: var(--ink)">{{ $d['card']->name }}</span>
                    <span class="mono text-sm" style="color: {{ $up ? 'var(--signal)' : 'var(--flat)' }}">
                        {{ $up ? '+' : '' }}{{ number_format($d['deltaMinor'] / 100, 2) }} {{ $d['latest']->currency }}
                    </span>
                </div>
            @empty
                <div class="p-6 text-center" style="color: var(--muted)">No price changes yet — check back after the next daily refresh.</div>
            @endforelse
        </div>
    </section>

    <section>
        <h2 class="text-sm font-semibold uppercase tracking-wide mb-3" style="color: var(--muted)">Recently added</h2>
        <div class="nw-card divide-y" style="border-color: var(--hair)">
            @forelse ($recentItems as $item)
                <div class="flex items-center gap-3 p-3">
                    <div class="w-10 h-10 flex-none">
                        <x-card-image :url="$item->card->official_image_url" :name="$item->card->name" />
                    </div>
                    <div>
                        <div style="color: var(--ink)">Added {{ $item->card->name }}</div>
                        <div class="text-xs" style="color: var(--muted)">{{ $item->created_at->diffForHumans() }}</div>
                    </div>
                </div>
            @empty
                <div class="p-6 text-center" style="color: var(--muted)">No activity yet.</div>
            @endforelse
        </div>
    </section>
</div>
```

- [ ] **Step 6: Wire the route**

In `routes/web.php`, add **BEFORE** the existing `gallery.show` route
(per Global Constraints' route-order warning — the wildcard would
otherwise swallow this literal segment):
```php
Route::get('/{username}/gallery/movimientos', \App\Livewire\Gallery\Movimientos::class)
    ->name('gallery.movimientos');
```

- [ ] **Step 7: Flip the nav header from inert to a real link**

In `resources/views/layouts/public.blade.php`, replace the inert
"Movimientos" `<span>` with a real link, following the exact same
conditional-active-class pattern the "Sets" link already uses:
```blade
<a href="{{ request()->route('username') ? route('gallery.movimientos', ['username' => request()->route('username')]) : '#' }}"
   class="nw-link {{ request()->routeIs('gallery.movimientos') ? 'is-active' : '' }}">Movimientos</a>
```

- [ ] **Step 8: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=MovimientosTest
./vendor/bin/sail artisan test --filter=CardPriceResolverTest
```
Expected: all passing. Then the full suite — confirm zero regressions
across all of Phase 1-4.

- [ ] **Step 9: Rebuild assets, verify manually**

```bash
./vendor/bin/sail npm run build
```
With Sail on port 8090: run `./vendor/bin/sail artisan catalog:refresh-prices`
(Task 1) on two different simulated days if practical (or manually insert
a second day's snapshot via tinker) to actually see a real delta render,
not just an empty state. Visit `/cativo23/gallery/movimientos`, confirm
the nav header's "Movimientos" link is now clickable and shows as active,
confirm the price-delta section and activity feed both render, and
confirm a private collection's cards never appear.

- [ ] **Step 10: Commit**

```bash
git add app/Modules/Catalog/Services/CardPriceResolver.php app/Livewire/Gallery/Movimientos.php resources/views/livewire/gallery/movimientos.blade.php routes/web.php resources/views/layouts/public.blade.php tests/Unit/Modules/Catalog/CardPriceResolverTest.php tests/Feature/Livewire/Gallery/MovimientosTest.php
git commit -m "feat(gallery): add the Movimientos screen (price deltas + activity feed)

Claude-Session: https://claude.ai/code/session_012LsNYkxAqmMouQegf42JTd"
```

---

## What Phase 4 deliberately does NOT include

- A chart/graph — the price-deltas list surfaces the same facts a chart
  would plot, without a new JS dependency. Revisit later if this feels
  insufficient once real multi-day data exists.
- Discovering new cards/sets — this refreshes pricing for cards already
  in the Catalog only.
- Any change to how Phase 1-3 already sync/display current prices —
  `CardPriceResolver::resolve()` (unchanged signature) still powers the
  Sets/set-detail screens exactly as before.
