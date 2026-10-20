<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Loads onto each card only the rows of its recent window (see
 * CardPriceSnapshot::scopeRecent()) that can decide a current price, in one
 * query, as its `priceSnapshots` relation. A card with years of daily rows
 * gets a handful instead of a month of them.
 *
 * The kept rows, out of the recent window (CardPriceResolver's rules; each
 * (card, source, variant) group holds at most one row per day, by the
 * unique index):
 *
 * - The newest row of every (card, source, variant) group. Every chain step
 *   picks the newest row of a subset made of whole groups, and the staleness
 *   reference (the card's newest priced marketplace row) is the newest priced
 *   row of its own group, which the next rule keeps.
 * - The two newest priced rows of every (card, source, variant, currency):
 *   previousComparable() looks, for a pick, for the newest earlier priced row
 *   of the same source, variant and currency.
 * - The newest priced marketplace row of every (card, source, variant,
 *   origin), so the set of origins a gallery header lists ("via tcgcsv ·
 *   tcgdex") is the same as over the whole window.
 *
 * The relation then answers current-price questions only, exactly as the
 * whole window would: resolve(), resolveForVariant(), resolveCardWide(),
 * hasCurrentMarketPrice(), deltaFor() / resolveDelta(), Valuation's
 * totalsByCurrency(), cardTotal() and headlineSnapshot(), and the newest
 * priced marketplace day and its origins. It does NOT answer as-of or
 * history questions (resolveForVariantAsOf(), resolveAsOf(),
 * totalsByCurrencyAsOf(), resolveForVariantOnDays(),
 * distinctSnapshotDates()): those need the rows this drops. historyIn()
 * reads the database and is unaffected.
 *
 * Rows carry CardPriceSnapshot::CHART_COLUMNS, not the `raw` payload.
 */
class DecidingPriceRows
{
    /**
     * @param  EloquentCollection<int, Card>  $cards
     * @return EloquentCollection<int, Card> the same cards, relation set
     */
    public function loadOnto(EloquentCollection $cards): EloquentCollection
    {
        if ($cards->isEmpty()) {
            return $cards;
        }

        $columns = implode(', ', CardPriceSnapshot::CHART_COLUMNS);
        // Running counts over newest-first partitions: a priced row's count
        // is its rank among the priced rows of its partition.
        $rows = DB::select(<<<SQL
            select {$columns} from (
                select {$columns},
                    row_number() over (partition by card_id, source, variant order by captured_on desc) as newest,
                    count(*) filter (where market_minor is not null)
                        over (partition by card_id, source, variant, currency order by captured_on desc rows unbounded preceding) as priced_rank,
                    count(*) filter (where market_minor is not null)
                        over (partition by card_id, source, variant, origin order by captured_on desc rows unbounded preceding) as origin_rank
                from "card_price_snapshots"
                where card_id = any(?::bigint[])
                  and (captured_on >= ?::date or source = 'manual')
            ) as windowed
            where newest = 1
               or (market_minor is not null and priced_rank <= 2)
               or (market_minor is not null and source <> 'manual' and origin_rank = 1)
            order by id
            SQL, [
            '{'.$cards->map(fn (Card $card): int => (int) $card->getKey())->implode(',').'}',
            CardPriceSnapshot::recentFrom()->toDateString(),
        ]);

        $byCard = [];
        foreach (CardPriceSnapshot::hydrate(array_map(fn (object $row): array => (array) $row, $rows)) as $snapshot) {
            $byCard[$snapshot->card_id][] = $snapshot;
        }

        foreach ($cards as $card) {
            $card->setRelation('priceSnapshots', new EloquentCollection($byCard[$card->getKey()] ?? []));
        }

        return $cards;
    }
}
