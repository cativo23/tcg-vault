<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Livewire\Gallery\Concerns\ResolvesPublicCollection;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Catalog\Support\Completion;
use App\Modules\Catalog\Support\PrintedStrip;
use App\Modules\Catalog\Support\Rarity;
use App\Modules\Catalog\Support\StripScale;
use App\Modules\Collection\Services\Valuation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One set, every synced card in it: the collector's copies as the default
 * state, the gaps as empty pockets a visitor can switch on to see what is
 * still missing without the gaps competing with the collection.
 */
#[Layout('layouts.public')]
final class Show extends Component
{
    use ResolvesPublicCollection;

    private const SORTS = ['number', 'value', 'name', 'rarity'];

    public Set $set;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'number')]
    public string $sort = 'number';

    #[Url(as: 'rarity', except: '')]
    public string $rarityFilter = '';

    // Missing cards are opt-in, off by default: a set page opens on just
    // the collector's own cards, not the whole official checklist.
    #[Url(as: 'missing', except: false)]
    public bool $showMissing = false;

    public function toggleMissing(): void
    {
        $this->showMissing = ! $this->showMissing;
    }

    public function mount(string $username, string $setTcgdexId): void
    {
        $this->resolveTargetUser($username);

        $set = Set::where('tcgdex_id', $setTcgdexId)->first();

        // A set the collector has no public card from does not exist in
        // this gallery — 404, not an empty page.
        if ($set === null || ! $this->publicCollection()->ownsSet($set)) {
            throw new NotFoundHttpException;
        }

        $this->set = $set;
    }

    public function sortBy(string $sort): void
    {
        if (in_array($sort, self::SORTS, true)) {
            $this->sort = $sort;
        }
    }

    /** Clear drops what narrows the grid; the order and the missing toggle stay. */
    public function clearFilters(): void
    {
        $this->reset('search', 'rarityFilter');
    }

    public function render(): View
    {
        $public = $this->publicCollection();
        $resolver = new CardPriceResolver;
        $valuation = new Valuation($resolver);

        // Every card of the set, loaded once with the price rows that decide
        // its current price (see DecidingPriceRows): the strip, the counts
        // and the owned value cover the whole set, and the grid is a slice
        // of these same cards, so no filter loads them a second time.
        $cards = app(DecidingPriceRows::class)->loadOnto($this->set->cards()
            ->with([
                'set',
                'collectionItems' => fn ($q) => $public->scopeItems($q)->orderBy('created_at'),
            ])
            ->get());
        $byNumber = $cards->sortBy(fn (Card $card) => self::numberKey($card))->values();
        $owns = fn (Card $card): bool => $card->collectionItems->isNotEmpty();
        $ownedCards = $byNumber->filter($owns)->values();

        // Search and rarity only choose which of those cards the grid shows.
        $matching = null;
        if ($this->search !== '' || $this->rarityFilter !== '') {
            $query = $this->set->cards();
            if ($this->search !== '') {
                // Postgres' LIKE is case-sensitive; ILIKE is the case-insensitive form.
                $query->where('name', 'ilike', '%'.trim($this->search).'%');
            }
            if ($this->rarityFilter !== '') {
                $query->where('rarity', $this->rarityFilter);
            }
            $matching = $query->pluck('id')->flip();
        }

        $entries = $byNumber
            ->filter(fn (Card $card) => ($this->showMissing || $owns($card)) && ($matching === null || $matching->has($card->id)))
            ->map(function (Card $card) use ($resolver, $valuation, $owns) {
                $snapshot = $valuation->headlineSnapshot($card);
                $owned = $owns($card);

                return [
                    'card' => $card,
                    'snapshot' => $snapshot,
                    'delta' => $resolver->deltaFor($card, $snapshot),
                    // A missing card's pocket shows its price, never a move.
                    'weekDelta' => $owned ? $resolver->weekDeltaFor($card, $snapshot) : null,
                    'items' => $card->collectionItems,
                    'owned' => $owned,
                ];
            });

        $sorted = match ($this->sort) {
            'name' => $entries->sortBy(fn ($e) => $e['card']->name),
            'rarity' => $entries->sortBy(fn ($e) => $e['card']->rarity ?? ''),
            'value' => $entries->sortByDesc(fn ($e) => $e['snapshot']->market_minor ?? -1),
            default => $entries, // already in the set's number order
        };

        // Owned cards lead within the chosen order for every sort EXCEPT
        // "number", which stays the set's true order: owned and missing
        // interleaved by their real position.
        if ($this->sort !== 'number') {
            $sorted = $sorted->sortByDesc(fn (array $e): int => (int) $e['owned']);
        }
        $sorted = $sorted->values();

        $ownedCount = $ownedCards->count();
        $ownedTotals = $valuation->totalsByCurrency($ownedCards);
        $mostValuable = $ownedCards
            ->map(fn (Card $card) => ['card' => $card, 'snapshot' => $valuation->headlineSnapshot($card)])
            ->filter(fn ($p) => $p['snapshot'] !== null && $p['snapshot']->market_minor !== null)
            ->sortByDesc(fn ($p) => $p['snapshot']->market_minor)
            ->first();

        $totalCount = $this->set->card_count ?: $cards->count();
        // The completion figure is the sets list's too: it never rounds a real, nonzero owned count down to 0%.
        $percent = Completion::percent($ownedCount, $totalCount);
        $strip = $this->strip($byNumber, $totalCount);

        // tcgdex's literal "None" (promos, unrated prints) is not a rarity
        // anyone can pick; the rest follow the printed ladder.
        $rarities = $cards->pluck('rarity')->unique()
            ->filter(fn (?string $rarity) => Rarity::label($rarity) !== '')
            ->sort(fn (string $a, string $b) => Rarity::rank($a) <=> Rarity::rank($b) ?: strcmp($a, $b))
            ->values();

        // The two priciest cards take a double pocket, but only in the order that puts them first and not in a search.
        // Only owned tiles count: a pocket never takes a lead, so the missing toggle cannot change an owned tile's size.
        $leadCount = $this->sort === 'value' && trim($this->search) === '' && $sorted->where('owned', true)->count() > 2 ? 2 : 0;

        $name = $this->collectorName();

        return view('livewire.gallery.show', [
            'entries' => $sorted,
            'ownedCount' => $ownedCount,
            'totalCount' => $totalCount,
            'ownedTotals' => $ownedTotals,
            'mostValuable' => $mostValuable,
            'rarities' => $rarities,
            // The pockets the toggle can reveal: imported cards the collector does not own. Cards the
            // catalogue has not imported are never pockets; they are the strip's absent slots.
            'missingCount' => $cards->count() - $ownedCount,
            'percent' => $percent,
            'strip' => $strip,
            'ticks' => StripScale::ticks(count($strip)),
            'leadCount' => $leadCount,
            'setCode' => $this->set->display_code,
            'isFiltered' => $this->search !== '' || $this->rarityFilter !== '',
            'canEditSet' => $this->isOwnerViewing() && auth()->user()?->can('use-collection') === true,
        ])->layoutData([
            'title' => "{$this->set->name} · {$name}'s collection",
            'description' => sprintf(
                '%s of %s cards from %s%s in %s\'s Pokémon TCG collection.',
                $ownedCount,
                $totalCount,
                $this->set->name,
                $this->set->series ? " ({$this->set->series})" : '',
                $name,
            ),
            'ogImage' => $mostValuable['card']->official_image_url ?? $this->set->safe_logo_url,
            'isOwner' => $this->isOwnerViewing(),
        ]);
    }

    /**
     * The printed strip's slots, from the rule shared with the sets list (`PrintedStrip`): numbered cards at
     * their number, lettered ones after, every position the catalogue has not imported an absent slot.
     * The one place this page calls it.
     *
     * @param  Collection<int, Card>  $byNumber  every imported card of the set, collectionItems loaded
     * @return list<array{number: ?string, state: 'owned'|'missing'|'absent'}>
     */
    private function strip(Collection $byNumber, int $total): array
    {
        $imported = $byNumber->map(fn (Card $card) => [
            'number' => (string) $card->local_id,
            'owned' => $card->collectionItems->isNotEmpty(),
        ])->all();

        return PrintedStrip::slots($imported, $total);
    }

    /** The set's own order: numbers compare zero-padded, so "3" sorts before "116". */
    private static function numberKey(Card $card): string
    {
        return str_pad((string) $card->local_id, 5, '0', STR_PAD_LEFT);
    }
}
