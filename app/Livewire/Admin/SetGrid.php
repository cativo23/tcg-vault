<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Support\CardVariants;
use App\Modules\Catalog\Support\Rarity;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\CollectionService;
use App\Modules\Collection\Services\Valuation;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Every local card of one set for the signed-in owner: what they have,
 * and the gaps. Copies are read through Collection's TenantScope, so a
 * private collection works and another user's copies never show.
 *
 * @phpstan-type Entry array{card: Card, snapshot: ?CardPriceSnapshot, owned: bool, quantity: int, chips: list<array{variant: ?string, label: string, count: int}>}
 */
#[Layout('layouts.app')]
final class SetGrid extends Component
{
    private const SORTS = ['number', 'value', 'name', 'rarity'];

    public const QUICK_ADD_LIMIT_PER_MINUTE = 120;

    // How long a quick add stays undoable; a little longer than the toast.
    public const UNDO_WINDOW_SECONDS = 30;

    // How long a tap waits for the per-card lock; lowered by tests that hold it.
    public static int $lockWaitSeconds = 2;

    private const BUSY = ['ok' => false, 'message' => 'Busy, try again.'];

    #[Locked]
    public Set $set;

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'rarity', except: '')]
    public string $rarityFilter = '';

    // Missing cards are shown by default: this screen exists to fill gaps.
    #[Url(as: 'hide-missing', except: false)]
    public bool $hideMissing = false;

    #[Url(except: 'number')]
    public string $sort = 'number';

    public function mount(string $setTcgdexId): void
    {
        $set = Set::where('tcgdex_id', $setTcgdexId)->first();

        if ($set === null) {
            throw new NotFoundHttpException;
        }

        $this->set = $set;
    }

    public function toggleMissing(): void
    {
        $this->hideMissing = ! $this->hideMissing;
    }

    /**
     * Receiving the editor's event is the whole job: Livewire re-renders
     * the grid after any action, so chips and tile counts reflect the edit.
     */
    #[On('card-copies-changed')]
    public function refreshAfterCardChange(): void {}

    public function sortBy(string $sort): void
    {
        if (in_array($sort, self::SORTS, true)) {
            $this->sort = $sort;
        }
    }

    /**
     * Adds one Near Mint, ungraded copy of $variant and reports what the
     * database now holds, so the page only ever shows a +1 the server
     * confirmed. The card, the variant and the collection are all
     * re-resolved here; nothing the browser sends is trusted.
     *
     * Accepts any variant the tile shows a chip for: a print the card
     * offers, a stored key the owner already holds (a hand-entered custom
     * print, even one whose key is not a well-formed variant), or no
     * variant when the card lists no prints or the owner already holds
     * unspecified copies. Anything else is refused.
     *
     * @return array{ok: bool, message?: string, itemId?: int, created?: bool, variantCount?: int, cardTotal?: int}
     */
    #[Renderless]
    public function quickAdd(int $cardId, ?string $variant = null): array
    {
        $key = 'quick-add:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, self::QUICK_ADD_LIMIT_PER_MINUTE)) {
            return ['ok' => false, 'message' => 'Slow down: too many adds in a minute.'];
        }

        $card = $this->set->cards()->whereKey($cardId)->first();

        if ($card === null) {
            return ['ok' => false, 'message' => 'That card is not in this set.'];
        }

        $variant = CollectionService::nullIfEmpty($variant);

        $offered = CardVariants::available($card->variants ?? [], $card->raw ?? []);
        $held = CollectionItem::query()
            ->whereIn('collection_id', Collection::query()->pluck('id'))
            ->where('card_id', $card->id)
            ->pluck('variant')
            ->map(fn (?string $held): ?string => CollectionService::nullIfEmpty($held))
            ->all();

        $allowed = $variant === null
            ? $offered === [] || in_array(null, $held, true)
            : in_array($variant, $offered, true) || in_array($variant, $held, true);

        if (! $allowed) {
            return ['ok' => false, 'message' => 'That card does not come in that print.'];
        }

        // The add and its ledger entry happen under one lock, so an undo of
        // the same card never sees the new copy without its pending unit.
        // The collection and the rate-limit hit are part of the locked work:
        // a busy answer creates nothing and costs no attempt. The limit is
        // checked before the lock, so requests already waiting on it can
        // each land one add past the limit.
        $item = $this->withCardLock($card->id, function () use ($card, $variant, $key): CollectionItem {
            $collection = Collection::firstOrCreate(
                ['user_id' => auth()->id(), 'slug' => 'my-collection'],
                ['name' => 'My Collection', 'is_public' => false],
            );

            $item = app(CollectionService::class)->addItemForCard($collection, $card, [
                'variant' => $variant,
                'condition' => 'NM',
                'quantity' => 1,
            ]);

            $ledger = Cache::get($this->ledgerKey($item->id));

            Cache::put($this->ledgerKey($item->id), [
                'pending' => (is_array($ledger) ? (int) $ledger['pending'] : 0) + 1,
                'created' => $item->wasRecentlyCreated || (is_array($ledger) && $ledger['created'] === true),
            ], self::UNDO_WINDOW_SECONDS);

            RateLimiter::hit($key, 60);

            return $item;
        });

        if ($item === null) {
            return self::BUSY;
        }

        return [
            'ok' => true,
            'itemId' => $item->id,
            'created' => $item->wasRecentlyCreated,
            ...$this->countsFor($card, $variant),
        ];
    }

    /**
     * The undo ledger of one row: how many quick-add taps are still
     * undoable and whether a tap created the row. One cache value, rewritten
     * with a fresh window on every tap and undo, so the two never expire
     * apart.
     */
    private function ledgerKey(int $itemId): string
    {
        return 'quick-add:ledger:'.auth()->id().':'.$itemId;
    }

    /**
     * Runs $callback under a per-user, per-card lock shared by quickAdd and
     * undoQuickAdd. The lock is per card because quickAdd only learns the
     * row's id once the add has merged or created it, and every row an add
     * can touch belongs to that card. Returns null, with $callback not run,
     * when the lock cannot be taken in time.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function withCardLock(int $cardId, callable $callback): mixed
    {
        try {
            return Cache::lock('quick-add:lock:'.auth()->id().':'.$cardId, 5)->block(self::$lockWaitSeconds, $callback);
        } catch (LockTimeoutException) {
            return null;
        }
    }

    /**
     * Takes back one copy a quick add made. The server's ledger decides
     * what is undoable: each tap leaves one pending unit for its row, and
     * an undo consumes one. Only a plain Near Mint, ungraded copy without
     * photo or notes qualifies; anything edited since belongs to the
     * panel. A row is deleted only when a tap created it and its last
     * pending unit is the one being undone; otherwise it keeps at least
     * the copies it had.
     *
     * @return array{ok: bool, message?: string, variantCount?: int, cardTotal?: int}
     */
    #[Renderless]
    public function undoQuickAdd(int $itemId): array
    {
        $key = 'quick-undo:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, self::QUICK_ADD_LIMIT_PER_MINUTE)) {
            return ['ok' => false, 'message' => 'Slow down: too many undos in a minute.'];
        }

        $found = CollectionItem::query()
            ->whereIn('collection_id', Collection::query()->pluck('id'))
            ->whereIn('card_id', Card::query()->where('set_id', $this->set->id)->select('id'))
            ->find($itemId);

        if ($found === null) {
            RateLimiter::hit($key, 60);

            return ['ok' => false, 'message' => 'That copy is no longer in your collection.'];
        }

        // The decision is made on a row re-read under the lock and the
        // database row lock, so a tap that merged in meanwhile is counted.
        // The ledger is written after the transaction commits, so a failed
        // commit consumes no unit, and before the lock is released, so the
        // next tap or undo of this card reads the updated ledger. A busy
        // answer costs no attempt.
        $result = $this->withCardLock($found->card_id, function () use ($itemId, $key): array {
            RateLimiter::hit($key, 60);

            $outcome = $this->decideUndo($itemId);
            $ledgerKey = $this->ledgerKey($itemId);

            if ($outcome['ledger'] === 'forget') {
                Cache::forget($ledgerKey);
            } elseif (is_array($outcome['ledger'])) {
                Cache::put($ledgerKey, $outcome['ledger'], self::UNDO_WINDOW_SECONDS);
            }

            return $outcome['result'];
        });

        return $result ?? self::BUSY;
    }

    /**
     * Applies one undo inside a transaction and says what the ledger should
     * become, without touching the cache.
     *
     * @return array{result: array{ok: bool, message?: string, variantCount?: int, cardTotal?: int}, ledger: 'forget'|array{pending: int, created: bool}|null}
     */
    private function decideUndo(int $itemId): array
    {
        return DB::transaction(function () use ($itemId): array {
            $item = CollectionItem::query()
                ->whereIn('collection_id', Collection::query()->pluck('id'))
                ->with('card')
                ->lockForUpdate()
                ->find($itemId);

            $refuse = fn (string $message): array => ['result' => ['ok' => false, 'message' => $message], 'ledger' => null];

            if ($item === null) {
                return $refuse('That copy is no longer in your collection.');
            }

            if ($item->photo_path !== null || filled($item->notes)) {
                return $refuse('That copy has a photo or notes: edit it in the panel.');
            }

            if ($item->condition !== 'NM' || filled($item->grade_company) || filled($item->grade_value)) {
                return $refuse('That copy was edited since: use the panel.');
            }

            $ledger = Cache::get($this->ledgerKey($item->id));
            $pending = is_array($ledger) ? (int) $ledger['pending'] : 0;
            $created = is_array($ledger) && $ledger['created'] === true;

            if ($pending < 1) {
                return $refuse('Nothing to undo.');
            }

            if ($item->quantity > 1) {
                $item->decrement('quantity');
            } elseif ($pending === 1 && $created) {
                $item->delete();
            } else {
                return $refuse('Nothing to undo.');
            }

            return [
                'result' => ['ok' => true, ...$this->countsFor($item->card, $item->variant)],
                'ledger' => $pending === 1 ? 'forget' : ['pending' => $pending - 1, 'created' => $created],
            ];
        });
    }

    /**
     * What the owner holds of one card across their collections: the total
     * of one variant (null and '' both mean no variant) and the total of
     * the whole card.
     *
     * @return array{variantCount: int, cardTotal: int}
     */
    private function countsFor(Card $card, ?string $variant): array
    {
        $variant = CollectionService::nullIfEmpty($variant);

        $items = CollectionItem::query()
            ->whereIn('collection_id', Collection::query()->pluck('id'))
            ->where('card_id', $card->id)
            ->get(['variant', 'quantity']);

        return [
            'variantCount' => (int) $items->filter(fn (CollectionItem $item): bool => CollectionService::nullIfEmpty($item->variant) === $variant)->sum('quantity'),
            'cardTotal' => (int) $items->sum('quantity'),
        ];
    }

    public function render(): View
    {
        $collectionIds = Collection::query()->pluck('id');
        $valuation = new Valuation(new CardPriceResolver);

        $cardsQuery = $this->set->cards()->with([
            'priceSnapshots' => fn ($q) => $q->recent(),
            'collectionItems' => fn ($q) => $q->whereIn('collection_id', $collectionIds)->orderBy('created_at'),
        ]);

        if ($this->search !== '') {
            // Postgres' LIKE is case-sensitive; ILIKE is the case-insensitive form.
            $cardsQuery->where('name', 'ilike', '%'.trim($this->search).'%');
        }

        if ($this->rarityFilter !== '') {
            $cardsQuery->where('rarity', $this->rarityFilter);
        }

        $entries = $cardsQuery->get()->toBase()->map(fn (Card $card): array => $this->entryFor($card, $valuation));

        if ($this->hideMissing) {
            /** @var SupportCollection<int, Entry> $entries */
            $entries = $entries->filter(fn (array $entry): bool => $entry['owned']);
        }

        $entries = $this->sortEntries($entries);

        // The stat band describes the whole set, not what the toolbar narrowed to.
        $ownedCount = CollectionItem::query()
            ->whereIn('collection_id', $collectionIds)
            ->whereIn('card_id', Card::query()->where('set_id', $this->set->id)->select('id'))
            ->distinct()
            ->count('card_id');

        $totalCount = $this->set->card_count ?: $this->set->cards()->count();

        // tcgdex files the literal string "None" under rarity for unrated
        // prints; Rarity::label() already encodes what counts as a real one.
        $rarities = $this->set->cards()->whereNotNull('rarity')->distinct()->orderBy('rarity')->pluck('rarity')
            ->filter(fn (?string $rarity) => Rarity::label($rarity) !== '')
            ->values();

        return view('livewire.admin.set-grid', [
            'entries' => $entries,
            'ownedCount' => $ownedCount,
            'totalCount' => $totalCount,
            'rarities' => $rarities,
        ]);
    }

    /**
     * Chips and the tile total come from the same pass over the owned copies,
     * so they cannot disagree: every owned variant gets a chip, whether or not
     * the card's catalog data lists it. Every condition and grade of one
     * variant counts toward its chip.
     *
     * @return Entry
     */
    private function entryFor(Card $card, Valuation $valuation): array
    {
        $offered = CardVariants::available($card->variants ?? [], $card->raw ?? []);

        $perVariant = $card->collectionItems
            ->groupBy(fn (CollectionItem $item): string => (string) $item->variant)
            ->map(fn ($items): int => (int) $items->sum('quantity'));

        $unspecified = (int) ($perVariant[''] ?? 0);

        $chips = [];

        // A card with no known prints keeps one plain "+1" chip for copies
        // without a variant; on a card with prints, those copies only get a
        // chip once they exist.
        if ($offered === []) {
            $chips[] = ['variant' => null, 'label' => '', 'count' => $unspecified];
        }

        foreach ($offered as $variant) {
            $chips[] = ['variant' => $variant, 'label' => CardVariants::label($variant), 'count' => (int) ($perVariant[$variant] ?? 0)];
        }

        // Owned prints the card's data does not list (hand-entered custom prints).
        $extras = $perVariant->except(array_merge($offered, ['']))->sortKeys();

        foreach ($extras as $variant => $count) {
            $chips[] = ['variant' => (string) $variant, 'label' => CardVariants::label((string) $variant), 'count' => $count];
        }

        if ($offered !== [] && $unspecified > 0) {
            $chips[] = ['variant' => null, 'label' => 'Unspecified', 'count' => $unspecified];
        }

        return [
            'card' => $card,
            'snapshot' => $valuation->headlineSnapshot($card),
            'owned' => $card->collectionItems->isNotEmpty(),
            'quantity' => array_sum(array_column($chips, 'count')),
            'chips' => $chips,
        ];
    }

    /**
     * Every sort orders the whole set: owned and missing cards interleave
     * by the chosen key and never split into an owned block and a missing
     * block. Ties fall back to card number so the order is stable. Value
     * compares raw minor units, the way Gallery\Show orders them.
     *
     * @param  SupportCollection<int, Entry>  $entries
     * @return SupportCollection<int, Entry>
     */
    private function sortEntries(SupportCollection $entries): SupportCollection
    {
        $primary = match ($this->sort) {
            'name' => fn (array $a, array $b): int => strcasecmp($a['card']->name, $b['card']->name),
            'rarity' => fn (array $a, array $b): int => strcmp($a['card']->rarity ?? '', $b['card']->rarity ?? ''),
            'value' => fn (array $a, array $b): int => ($b['snapshot']->market_minor ?? -1) <=> ($a['snapshot']->market_minor ?? -1),
            default => fn (array $a, array $b): int => 0,
        };

        return $entries
            ->sort(fn (array $a, array $b): int => $primary($a, $b) ?: self::compareNumbers($a, $b))
            ->values();
    }

    /**
     * Natural, case-insensitive order of the printed card number, so "2"
     * precedes "10" and "TG2" precedes "TG10".
     *
     * @param  Entry  $a
     * @param  Entry  $b
     */
    private static function compareNumbers(array $a, array $b): int
    {
        return strnatcasecmp($a['card']->local_id, $b['card']->local_id);
    }
}
