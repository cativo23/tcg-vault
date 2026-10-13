<?php

declare(strict_types=1);

use App\Jobs\ImportSetJob;
use App\Livewire\Admin\SetGrid;
use App\Livewire\Admin\SetImportBanner;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\ImportBudget;
use App\Modules\Catalog\Services\ImportBudgetResult;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

/** A signed-in collector who may use /admin. */
function bannerUser(): User
{
    test()->seed(PermissionSeeder::class);
    $user = User::factory()->create();
    test()->actingAs($user);

    return $user;
}

/** Pitch Black announces five cards; $loaded of them exist locally. */
function bannerSet(int $loaded, ?int $total = 5): Set
{
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => $total]);

    for ($n = 1; $n <= $loaded; $n++) {
        Card::create(['tcgdex_id' => sprintf('me05-%03d', $n), 'set_id' => $set->id, 'local_id' => sprintf('%03d', $n), 'name' => "Card {$n}"]);
    }

    return $set;
}

test('an incomplete set shows how many cards have loaded and keeps polling', function () {
    bannerUser();
    bannerSet(2);

    Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05'])
        ->assertSee('Loading cards…')
        ->assertSeeHtml('<span class="mono">2/5</span>')
        ->assertSeeHtml('wire:poll.5s');
});

test('a complete set shows no banner and stops polling', function () {
    bannerUser();
    bannerSet(5);

    Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05'])
        ->assertDontSee('Loading cards')
        ->assertDontSeeHtml('wire:poll');
});

test('a set whose size tcgdex never reported is not treated as importing', function (?int $total) {
    bannerUser();
    bannerSet(1, $total);

    Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05'])
        ->assertDontSee('Loading cards')
        ->assertDontSeeHtml('wire:poll');
})->with([[null], [0]]);

test('after ten minutes with no new card the banner falls back to a slow poll and offers a retry', function () {
    bannerUser();
    bannerSet(2);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);

    $this->travel(11)->minutes();

    $component->call('check')
        ->assertSee('3 cards missing')
        ->assertSee('Retry')
        ->assertDontSeeHtml('wire:poll.5s')
        ->assertDontSee('Loading cards');
});

test('a banner mounted on a set that stopped growing long ago keeps fast polling through a grace period, then stalls', function () {
    Queue::fake();
    bannerUser();
    bannerSet(2);

    $this->travel(11)->minutes();

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05'])
        ->assertSee('Loading cards')
        ->assertSeeHtml('wire:poll.5s')
        ->assertDontSee('Retry');

    $this->travel(SetImportBanner::MOUNT_GRACE_SECONDS - 1)->seconds();
    $component->call('check')->assertSeeHtml('wire:poll.5s')->assertDontSee('Retry');

    $this->travel(1)->seconds();
    $component->call('check')
        ->assertSee('3 cards missing')
        ->assertSee('Retry')
        ->assertDontSeeHtml('wire:poll.5s');

    $component->call('retryImport');
    Queue::assertPushed(ImportSetJob::class, 1);
});

test('a stalled banner keeps a slow fallback poll so a recovering queue is noticed', function () {
    bannerUser();
    bannerSet(2);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);

    $this->travel(11)->minutes();

    $component->call('check')
        ->assertSeeHtml('wire:poll.'.SetImportBanner::STALLED_POLL_SECONDS.'s="check"')
        ->assertDontSeeHtml('wire:poll.5s');
});

test('cards arriving after a stall leave the stalled state, resume fast polling and redraw the grid', function () {
    bannerUser();
    $set = bannerSet(2);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);

    $this->travel(11)->minutes();
    $component->call('check')->assertSee('3 cards missing');

    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Card 3']);

    $component->call('check')
        ->assertDispatched('set-cards-changed')
        ->assertSee('Loading cards…')
        ->assertSeeHtml('<span class="mono">3/5</span>')
        ->assertSeeHtml('wire:poll.5s')
        ->assertDontSee('cards missing')
        ->assertDontSee('Retry');
});

