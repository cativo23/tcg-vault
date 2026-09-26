<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Livewire\Gallery\Concerns\ResolvesPublicCollection;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Support\Rarity;
use App\Modules\Collection\Services\Valuation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One set, every synced card in it: the collector's copies as the
 * default state, the gaps rendered as ghosts so a visitor can see what
 * is still missing without the gaps competing with the collection.
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

    // Ghosts (cards the collector doesn't own) are opt-in, off by default:
    // a set page opens on just the collector's own cards, not the whole
    // official checklist.
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
        // this gallery — 404, not an empty page (spec §7).
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

    public function render(): View
    {
        $public = $this->publicCollection();
        $resolver = new CardPriceResolver;
        $valuation = new Valuation($resolver);

        $cardsQuery = $this->set->cards()
            ->with([
                'set',
                'priceSnapshots',
                'collectionItems' => fn ($q) => $public->scopeItems($q)->orderBy('created_at'),
            ]);

        if ($this->search !== '') {
            // Postgres' LIKE is case-sensitive; ILIKE is the case-insensitive form.
            $cardsQuery->where('name', 'ilike', '%'.trim($this->search).'%');
        }

        if ($this->rarityFilter !== '') {
            $cardsQuery->where('rarity', $this->rarityFilter);
        }

        $allEntries = $cardsQuery->get()->map(function (Card $card) use ($resolver, $valuation) {
            $snapshot = $valuation->headlineSnapshot($card);

            return [
                'card' => $card,
                'snapshot' => $snapshot,
                'delta' => $resolver->deltaFor($card, $snapshot),
                'items' => $card->collectionItems,
                'owned' => $card->collectionItems->isNotEmpty(),
            ];
        });

        $entries = $this->showMissing ? $allEntries : $allEntries->filter(fn ($e) => $e['owned']);

        $sorted = match ($this->sort) {
            'name' => $entries->sortBy(fn ($e) => $e['card']->name),
            'rarity' => $entries->sortBy(fn ($e) => $e['card']->rarity ?? ''),
            'value' => $entries->sortByDesc(fn ($e) => $e['snapshot']?->market_minor ?? -1),
            default => $entries->sortBy(fn ($e) => str_pad($e['card']->local_id, 5, '0', STR_PAD_LEFT)),
        };

        // Owned cards lead within the chosen order for every sort EXCEPT
        // "number": the default numeric sort stays the set's true literal
        // card order (owned and missing interleaved by their real
        // position), not two separate owned-then-missing blocks each
        // individually numeric. The other sort modes (name/rarity/value)
        // keep the "collection is the subject" grouping.
        if ($this->sort !== 'number') {
            $sorted = $sorted->sortByDesc(fn ($e) => $e['owned'] ? 1 : 0, SORT_REGULAR, false)->values();
        } else {
            $sorted = $sorted->values();
        }

        // Stats come from the whole set, unfiltered — the toolbar narrows
        // the grid, not the numbers. When the toolbar has no active
        // filter, $allEntries already IS the whole set (same relations
        // eager-loaded the same way) — reuse it instead of a second
        // identical-shape query. Only re-query when search/rarity have
        // narrowed $cardsQuery away from "the whole set".
        $ownedCards = ($this->search === '' && $this->rarityFilter === '')
            ? $allEntries->filter(fn ($e) => $e['owned'])->pluck('card')
            : $this->set->cards()
                ->whereHas('collectionItems', fn ($q) => $public->scopeItems($q))
                ->with(['priceSnapshots', 'collectionItems' => fn ($q) => $public->scopeItems($q)])
                ->get();

        $ownedTotals = $valuation->totalsByCurrency($ownedCards);
        $mostValuable = $ownedCards
            ->map(fn (Card $card) => ['card' => $card, 'snapshot' => $valuation->headlineSnapshot($card)])
            ->filter(fn ($p) => $p['snapshot'] !== null && $p['snapshot']->market_minor !== null)
            ->sortByDesc(fn ($p) => $p['snapshot']->market_minor)
            ->first();

        $totalCount = $this->set->card_count ?? $this->set->cards()->count();
        // Same tcgdex quirk as Gallery\Index: the literal string "None"
        // (promos/unrated prints) survives whereNotNull() since it isn't
        // actually null — filter it with the same "is this a real
        // rarity" definition Rarity::label() already encodes, or it
        // shows up as a selectable-but-blank filter option that only
        // matches cards literally rated "None".
        $rarities = $this->set->cards()->whereNotNull('rarity')->distinct()->orderBy('rarity')->pluck('rarity')
            ->filter(fn (?string $rarity) => Rarity::label($rarity) !== '')
            ->values();

        $name = $this->collectorName();

        return view('livewire.gallery.show', [
            'entries' => $sorted,
            'ownedCount' => $ownedCards->count(),
            'totalCount' => $totalCount,
            'ownedTotals' => $ownedTotals,
            'mostValuable' => $mostValuable,
            'rarities' => $rarities,
        ])->layoutData([
            'title' => "{$this->set->name} · {$name}'s collection",
            'description' => sprintf(
                '%s of %s cards from %s%s in %s\'s Pokémon TCG collection.',
                $ownedCards->count(),
                $totalCount,
                $this->set->name,
                $this->set->series ? " ({$this->set->series})" : '',
                $name,
            ),
            'ogImage' => $mostValuable['card']->official_image_url ?? $this->set->logo_url,
            'isOwner' => $this->isOwnerViewing(),
        ]);
    }
}
