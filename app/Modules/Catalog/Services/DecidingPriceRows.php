<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
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
 *
 * The rows are cached per set of cards (see cacheKey()), as plain values,
 * compressed, hydrated on every read, for up to a day. An entry is used only while the
 * price-data version (PriceDataVersion) and the window's first day are the
 * ones it was read under, and only for exactly the card ids it was read
 * for, so a price written since, a window that moved at midnight, or a
 * different set of cards (a public page's versus one that includes private
 * copies) all read the database again. Two galleries with exactly the same
 * cards share an entry: the rows depend on the cards alone. A miss
 * overwrites the entry, so a card set holds one entry however often prices
 * change.
 */
class DecidingPriceRows
{
    /** Bump when the query or the entry's shape changes, so a deploy never reads an entry the old code wrote. */
    private const CACHE_VERSION = 1;

    private const TTL_SECONDS = 86400;

    /**
     * @param  EloquentCollection<int, Card>  $cards
     * @return EloquentCollection<int, Card> the same cards, relation set
     */
    public function loadOnto(EloquentCollection $cards): EloquentCollection
    {
        if ($cards->isEmpty()) {
            return $cards;
        }

        $ids = $cards->map(fn (Card $card): int => (int) $card->getKey())->unique()->sort()->values()->all();
        $idList = implode(',', $ids);
        $from = CardPriceSnapshot::recentFrom()->toDateString();
        // Read before the rows: a write that commits after this bumps past it.
        $version = app(PriceDataVersion::class)->current();
        $key = self::cacheKey($ids);
        $values = self::cachedValues(Cache::get($key), $version, $from, $idList);

        if ($values === null) {
            $values = $this->query($idList, $from);
            Cache::put($key, [
                'version' => $version,
                'from' => $from,
                'ids' => $idList,
                'columns' => CardPriceSnapshot::CHART_COLUMNS,
                // Compressed: a collection's rows serialize to over half a
                // megabyte and compress about tenfold at the fastest level.
                'rows' => gzcompress(serialize($values), 1),
            ], self::TTL_SECONDS);
        }

        $byCard = [];
        $attributes = array_map(fn (array $row): array => array_combine(CardPriceSnapshot::CHART_COLUMNS, $row), $values);

        foreach (CardPriceSnapshot::hydrate($attributes) as $snapshot) {
            $byCard[$snapshot->card_id][] = $snapshot;
        }

        foreach ($cards as $card) {
            $card->setRelation('priceSnapshots', new EloquentCollection($byCard[$card->getKey()] ?? []));
        }

        return $cards;
    }

    /**
     * The rows of a cache entry, or null when it is not one this load may
     * use: another version, window or card set, or an entry that won't
     * inflate or unserialize to rows. Null reads the database, never errors.
     *
     * @return list<list<mixed>>|null
     */
    private static function cachedValues(mixed $entry, int $version, string $from, string $idList): ?array
    {
        if (! is_array($entry)
            || ($entry['version'] ?? null) !== $version
            || ($entry['from'] ?? null) !== $from
            || ($entry['ids'] ?? null) !== $idList
            || ($entry['columns'] ?? null) !== CardPriceSnapshot::CHART_COLUMNS
            || ! is_string($entry['rows'] ?? null)
            || ! is_string($packed = @gzuncompress($entry['rows']))) {
            return null;
        }

        $values = @unserialize($packed, ['allowed_classes' => false]);

        if (! is_array($values)) {
            return null;
        }

        $width = count(CardPriceSnapshot::CHART_COLUMNS);

        foreach ($values as $row) {
            if (! is_array($row) || count($row) !== $width) {
                return null;
            }
        }

        /** @var list<list<mixed>> $values */
        return $values;
    }

    /**
     * The cache key for a set of card ids, in any order.
     *
     * @param  array<int, int>  $ids
     */
    public static function cacheKey(array $ids): string
    {
        $ids = array_map('intval', $ids);
        sort($ids);

        return 'deciding-price-rows:v'.self::CACHE_VERSION.':'.sha1(implode(',', array_unique($ids)));
    }

    /**
     * The deciding rows of the cards, in id order, each as its
     * CHART_COLUMNS values in that order.
     *
     * @return list<list<mixed>>
     */
    private function query(string $idList, string $from): array
    {
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
            '{'.$idList.'}',
            $from,
        ]);

        return array_values(array_map(fn (object $row): array => array_values((array) $row), $rows));
    }
}