test('a card arriving resets the stall clock', function () {
    bannerUser();
    $set = bannerSet(1);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);

    $this->travel(9)->minutes();
    Card::create(['tcgdex_id' => 'me05-002', 'set_id' => $set->id, 'local_id' => '002', 'name' => 'Card 2']);
    $component->call('check');

    $this->travel(9)->minutes();
    $component->call('check')
        ->assertSee('Loading cards…')
        ->assertSeeHtml('<span class="mono">2/5</span>')
        ->assertDontSee('cards missing');
});

test('the live region is the always-present root and the changing count is not read out', function () {
    bannerUser();
    bannerSet(2);

    Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05'])
        ->assertSeeHtml('role="status" aria-atomic="true"')
        ->assertSeeHtml('<span aria-hidden="true">Loading cards…');
});

test('the live region stays in place when there is nothing to announce', function () {
    bannerUser();
    bannerSet(5);

    Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05'])
        ->assertSeeHtml('role="status" aria-atomic="true"');
});

test('a card arriving tells the grid to redraw once the refresh interval has passed', function () {
    bannerUser();
    $set = bannerSet(1);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05'])
        ->call('check')
        ->assertNotDispatched('set-cards-changed');

    Card::create(['tcgdex_id' => 'me05-002', 'set_id' => $set->id, 'local_id' => '002', 'name' => 'Card 2']);

    $this->travel(SetImportBanner::REFRESH_INTERVAL_SECONDS)->seconds();
    $component->call('check')->assertDispatched('set-cards-changed');
});

test('polls that see a card arrive are coalesced into one redraw per interval while importing', function () {
    bannerUser();
    $set = bannerSet(1);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);

    Card::create(['tcgdex_id' => 'me05-002', 'set_id' => $set->id, 'local_id' => '002', 'name' => 'Card 2']);
    $this->travel(5)->seconds();
    $component->call('check')->assertNotDispatched('set-cards-changed');

    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Card 3']);
    $this->travel(5)->seconds();
    $component->call('check')->assertNotDispatched('set-cards-changed');

    $this->travel(SetImportBanner::REFRESH_INTERVAL_SECONDS)->seconds();
    $component->call('check')->assertDispatched('set-cards-changed');

    // Nothing new since that redraw: the next poll stays quiet.
    $component->call('check')->assertNotDispatched('set-cards-changed');
});

test('the last cards are announced at once when the import completes', function () {
    bannerUser();
    $set = bannerSet(4);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);

    Card::create(['tcgdex_id' => 'me05-005', 'set_id' => $set->id, 'local_id' => '005', 'name' => 'Card 5']);
    $this->travel(2)->seconds();

    $component->call('check')
        ->assertDispatched('set-cards-changed')
        ->assertSee('All cards loaded')
        ->assertDontSeeHtml('wire:poll');
});

test('cards that arrived before a stall are announced when the import stalls', function () {
    bannerUser();
    $set = bannerSet(1);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);

    $this->travel(5)->seconds();
    Card::create(['tcgdex_id' => 'me05-002', 'set_id' => $set->id, 'local_id' => '002', 'name' => 'Card 2']);
    $component->call('check')->assertNotDispatched('set-cards-changed');

    $this->travel(11)->minutes();
    $component->call('check')->assertDispatched('set-cards-changed')->assertSee('3 cards missing');
});

test('a poll with nothing new leaves the grid alone', function () {
    bannerUser();
    bannerSet(2);

    Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05'])
        ->call('check')
        ->assertNotDispatched('set-cards-changed');
});

test('a retry while cards are still arriving queues nothing', function () {
    Queue::fake();
    bannerUser();
    bannerSet(1);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);

    $this->travel(9)->minutes();
    $component->call('retryImport');

    Queue::assertNothingPushed();
});

