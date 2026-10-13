<?php

declare(strict_types=1);

use App\Jobs\ImportSetJob;
use App\Livewire\Admin\SetGrid;
use App\Livewire\Admin\SetImportBanner;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
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
        ->assertSee('Loading cards… 2/5')
        ->assertSeeHtml('role="status"')
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

test('after ten minutes with no new card the banner stops polling and offers a retry', function () {
    bannerUser();
    bannerSet(2);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);

    $this->travel(11)->minutes();

    $component->call('check')
        ->assertSee('3 cards missing')
        ->assertSee('Retry')
        ->assertDontSeeHtml('wire:poll')
        ->assertDontSee('Loading cards');
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
        ->assertSee('Loading cards… 2/5')
        ->assertDontSee('cards missing');
});

test('a card arriving tells the grid to redraw', function () {
    bannerUser();
    $set = bannerSet(1);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05'])
        ->call('check')
        ->assertNotDispatched('set-cards-changed');

    Card::create(['tcgdex_id' => 'me05-002', 'set_id' => $set->id, 'local_id' => '002', 'name' => 'Card 2']);

    $component->call('check')->assertDispatched('set-cards-changed');
});

test('a poll with nothing new leaves the grid alone', function () {
    bannerUser();
    bannerSet(2);

    Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05'])
        ->call('check')
        ->assertNotDispatched('set-cards-changed');
});

test('retry dispatches the import again and restarts polling', function () {
    Queue::fake();
    bannerUser();
    bannerSet(1);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);

    $this->travel(11)->minutes();
    $component->call('check')->assertSee('4 cards missing');

    $component->call('retryImport')
        ->assertSee('Loading cards… 1/5')
        ->assertSeeHtml('wire:poll.5s');

    Queue::assertPushed(ImportSetJob::class, fn (ImportSetJob $job) => $job->setTcgdexId === 'me05');
});

test('retry does nothing once the set is complete', function () {
    Queue::fake();
    bannerUser();
    bannerSet(5);

    Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05'])->call('retryImport');

    Queue::assertNothingPushed();
});

test('retry does nothing for a set with no known size', function () {
    Queue::fake();
    bannerUser();
    bannerSet(1, null);

    Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05'])->call('retryImport');

    Queue::assertNothingPushed();
});

test('retry is limited to a few presses a minute per user', function () {
    Queue::fake();
    $user = bannerUser();
    bannerSet(1);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);

    foreach (range(1, 5) as $press) {
        $component->call('retryImport');
    }

    // The import is unique, so the fake queue keeps one push; the limiter
    // shows how many presses actually went through.
    expect(RateLimiter::attempts('import-retry:'.$user->id))->toBe(SetImportBanner::RETRY_LIMIT_PER_MINUTE);

    $this->travel(61)->seconds();
    $component->call('retryImport');

    expect(RateLimiter::attempts('import-retry:'.$user->id))->toBe(1);
});

test('a rate-limited retry says so instead of failing silently', function () {
    Queue::fake();
    bannerUser();
    bannerSet(1);

    $component = Livewire::test(SetImportBanner::class, ['setTcgdexId' => 'me05']);

    foreach (range(1, SetImportBanner::RETRY_LIMIT_PER_MINUTE + 1) as $press) {
        $component->call('retryImport');
    }

    $component->assertSee('Already retrying');
});

test('the grid mounts the banner, and the grid itself never polls', function () {
    bannerUser();
    bannerSet(2);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->assertSee('Loading cards… 2/5')
        ->assertSeeHtml('nw-import-banner');

    // Only the banner element polls, so a poll never re-renders chips.
    $html = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])->html();
    expect(substr_count($html, 'wire:poll'))->toBe(1);
});

test('a set with no cards yet says they are on their way', function () {
    bannerUser();
    bannerSet(0);

    Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])
        ->assertSee('Loading cards… 0/5')
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

test('the grid redraws when the banner reports new cards', function () {
    bannerUser();
    $set = bannerSet(1);

    $component = Livewire::test(SetGrid::class, ['setTcgdexId' => 'me05'])->assertDontSee('Card 2');

    Card::create(['tcgdex_id' => 'me05-002', 'set_id' => $set->id, 'local_id' => '002', 'name' => 'Card 2']);

    $component->dispatch('set-cards-changed')->assertSee('Card 2');
});
