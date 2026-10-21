<?php

declare(strict_types=1);

use App\Jobs\WarmValueSeriesJob;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use Illuminate\Bus\UniqueLock;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\RecordingQueue;

/*
 * A change to what a public collection holds queues one delayed warm of the
 * owner's Activity chart, once the change is committed. Anything the chart
 * does not read, and anything in a private collection, queues nothing.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->collection = Collection::factory()->for($this->user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $this->card = Card::create(['tcgdex_id' => 'me05-001', 'set_id' => $set->id, 'local_id' => '001', 'name' => 'Card 1']);
    $this->other = Card::create(['tcgdex_id' => 'me05-002', 'set_id' => $set->id, 'local_id' => '002', 'name' => 'Card 2']);
    $this->item = fn (?Collection $in = null): CollectionItem => CollectionItem::create([
        'collection_id' => ($in ?? $this->collection)->id, 'card_id' => $this->card->id, 'card_tcgdex_id' => $this->card->tcgdex_id,
        'variant' => 'normal', 'condition' => 'NM', 'quantity' => 2,
    ]);
    $this->queue = fn (): RecordingQueue => app('queue')->connection('redis-long');
    // Forget what setting the fixtures up queued, and the unique locks it took.
    $this->settle = function (): void {
        (new UniqueLock(Cache::driver()))->release(new WarmValueSeriesJob($this->user->id));
        ($this->queue)()->pushed = [];
    };
    $this->queued = fn (): array => ($this->queue)()->pushed;
});

test('a new copy in a public collection queues one delayed warm for the owner', function () {
    ($this->item)();

    $queued = ($this->queued)();

    expect($queued)->toHaveCount(1)
        ->and($queued[0]['job'])->toBeInstanceOf(WarmValueSeriesJob::class)
        ->and($queued[0]['job']->userId)->toBe($this->user->id)
        ->and($queued[0]['job']->connection)->toBe('redis-long')
        ->and($queued[0]['delay'])->not->toBeNull();
});

test('the warm is delayed by about a minute', function () {
    ($this->item)();

    $delay = ($this->queued)()[0]['delay'];
    $seconds = $delay instanceof DateTimeInterface ? now()->diffInSeconds($delay, false) : $delay;

    expect($seconds)->toBeGreaterThan(55)->toBeLessThanOrEqual(60);
});

test('a change the chart reads queues one warm', function (Closure $change) {
    $item = ($this->item)();
    ($this->settle)();

    $change($item, $this->other);

    expect(($this->queued)())->toHaveCount(1)
        ->and(($this->queued)()[0]['job']->userId)->toBe($this->user->id);
})->with([
    'quantity' => [fn (CollectionItem $i) => $i->update(['quantity' => 5])],
    'variant' => [fn (CollectionItem $i) => $i->update(['variant' => 'holofoil'])],
    'card' => [fn (CollectionItem $i, Card $other) => $i->update(['card_id' => $other->id, 'card_tcgdex_id' => $other->tcgdex_id])],
    'delete' => [fn (CollectionItem $i) => $i->delete()],
    'increment' => [fn (CollectionItem $i) => $i->increment('quantity')],
    'decrement' => [fn (CollectionItem $i) => $i->decrement('quantity')],
]);

test('an edit the chart does not read queues nothing', function (Closure $change) {
    $item = ($this->item)();
    ($this->settle)();

    $change($item);

    expect(($this->queued)())->toBe([]);
})->with([
    'notes' => [fn (CollectionItem $i) => $i->update(['notes' => 'sleeved'])],
    'photo' => [fn (CollectionItem $i) => $i->update(['photo_path' => 'a/b.jpg'])],
    'condition' => [fn (CollectionItem $i) => $i->update(['condition' => 'LP'])],
    'grade' => [fn (CollectionItem $i) => $i->update(['grade_company' => 'PSA', 'grade_value' => '10'])],
    'a save that changes nothing' => [fn (CollectionItem $i) => $i->save()],
    'the same quantity again' => [fn (CollectionItem $i) => $i->update(['quantity' => 2])],
]);

test('flipping the collection public or private queues one warm; other edits queue nothing', function () {
    ($this->item)();
    ($this->settle)();

    $this->collection->update(['name' => 'Renamed']);
    expect(($this->queued)())->toBe([]);

    $this->collection->update(['is_public' => false]);
    expect(($this->queued)())->toHaveCount(1);

    ($this->settle)();
    $this->collection->update(['is_public' => true]);
    expect(($this->queued)())->toHaveCount(1)
        ->and(($this->queued)()[0]['job']->userId)->toBe($this->user->id);
});

test('changes in a private collection queue nothing', function () {
    $private = Collection::factory()->for($this->user)->create(['is_public' => false, 'slug' => 'hidden']);
    ($this->settle)();

    $item = ($this->item)($private);
    $item->update(['quantity' => 9]);
    $item->increment('quantity');
    $item->delete();

    expect(($this->queued)())->toBe([]);
});

test('edits close together share one queued warm', function () {
    $item = ($this->item)();
    $item->update(['quantity' => 3]);
    $item->increment('quantity');

    expect(($this->queued)())->toHaveCount(1);
});

test('the warm is queued when the surrounding transaction commits, and not at all when it rolls back', function () {
    ($this->settle)();
    $insideCount = null;

    DB::transaction(function () use (&$insideCount) {
        ($this->item)();
        $insideCount = count(($this->queued)());
    });

    expect($insideCount)->toBe(0)
        ->and(($this->queued)())->toHaveCount(1);

    ($this->settle)();

    try {
        DB::transaction(function () {
            CollectionItem::query()->first()->update(['quantity' => 7]);
            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(($this->queued)())->toBe([]);

    // The rolled-back attempt must not leave the owner locked out of the next warm.
    CollectionItem::query()->first()->update(['quantity' => 8]);

    expect(($this->queued)())->toHaveCount(1);
});

test('a queue that fails is reported and the save still succeeds', function () {
    Exceptions::fake();
    $this->app['queue']->addConnector('exploding', fn (): ConnectorInterface => new class implements ConnectorInterface
    {
        public function connect(array $config)
        {
            return new class extends RecordingQueue
            {
                public function later($delay, $job, $data = '', $queue = null)
                {
                    throw new RuntimeException('redis went away');
                }
            };
        }
    });
    config(['queue.connections.redis-long.driver' => 'exploding']);

    $item = ($this->item)();
    $item->update(['quantity' => 4]);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'redis went away');
    expect($item->fresh()->quantity)->toBe(4)
        ->and(CollectionItem::count())->toBe(1);
});
