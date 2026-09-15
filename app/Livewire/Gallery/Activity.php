<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Livewire\Gallery\Concerns\ResolvesPublicCollection;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\Valuation;
use Carbon\CarbonImmutable;
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

    public function render()
    {
        $public = $this->publicCollection();
        $resolver = new CardPriceResolver();
        $valuation = new Valuation($resolver);

        $cards = $public->cardsQuery()->take(self::MAX_CARDS)->get();

        $moves = $cards
            ->map(function (Card $card) use ($resolver) {
                $delta = $resolver->resolveDelta($card);

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
            ->with('card.set')
            ->take(self::MAX_FEED)
            ->get()
            ->map(fn (CollectionItem $item) => [
                'kind' => 'added',
                'card' => $item->card,
                'item' => $item,
                'snapshot' => $item->card ? $resolver->resolve($item->card->load('priceSnapshots')) : null,
                'at' => $item->created_at,
            ]);

        $feed = $moves->concat($additions)
            ->sortByDesc(fn ($e) => $e['at']->timestamp)
            ->take(self::MAX_FEED)
            ->values();

        $totals = $valuation->totalsByCurrency($cards);
        $primaryCurrency = array_key_first($totals);
        $series = $primaryCurrency ? $this->valueSeries($cards, $primaryCurrency, $resolver) : collect();

        $name = $this->collectorName();

        return view('livewire.gallery.activity', [
            'feed' => $feed,
            'moveCount' => $moves->count(),
            'totals' => $totals,
            'series' => $series,
            'primaryCurrency' => $primaryCurrency,
        ])->layoutData([
            'title' => "Activity · {$name}'s collection",
            'description' => "Price movements and recent additions in {$name}'s Pokémon TCG collection.",
        ]);
    }

    /**
     * Collection value per snapshot day, in ONE currency: for each day,
     * every card is priced as of that day and counted only when its
     * resolved price is in $currency (mixing would fabricate a total).
     * Days come from the union of every card's snapshot dates, capped to
     * the most recent MAX_SERIES_DAYS.
     *
     * @param  \Illuminate\Support\Collection<int, Card>  $cards
     * @return \Illuminate\Support\Collection<int, array{date: CarbonImmutable, minor: int}>
     */
    private function valueSeries($cards, string $currency, CardPriceResolver $resolver)
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

        return $dates->map(function ($date) use ($cards, $currency, $resolver) {
            $minor = 0;

            foreach ($cards as $card) {
                $snapshot = $resolver->resolveAsOf($card, $date);

                if ($snapshot === null || $snapshot->currency !== $currency || $snapshot->market_minor === null) {
                    continue;
                }

                $minor += $snapshot->market_minor * max((int) $card->collectionItems->sum('quantity'), 1);
            }

            return ['date' => CarbonImmutable::parse($date), 'minor' => $minor];
        })->values();
    }
}
