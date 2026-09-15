<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Livewire\Gallery\Concerns\ResolvesPublicCollection;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Support\Rarity;
use App\Modules\Collection\Services\Valuation;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The collection itself — every card the collector shows publicly, in
 * one grid, with the stat band the approved "Colección" mockup leads
 * with. This is the front door of a gallery; sets are a way to slice it.
 */
#[Layout('layouts.public')]
final class Index extends Component
{
    use ResolvesPublicCollection;

    /** Bounded so a public, unauthenticated route can never fan out unbounded work. */
    private const MAX_CARDS = 600;

    private const SORTS = ['value', 'newest', 'number', 'name'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'value')]
    public string $sort = 'value';

    #[Url(as: 'set', except: '')]
    public string $setFilter = '';

    #[Url(as: 'rarity', except: '')]
    public string $rarityFilter = '';

    public function mount(string $username): void
    {
        $this->resolveTargetUser($username);
    }

    public function sortBy(string $sort): void
    {
        if (in_array($sort, self::SORTS, true)) {
            $this->sort = $sort;
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'setFilter', 'rarityFilter');
    }

    public function render()
    {
        $public = $this->publicCollection();
        $resolver = new CardPriceResolver;
        $valuation = new Valuation($resolver);

        $allCards = $public->cardsQuery()->take(self::MAX_CARDS)->get();
        $sets = $public->setsQuery()->orderByDesc('released_on')->get();

        $entries = $allCards->map(function (Card $card) use ($resolver, $valuation) {
            $total = $valuation->cardTotal($card);

            return [
                'card' => $card,
                'snapshot' => $resolver->resolve($card),
                'delta' => $resolver->resolveDelta($card),
                'items' => $card->collectionItems,
                'valueMinor' => $total['minor'] ?? null,
                'addedAt' => $card->collectionItems->max('created_at'),
            ];
        });

        $filtered = $entries
            ->when($this->search !== '', fn ($c) => $c->filter(
                fn ($e) => str_contains(mb_strtolower($e['card']->name), mb_strtolower(trim($this->search))),
            ))
            ->when($this->setFilter !== '', fn ($c) => $c->filter(fn ($e) => $e['card']->set?->tcgdex_id === $this->setFilter))
            ->when($this->rarityFilter !== '', fn ($c) => $c->filter(fn ($e) => $e['card']->rarity === $this->rarityFilter));

        $sorted = match ($this->sort) {
            'newest' => $filtered->sortByDesc(fn ($e) => $e['addedAt']?->timestamp ?? 0),
            'number' => $filtered->sortBy(fn ($e) => ($e['card']->set?->tcgdex_id ?? '').'-'.str_pad($e['card']->local_id, 5, '0', STR_PAD_LEFT)),
            'name' => $filtered->sortBy(fn ($e) => $e['card']->name),
            default => $filtered->sortByDesc(fn ($e) => $e['valueMinor'] ?? -1),
        };

        $totals = $valuation->totalsByCurrency($allCards);
        $copies = (int) $allCards->sum(fn (Card $card) => $card->collectionItems->sum('quantity'));
        $updatedAt = $allCards->flatMap(fn (Card $c) => $c->priceSnapshots)->max('captured_on');
        $topEntry = $entries->sortByDesc(fn ($e) => $e['valueMinor'] ?? -1)->first();
        // tcgdex emits the literal string "None" for promos and other
        // unrated prints — plain ->filter() only strips null/'', so
        // "None" survived as a selectable-but-blank-labeled filter
        // option (Rarity::label() already renders it as '', which is
        // what made the dropdown option look empty) and, worse, filtering
        // by it matched only cards with that literal string, hiding
        // every properly-rated card. Use the same "is this a real
        // rarity" definition Rarity::label() already encodes.
        $rarities = $allCards->pluck('rarity')->unique()
            ->filter(fn (?string $rarity) => Rarity::label($rarity) !== '')
            ->sort()->values();

        $name = $this->collectorName();

        return view('livewire.gallery.index', [
            'entries' => $sorted->values(),
            'totalEntries' => $entries->count(),
            'sets' => $sets,
            'totals' => $totals,
            'copies' => $copies,
            'updatedAt' => $updatedAt,
            'topEntry' => $topEntry,
            'rarities' => $rarities,
            'isFiltered' => $this->search !== '' || $this->setFilter !== '' || $this->rarityFilter !== '',
        ])->layoutData([
            'title' => "{$name}'s collection",
            'description' => sprintf(
                '%s Pokémon TCG cards across %s sets, catalogued with live market value.',
                $entries->count(),
                $sets->count(),
            ),
            'ogImage' => $topEntry['card']->official_image_url ?? null,
        ]);
    }
}
