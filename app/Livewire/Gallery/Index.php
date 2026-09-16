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

    /**
     * Bounded so a public, unauthenticated route can never fan out
     * unbounded work — used for the collection-wide stat band (total
     * value, rarities list, most valuable card), which reflects the
     * WHOLE collection regardless of filters/pagination, and as the
     * candidate ceiling for "value" sort (see render()'s $sort==='value'
     * branch — a real personal collection is nowhere close to this).
     */
    private const MAX_CARDS = 600;

    /** How many cards load at a time — Carlos chose infinite-scroll
     * (load more on scroll), not page-number pagination, 2026-09-15.
     */
    private const PER_PAGE = 24;

    private const SORTS = ['value', 'newest', 'number', 'name'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'value')]
    public string $sort = 'value';

    #[Url(as: 'set', except: '')]
    public string $setFilter = '';

    #[Url(as: 'rarity', except: '')]
    public string $rarityFilter = '';

    /** How many of the filtered/sorted cards are currently loaded. */
    public int $take = self::PER_PAGE;

    public function mount(string $username): void
    {
        $this->resolveTargetUser($username);
    }

    public function loadMore(): void
    {
        $this->take += self::PER_PAGE;
    }

    public function sortBy(string $sort): void
    {
        if (in_array($sort, self::SORTS, true)) {
            $this->sort = $sort;
            $this->take = self::PER_PAGE;
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'setFilter', 'rarityFilter');
        $this->take = self::PER_PAGE;
    }

    public function updatedSearch(): void
    {
        $this->take = self::PER_PAGE;
    }

    public function updatedSetFilter(): void
    {
        $this->take = self::PER_PAGE;
    }

    public function updatedRarityFilter(): void
    {
        $this->take = self::PER_PAGE;
    }

    public function render()
    {
        $public = $this->publicCollection();
        $resolver = new CardPriceResolver;
        $valuation = new Valuation($resolver);

        // Collection-wide stats (value, copies, rarities, most valuable
        // card) reflect the WHOLE collection, never the grid's current
        // filter/scroll position — same "stats vs grid" split Show.php
        // already uses. Bounded by MAX_CARDS, same as before.
        $allCards = $public->cardsQuery()->take(self::MAX_CARDS)->get();
        $sets = $public->setsQuery()->orderByDesc('released_on')->get();

        $totals = $valuation->totalsByCurrency($allCards);
        $copies = (int) $allCards->sum(fn (Card $card) => $card->collectionItems->sum('quantity'));
        $updatedAt = $allCards->flatMap(fn (Card $c) => $c->priceSnapshots)->max('captured_on');
        $topEntry = $allCards
            ->map(fn (Card $card) => ['card' => $card, 'valueMinor' => $valuation->headlineSnapshot($card)?->market_minor])
            ->sortByDesc(fn ($e) => $e['valueMinor'] ?? -1)
            ->first();
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

        // The grid itself: filters and (for name/newest/number) the sort
        // order are pushed into SQL, so only the cards actually loaded
        // ($this->take of them) ever get mapped through the price
        // resolver — not the whole collection on every keystroke.
        $gridQuery = $public->cardsQuery();

        if ($this->search !== '') {
            // Postgres' LIKE is case-sensitive; ILIKE is the case-insensitive form.
            $gridQuery->where('name', 'ilike', '%'.trim($this->search).'%');
        }
        if ($this->setFilter !== '') {
            $gridQuery->whereHas('set', fn ($q) => $q->where('tcgdex_id', $this->setFilter));
        }
        if ($this->rarityFilter !== '') {
            $gridQuery->where('rarity', $this->rarityFilter);
        }

        $totalEntries = (clone $gridQuery)->count();

        if ($this->sort === 'value') {
            // CardPriceResolver's variant-aware chain can't be expressed
            // as a SQL ORDER BY — resolve a bounded candidate set (a real
            // personal collection is nowhere near MAX_CARDS), sort in
            // memory, then slice the loaded window.
            $candidates = (clone $gridQuery)->take(self::MAX_CARDS)->get()
                ->map(fn (Card $card) => [
                    'card' => $card,
                    'snapshot' => $snapshot = $valuation->headlineSnapshot($card),
                    'delta' => $resolver->deltaFor($card, $snapshot),
                    'items' => $card->collectionItems,
                    'valueMinor' => $snapshot?->market_minor,
                ])
                ->sortByDesc(fn ($e) => $e['valueMinor'] ?? -1)
                ->values();

            $entries = $candidates->take($this->take)->values();
        } else {
            match ($this->sort) {
                'newest' => $gridQuery
                    ->withMax(['collectionItems as added_at' => fn ($q) => $public->scopeItems($q)], 'created_at')
                    ->orderByDesc('added_at'),
                'number' => $gridQuery
                    ->join('sets', 'sets.id', '=', 'cards.set_id')
                    ->orderBy('sets.tcgdex_id')
                    ->orderBy('cards.local_id')
                    ->select('cards.*'),
                default => $gridQuery->orderBy('name'), // 'name'
            };

            $entries = $gridQuery->take($this->take)->get()
                ->map(fn (Card $card) => [
                    'card' => $card,
                    'snapshot' => $snapshot = $valuation->headlineSnapshot($card),
                    'delta' => $resolver->deltaFor($card, $snapshot),
                    'items' => $card->collectionItems,
                    'valueMinor' => $snapshot?->market_minor,
                ]);
        }

        $name = $this->collectorName();

        return view('livewire.gallery.index', [
            'entries' => $entries,
            'totalEntries' => $totalEntries,
            'hasMore' => $entries->count() < $totalEntries,
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
                $allCards->count(),
                $sets->count(),
            ),
            'ogImage' => $topEntry['card']->official_image_url ?? null,
        ]);
    }
}
