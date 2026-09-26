<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Livewire\Gallery\Concerns\ResolvesPublicCollection;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\Valuation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * What changed: price moves since the previous snapshot and cards added,
 * as one chronological feed, under the collection's value over time.
 */
#[Layout('layouts.public')]
final class Activity extends Component
{
    use ResolvesPublicCollection;

    /** Bounded: a public route must never fan out unbounded per-card work. */
    private const MAX_CARDS = 500;

    private const MAX_FEED = 40;

    private const MAX_SERIES_DAYS = 30;

    public function mount(string $username): void
    {
        $this->resolveTargetUser($username);
    }

    public function render(): View
    {
        $public = $this->publicCollection();
        $resolver = new CardPriceResolver;
        $valuation = new Valuation($resolver);

        $cards = $public->cardsQuery()->take(self::MAX_CARDS)->get();

        $moves = $cards
            ->map(function (Card $card) use ($resolver, $valuation) {
                // deltaFor() against the owned headline variant, not
                // resolveDelta(): the latter runs the card-level chain,
                // so a collector who owns only the reverse-holofoil
                // print would be shown the normal print's movement — a
                // trend for a card they do not have.
                $delta = $resolver->deltaFor($card, $valuation->headlineSnapshot($card));

                if ($delta === null || $delta->deltaMinor === 0) {
                    return null;
                }

                return [
                    'kind' => 'move',
                    'card' => $card,
                    'delta' => $delta,
                    'at' => $delta->latest->captured_on,
                ];
            })
            ->filter();

        $additions = $public->itemsQuery()
            // priceSnapshots eager-loaded here, not `load()`ed per row
            // inside the map: resolveForVariant() reads the relation as a
            // property, so a per-row load would mean one query per feed
            // entry on a public route.
            ->with(['card.set', 'card.priceSnapshots'])
            ->take(self::MAX_FEED)
            ->get()
            ->map(fn (CollectionItem $item) => [
                'kind' => 'added',
                'card' => $item->card,
                'item' => $item,
                // resolveForVariant, not resolve(): the row next to this
                // price names the variant the copy actually is, and
                // resolve()'s card-level chain only ever considers
                // tcgplayer normal/holofoil — so a "Reverse Holofoil"
                // entry would carry the normal print's price, which is a
                // different card's value.
                //
                // resolveForVariant() matches the variant EXACTLY and
                // returns null when nothing does — which a card priced
                // only under cardmarket 'default' hits for any copy
                // saved as 'normal'. resolveCardWide(), not resolve(),
                // backstops that: it returns only the variant-agnostic
                // 'default' row, so the fallback can never put another
                // print's price under this row's label. No card-wide
                // price means no price shown.
                'snapshot' => $item->card
                    ? $resolver->resolveForVariant($item->card, $item->variant) ?? $resolver->resolveCardWide($item->card)
                    : null,
                'at' => $item->created_at,
            ]);

        $feed = $moves->concat($additions)
            ->sortByDesc(fn ($e) => $e['at']->timestamp)
            ->take(self::MAX_FEED)
            ->values();

        $totals = $valuation->totalsByCurrency($cards);
        $primaryCurrency = array_key_first($totals);
        $series = $primaryCurrency ? $this->valueSeries($cards, $primaryCurrency, $resolver, $valuation) : collect();

        $name = $this->collectorName();

        return view('livewire.gallery.activity', [
            'feed' => $feed,
            // Deliberately the feed's own count, not $moves->count() — an
            // item is always in $feed as an "added" entry regardless of
            // whether its price has ever moved, so a header counting only
            // moves could read "0 price moves" directly above a feed
            // showing real "Added <card>" entries. This is what's
            // actually listed below.
            'feedCount' => $feed->count(),
            'totals' => $totals,
            'series' => $series,
            'primaryCurrency' => $primaryCurrency,
        ])->layoutData([
            'title' => "Activity · {$name}'s collection",
            'description' => "Price movements and recent additions in {$name}'s Pokémon TCG collection.",
            'isOwner' => $this->isOwnerViewing(),
        ]);
    }

    /**
     * Collection value per snapshot day, in ONE currency: for each day,
     * every OWNED COPY is priced from its own variant as of that day and
     * counted only when that price is in $currency (mixing would
     * fabricate a total). Days come from the union of every card's
     * snapshot dates, capped to the most recent MAX_SERIES_DAYS.
     *
     * Per copy, not per card: a collector holding one normal and one
     * reverse-holofoil of the same card owns two differently priced
     * things, and pricing the card once times the total quantity charts
     * a collection nobody has. Valuation::totalsByCurrencyAsOf() is the
     * same summation the headline figure uses, so the series' last point
     * and the number printed above it always agree.
     *
     * @param  Collection<int, Card>  $cards
     * @return Collection<int, array{date: CarbonImmutable, minor: int}>
     */
    private function valueSeries($cards, string $currency, CardPriceResolver $resolver, Valuation $valuation)
    {
        $dates = $cards
            ->flatMap(fn (Card $card) => $resolver->distinctSnapshotDates($card))
            ->unique(fn ($d) => $d->toDateString())
            ->sortBy(fn ($d) => $d->toDateString())
            ->values()
            ->take(-self::MAX_SERIES_DAYS);

        if ($dates->count() < 2) {
            return collect();
        }

        return $dates->map(function ($date) use ($cards, $currency, $valuation) {
            $minor = $valuation->totalsByCurrencyAsOf($cards, $date)[$currency] ?? 0;

            return ['date' => CarbonImmutable::parse($date), 'minor' => $minor];
        })->values();
    }
}