test('a retry after a stall queues the import once and restarts polling', function () {
    Queue::fake();
    bannerUser();
    bannerSet(1);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);

    $this->travel(11)->minutes();
    $component->call('check')->assertSee('4 cards missing');

    $component->call('retryImport')
        ->assertSee('Loading cards…')
        ->assertSeeHtml('<span class="mono">1/5</span>')
        ->assertSeeHtml('wire:poll.5s');

    Queue::assertPushed(ImportSetJob::class, fn (ImportSetJob $job) => $job->setTcgdexId === 'me05');
    Queue::assertPushed(ImportSetJob::class, 1);

    // The stall clock restarted, so an immediate second press is refused.
    $component->call('retryImport');
    Queue::assertPushed(ImportSetJob::class, 1);
});

test('a second retry inside the stall window is refused and says so', function () {
    Queue::fake();
    bannerUser();
    bannerSet(1);

    // Two open tabs, both showing the stalled banner.
    $first = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);
    $second = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);

    $this->travel(11)->minutes();

    $first->call('retryImport');
    Queue::assertPushed(ImportSetJob::class, 1);

    $second->call('retryImport')->assertSee('Already retried');

    Queue::assertPushed(ImportSetJob::class, 1);
});

test('the retry limit is per set', function () {
    Queue::fake();
    bannerUser();
    bannerSet(1);
    Set::create(['tcgdex_id' => 'me06', 'name' => 'Other', 'card_count' => 4]);

    $first = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);
    $second = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me06']);

    $this->travel(11)->minutes();

    $first->call('retryImport');
    $second->call('retryImport');

    Queue::assertPushed(ImportSetJob::class, 2);
});

test('retry does nothing once the set is complete', function () {
    Queue::fake();
    bannerUser();
    bannerSet(5);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);
    $this->travel(11)->minutes();
    $component->call('retryImport');

    Queue::assertNothingPushed();
});

test('retry does nothing for a set with no known size', function () {
    Queue::fake();
    bannerUser();
    bannerSet(1, null);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);
    $this->travel(11)->minutes();
    $component->call('retryImport');

    Queue::assertNothingPushed();
});

/** Spends $userId's daily import budget on sets other than me05. */
function spendBudget(int $userId): void
{
    for ($i = 1; $i <= ImportBudget::DAILY_LIMIT; $i++) {
        app(ImportBudget::class)->tryStart($userId, "x{$i}");
    }
}

test('a retry is refused once the daily import budget is spent', function () {
    Queue::fake();
    $this->travelTo(now()->startOfDay()->addHours(12));
    $user = bannerUser();
    bannerSet(1);
    spendBudget($user->id);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);
    $this->travel(11)->minutes();

    $component->call('retryImport')->assertSee('Not imported (daily limit of 20 sets, resets at midnight UTC).');

    Queue::assertNothingPushed();
});

test('a retry of a set this user already started today is free', function () {
    Queue::fake();
    $this->travelTo(now()->startOfDay()->addHours(12));
    $user = bannerUser();
    bannerSet(1);
    app(ImportBudget::class)->tryStart($user->id, 'me05');
    for ($i = 1; $i < ImportBudget::DAILY_LIMIT; $i++) {
        app(ImportBudget::class)->tryStart($user->id, "x{$i}");
    }

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);
    $this->travel(11)->minutes();

    $component->call('retryImport');

    Queue::assertPushed(ImportSetJob::class, 1);
});

test('a retry draws from the daily import budget', function () {
    Queue::fake();
    $this->travelTo(now()->startOfDay()->addHours(12));
    $user = bannerUser();
    bannerSet(1);
    for ($i = 1; $i < ImportBudget::DAILY_LIMIT; $i++) {
        app(ImportBudget::class)->tryStart($user->id, "x{$i}");
    }

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);
    $this->travel(11)->minutes();
    $component->call('retryImport');

    Queue::assertPushed(ImportSetJob::class, 1);
    expect(app(ImportBudget::class)->tryStart($user->id, 'other'))->toBe(ImportBudgetResult::Exhausted);
});

test("another user's spent budget does not refuse this user's retry", function () {
    Queue::fake();
    $this->travelTo(now()->startOfDay()->addHours(12));
    bannerUser();
    bannerSet(1);
    spendBudget(User::factory()->create()->id);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);
    $this->travel(11)->minutes();
    $component->call('retryImport');

    Queue::assertPushed(ImportSetJob::class, 1);
});

