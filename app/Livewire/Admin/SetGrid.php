<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Support\Rarity;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\Valuation;
use Illuminate\Contracts\View\View;
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
 * @phpstan-type Entry array{card: Card, snapshot: ?CardPriceSnapshot, owned: bool, quantity: int}
 */
#[Layout('layouts.app')]
final class SetGrid extends Component
{
    #[Locked]
    public Set $set;

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'rarity', except: '')]
    public string $rarityFilter = '';

    // Missing cards are shown by default: this screen exists to fill gaps.
    #[Url(as: 'hide-missing', except: false)]
    public bool $hideMissing = false;

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

        $entries = $cardsQuery->get()->map(fn (Card $card): array => $this->entryFor($card, $valuation));

        if ($this->hideMissing) {
            $entries = $entries->filter(fn (array $entry): bool => $entry['owned']);
        }

        $entries = $entries->sort(fn (array $a, array $b): int => strcmp(self::numberKey($a), self::numberKey($b)))->values();

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
        return [
            'card' => $card,
            'snapshot' => $valuation->headlineSnapshot($card),
            'owned' => $card->collectionItems->isNotEmpty(),
            'quantity' => (int) $card->collectionItems->sum('quantity'),
        ];
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
