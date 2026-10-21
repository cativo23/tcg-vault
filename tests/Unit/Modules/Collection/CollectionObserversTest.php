<?php

declare(strict_types=1);

use App\Jobs\WarmValueSeriesJob;
use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\ValueSeriesWarmup;
use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\ArrayStore;
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
        $this->app->forgetScopedInstances();
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

test('a queue that fails is reported, the save still succeeds, and the next edit queues once it works', function () {
    Exceptions::fake();
    $down = new ArrayObject(['on' => true]);
    $this->app['queue']->addConnector('flaky-queue', fn (): ConnectorInterface => new class($down) implements ConnectorInterface
    {
        public function __construct(private readonly ArrayObject $down) {}

        public function connect(array $config)
        {
            return new class($this->down) extends RecordingQueue
            {
                public function __construct(private readonly ArrayObject $down) {}

                public function later($delay, $job, $data = '', $queue = null)
                {
                    if ($this->down['on']) {
                        throw new RuntimeException('redis went away');
                    }

                    return parent::later($delay, $job, $data, $queue);
                }
            };
        }
    });
    config(['queue.connections.redis-long.driver' => 'flaky-queue']);

    $item = ($this->item)();
    $item->update(['quantity' => 4]);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'redis went away');
    expect($item->fresh()->quantity)->toBe(4)
        ->and(CollectionItem::count())->toBe(1);

    // The failed push gave the unique lock back: once the queue works again the next edit queues.
    $down['on'] = false;
    $item->update(['quantity' => 5]);

    expect(($this->queued)())->toHaveCount(1);
});

test('a lock that cannot be acquired is reported and never frees another job\'s lock', function () {
    Exceptions::fake();
    $failing = new ArrayObject(['on' => false]);
    Cache::extend('lock-trouble', fn () => Cache::repository(new class($failing) extends ArrayStore
    {
        public function __construct(private readonly ArrayObject $failing)
        {
            parent::__construct();
        }

        public function lock($name, $seconds = 0, $owner = null)
        {
            // Only the acquire fails; the cleanup that follows it works, as a brief outage would.
            if ($this->failing['on']) {
                $this->failing['on'] = false;

                throw new RuntimeException('lock store went away');
            }

            return parent::lock($name, $seconds, $owner);
        }
    }));
    config(['cache.stores.lock-trouble' => ['driver' => 'lock-trouble'], 'cache.default' => 'lock-trouble']);

    $item = ($this->item)(); // queues the user's warm and holds its lock
    $this->app->forgetScopedInstances(); // a later request
    $failing['on'] = true;
    $item->update(['quantity' => 5]); // cannot take the lock: reported, queues nothing
    $this->app->forgetScopedInstances();
    $item->update(['quantity' => 6]); // the first job's lock must still be held

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'lock store went away');
    expect(($this->queued)())->toHaveCount(1);
});

test('a burst of edits looks the collection up once and queues once', function () {
    $items = [($this->item)()];
    foreach (range(1, 4) as $n) {
        $items[] = CollectionItem::create([
            'collection_id' => $this->collection->id, 'card_id' => $this->card->id, 'card_tcgdex_id' => $this->card->tcgdex_id,
            'variant' => "v{$n}", 'condition' => 'NM', 'quantity' => 1,
        ]);
    }
    ($this->settle)();

    $lookups = 0;
    DB::listen(function ($query) use (&$lookups) {
        $lookups += str_contains($query->sql, 'from "collections"') ? 1 : 0;
    });

    foreach ($items as $i => $item) {
        $item->update(['quantity' => 10 + $i]);
    }

    expect($lookups)->toBe(1)
        ->and(($this->queued)())->toHaveCount(1);
});

test('the burst guard expires with the delay, so a later edit is looked at again', function () {
    $item = ($this->item)();
    ($this->settle)();
    $lookups = 0;
    DB::listen(function ($query) use (&$lookups) {
        $lookups += str_contains($query->sql, 'from "collections"') ? 1 : 0;
    });

    $item->update(['quantity' => 3]);
    $item->update(['quantity' => 4]);
    expect($lookups)->toBe(1);

    $this->travel(ValueSeriesWarmup::DELAY_SECONDS + 1)->seconds();
    $item->update(['quantity' => 5]);

    expect($lookups)->toBe(2);
});

test('a burst inside a transaction that rolls back leaves the next edit free to queue', function () {
    $item = ($this->item)();
    ($this->settle)();

    try {
        DB::transaction(function () use ($item) {
            $item->update(['quantity' => 3]);
            $item->update(['quantity' => 4]);
            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    $item->fresh()->update(['quantity' => 6]);

    expect(($this->queued)())->toHaveCount(1);
});

test('moving a copy between collections warms its owner when either the old or the new side is public', function () {
    $item = ($this->item)();
    $private = Collection::factory()->for($this->user)->create(['is_public' => false, 'slug' => 'hidden']);
    ($this->settle)();

    $item->update(['collection_id' => $private->id]);
    expect(($this->queued)())->toHaveCount(1);

    ($this->settle)();
    $item->update(['collection_id' => $this->collection->id]);
    expect(($this->queued)())->toHaveCount(1);
});
