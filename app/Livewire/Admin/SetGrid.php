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
use App\Modules\Collection\Services\Valuation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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

    /** @return Entry */
    private function entryFor(Card $card, Valuation $valuation): array
    {
        $offered = CardVariants::available($card->variants ?? [], $card->raw ?? []);

        // Every condition and grade of one variant counts toward its chip.
        $perVariant = $card->collectionItems
            ->groupBy(fn (CollectionItem $item): string => (string) $item->variant)
            ->map(fn ($items): int => (int) $items->sum('quantity'));

        $chips = $offered === []
            ? [['variant' => null, 'label' => '', 'count' => (int) ($perVariant[''] ?? 0)]]
            : array_values(array_map(
                fn (string $variant): array => ['variant' => $variant, 'label' => CardVariants::label($variant), 'count' => (int) ($perVariant[$variant] ?? 0)],
                $offered,
            ));

        return [
            'card' => $card,
            'snapshot' => $valuation->headlineSnapshot($card),
            'owned' => $card->collectionItems->isNotEmpty(),
            'quantity' => (int) $card->collectionItems->sum('quantity'),
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
            ->sort(fn (array $a, array $b): int => $primary($a, $b) ?: strcmp(self::numberKey($a), self::numberKey($b)))
            ->values();
    }

    /**
     * Zero-padded so "010" sorts after "002"; a longer id such as "SWSH001"
     * is left as is.
     *
     * @param  Entry  $entry
     */
    private static function numberKey(array $entry): string
    {
        return str_pad($entry['card']->local_id, 5, '0', STR_PAD_LEFT);
    }
}