test('a busy budget refuses the retry and says to try again', function () {
    Queue::fake();
    $user = bannerUser();
    bannerSet(1);
    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);
    $this->travel(11)->minutes();
    // The lock wait sleeps; a fake sleep that moves the clock keeps it
    // finite under frozen test time and instant.
    Sleep::fake(syncWithCarbon: true);
    $lock = Cache::lock('import-budget:lock:'.$user->id, 10);
    $lock->get();

    $component->call('retryImport')->assertSee('Another import is being started — try again.');

    $lock->release();
    Queue::assertNothingPushed();
});

test('more local cards than the announced total is not an import and never goes negative', function () {
    Queue::fake();
    bannerUser();
    bannerSet(6);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05'])
        ->assertDontSee('Loading cards')
        ->assertDontSee('missing')
        ->assertDontSee('All cards loaded')
        ->assertDontSeeHtml('wire:poll');

    $this->travel(11)->minutes();
    $component->call('check')->call('retryImport')->assertDontSee('missing');

    Queue::assertNothingPushed();
});

test('the grid mounts the banner, and the grid itself never polls', function () {
    bannerUser();
    bannerSet(2);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->assertSee('Loading cards…')
        ->assertSeeHtml('<span class="mono">2/5</span>')
        ->assertSeeHtml('nw-import-banner');

    // Only the banner element polls, so a poll never re-renders chips.
    $html = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])->html();
    expect(substr_count($html, 'wire:poll'))->toBe(1);
});

test('a set with no cards yet says they are on their way', function () {
    bannerUser();
    bannerSet(0);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->assertSee('Loading cards…')
        ->assertSeeHtml('<span class="mono">0/5</span>')
        ->assertSee('Cards are on their way')
        ->assertDontSee('No cards match');
});

test('a complete set with no matches keeps the no-match message', function () {
    bannerUser();
    bannerSet(5);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->set('search', 'zzzz')
        ->assertSee('No cards match')
        ->assertDontSee('Cards are on their way');
});

test('the grid refreshes itself only through the client-side idle gate', function () {
    bannerUser();
    bannerSet(2);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->assertSeeHtml('x-on:set-cards-changed.window="refreshWhenIdle()"');
});

test('an import that overshoots the announced total still finishes cleanly', function () {
    bannerUser();
    $set = bannerSet(4);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);

    foreach ([5, 6] as $n) {
        Card::create(['tcgdex_id' => sprintf('me05-%03d', $n), 'set_id' => $set->id, 'local_id' => sprintf('%03d', $n), 'name' => "Card {$n}"]);
    }

    $this->travel(2)->seconds();

    $component->call('check')
        ->assertDispatched('set-cards-changed')
        ->assertSee('All cards loaded')
        ->assertDontSeeHtml('wire:poll');
});

test('a set this user cannot start today says the daily limit is reached instead of loading', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    $user = bannerUser();
    bannerSet(2);
    spendBudget($user->id);

    Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05'])
        ->assertDontSee('Loading cards')
        ->assertSee('3 cards missing — not imported (daily limit of 20 sets, resets at midnight UTC).')
        ->assertDontSee('Retry');
});

test('a set this user already started today keeps loading even with the budget spent', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    $user = bannerUser();
    bannerSet(2);
    app(ImportBudget::class)->tryStart($user->id, 'me05');
    for ($i = 1; $i < ImportBudget::DAILY_LIMIT; $i++) {
        app(ImportBudget::class)->tryStart($user->id, "x{$i}");
    }

    Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05'])
        ->assertSee('Loading cards…')
        ->assertDontSee('daily limit');
});

test('cards arriving for a set this user cannot start show as loading', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    $user = bannerUser();
    $set = bannerSet(2);
    spendBudget($user->id);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);

    $this->travel(1)->minutes();
    Card::create(['tcgdex_id' => 'me05-003', 'set_id' => $set->id, 'local_id' => '003', 'name' => 'Card 3']);

    $component->call('check')
        ->assertSee('Loading cards…')
        ->assertDontSee('daily limit');
});
