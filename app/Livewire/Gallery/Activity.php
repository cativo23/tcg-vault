<?php

declare(strict_types=1);

namespace App\Livewire\Gallery;

use App\Livewire\Gallery\Concerns\ResolvesPublicCollection;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Catalog\Support\ChartRange;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Services\CollectionValueSeries;
use App\Modules\Collection\Services\Valuation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What changed: price moves since the previous snapshot and cards added,
 * as one chronological feed, under the collection's value over time.
 */
#[Layout('layouts.public')]
final class Activity extends Component
{
    use ResolvesPublicCollection;

    private const MAX_FEED = 40;

    /**
     * The chart's range, from `?range=`. Untrusted like CardShow's, hence
     * the union: ChartRange::fromInput() turns anything unknown into one
     * month.
     *
     * @var string|array<array-key, mixed>|null
     */
    #[Url(as: 'range', except: '1m', history: true)]
    public string|array|null $range = '1m';

    public function selectRange(mixed $key): void
    {
        if (is_string($key)) {
            $this->range = $key;
        }
    }

    public function mount(string $username): void
    {
        $this->resolveTargetUser($username);
    }

    public function render(): View
    {
        $public = $this->publicCollection();
        $resolver = new CardPriceResolver;
        $valuation = new Valuation($resolver);

        $seriesService = new CollectionValueSeries($valuation);
        $chartRange = ChartRange::fromInput($this->range);
        ['cards' => $cards, 'totals' => $totals, 'currency' => $primaryCurrency, 'series' => $series, 'earliest' => $earliest] = $seriesService->forPublicCollection($public, $chartRange);

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

        $items = $public->itemsQuery()->take(self::MAX_FEED)->get();

        // An added copy's card is almost always one the headline already
        // loaded, price rows included: reuse it, so its rows are neither
        // loaded nor resolved twice. Only a card past MAX_CARDS is loaded
        // here, in one batch through the same deciding-rows query.
        $loaded = $cards->keyBy('id');
        $missing = $items->pluck('card_id')->unique()->diff($loaded->keys())->values();

        if ($missing->isNotEmpty()) {
            $loaded = $loaded->union(app(DecidingPriceRows::class)->loadOnto(
                Card::query()->with('set')->whereKey($missing)->get(),
            )->keyBy('id'));
        }

        $items->each(fn (CollectionItem $item) => $item->setRelation('card', $loaded->get($item->card_id)));

        $additions = $items
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
                'snapshot' => $resolver->resolveForVariant($item->card, $item->variant) ?? $resolver->resolveCardWide($item->card),
                'at' => $item->created_at,
            ]);

        $feed = $moves->concat($additions)
            ->sortByDesc(fn ($e) => $e['at']->timestamp ?? 0)
            ->take(self::MAX_FEED)
            ->values();

        $startsAfterRange = $earliest !== null && $earliest->toDateString() > $chartRange->start(today())->toDateString();
        $historySince = $series->isNotEmpty() && $startsAfterRange ? $series->first()['date'] : null;

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
            'chartRange' => $chartRange,
            'historySince' => $historySince,
        ])->layoutData([
            'title' => "Activity · {$name}'s collection",
            'description' => "Price movements and recent additions in {$name}'s Pokémon TCG collection.",
            'isOwner' => $this->isOwnerViewing(),
        ]);
    }
}
