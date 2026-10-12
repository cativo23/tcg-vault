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
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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
     * print), or no variant when the card lists no prints or the owner
     * already holds unspecified copies.
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

        if ($variant !== null && ! CardVariants::isValid($variant)) {
            return ['ok' => false, 'message' => 'That card does not come in that print.'];
        }

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

        RateLimiter::hit($key, 60);

        $collection = Collection::firstOrCreate(
            ['user_id' => auth()->id(), 'slug' => 'my-collection'],
            ['name' => 'My Collection', 'is_public' => false],
        );

        $item = app(CollectionService::class)->addItemForCard($collection, $card, [
            'variant' => $variant,
            'condition' => 'NM',
            'quantity' => 1,
        ]);

        return [
            'ok' => true,
            'itemId' => $item->id,
            'created' => $item->wasRecentlyCreated,
            ...$this->countsFor($card, $variant),
        ];
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
