<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Services\DecidingPriceRows;
use App\Modules\Catalog\Services\PriceHistoryEpoch;
use App\Modules\Catalog\Support\ChartRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The collection's value over a chart range, one point per sampled day,
 * in one currency. Each owned copy is priced from its own variant as of
 * that day by the same summation the headline uses
 * (Valuation::totalsByCurrencyOver()), from the same window the headline
 * reads — RECENT_DAYS back from the day, plus manual prices — so the
 * last point, today, is the headline figure.
 *
 * The resolver chain only ever needs, per (card, source, variant) and
 * day, the newest row and the newest priced row in that window: every
 * chain step picks the newest row of some of those groups, and the
 * staleness reference is the newest priced marketplace row. The groups
 * are listed once; then, one sample day at a time, two lookups per group
 * (LATERAL ... ORDER BY captured_on DESC LIMIT 1) return those rows. The
 * window's lower bound is part of the index range on the unique
 * (card_id, source, variant, captured_on) index (a manual group has none),
 * so a group that stopped syncing or has no priced row in the window stops
 * at the window's edge instead of walking its whole history. A
 * day's rows are priced and dropped before the next day is loaded, so
 * memory holds about two rows per group however long the range is, and
 * no query binds a value per card or per row.
 *
 * Even so, a year of history costs seconds on a large collection, and the
 * collection page is public, so every visit would pay it. The finished
 * series is cached for the day, per range and currency. Past days never
 * change: every price write is stamped with today, so a price written
 * today cannot move a point already computed for an earlier day, and the
 * key does not carry the price data at all. It carries what is left to
 * change the series: the day (a new day is a new key, which also heals any
 * mistake at midnight), the owned prints as the valuation reads them
 * (fingerprint()), and the price-history epoch (PriceHistoryEpoch), which
 * only `prices:bump-version` moves, for a past day corrected by hand.
 *
 * Today is the one point that can change during the day, so the page
 * replaces the stored series' last point with its headline total
 * (forPublicCollection()); for() and warm() deal in the stored series.
 *
 * Holdings changes rebuild the series; the hourly warm() job and the
 * collection observers repopulate it in the background.
 *
 * A hit costs no query beyond whatever the caller loaded to ask: the
 * page's cards, or, for warm(), the cards without their price rows.
 */
final class CollectionValueSeries
{
    /** Bump when the series' calculation changes, so a deploy doesn't serve a series the old code computed. */
    private const CACHE_VERSION = 4;

    /** Bounded: a public route must never fan out unbounded per-card work. */
    public const MAX_CARDS = 500;

    /** How many cards warm() loads price rows for at a time. */
    public const WARM_CHUNK = 40;

    public function __construct(private readonly Valuation $valuation) {}

    /** @var array<string, ?string> earliest day by card-id array, so a render asks the database once */
    private array $earliest = [];

    /**
     * What an anonymous visitor to the Activity page gets for a user: the
     * public collection's cards (capped at MAX_CARDS) with the price rows that
     * decide their current prices (see DecidingPriceRows), their totals, the
     * primary currency (see primaryCurrency()), and that currency's value
     * series and earliest priced day over the range. The page calls this;
     * warm() fills the same cache keys.
     *
     * @return array{
     *     cards: EloquentCollection<int, Card>,
     *     totals: array<string, int>,
     *     currency: ?string,
     *     series: Collection<int, array{date: CarbonImmutable, minor: int}>,
     *     earliest: ?CarbonImmutable,
     *     cached: bool,
     * }
     */
    public function forPublicCollection(PublicCollection $public, ChartRange $range): array
    {
        $cards = app(DecidingPriceRows::class)->loadOnto($public->cardsQuery()->take(self::MAX_CARDS)->get());
        $totals = $this->valuation->totalsByCurrency($cards);
        $currency = $this->primaryCurrency($totals);

        if ($currency === null) {
            return ['cards' => $cards, 'totals' => $totals, 'currency' => null, 'series' => collect(), 'earliest' => null, 'cached' => true];
        }

        [$series, $cached] = $this->cachedSeries($cards, $currency, $range);

        return [
            'cards' => $cards,
            'totals' => $totals,
            'currency' => $currency,
            'series' => $this->withTodayAt($series, $totals[$currency]),
            // The series asked for it already; this reads the same answer.
            'earliest' => $this->earliestDay($cards),
            'cached' => $cached,
        ];
    }

    /**
     * The stored series with today's point set to the page's headline, so a
     * price written since the series was built shows without a rebuild.
     * Today's point is the headline by construction (see the class docblock),
     * and every earlier point is a past day the cache can trust.
     *
     * @param  Collection<int, array{date: CarbonImmutable, minor: int}>  $series
     * @return Collection<int, array{date: CarbonImmutable, minor: int}>
     */
    private function withTodayAt(Collection $series, int $headline): Collection
    {
        $last = $series->last();

        if ($last === null || ! $last['date']->isSameDay(CarbonImmutable::today())) {
            return $series;
        }

        return $series->slice(0, -1)->push(['date' => $last['date'], 'minor' => $headline])->values();
    }

    /**
     * Makes sure the series cache holds, for each range, what the Activity
     * page reads for this user, and says how many were computed and how many
     * were already there. The cards are loaded once for every range, without
     * their price rows: the page's totals are summed over batches of
     * WARM_CHUNK cards whose deciding price rows (see DecidingPriceRows) are
     * loaded and dropped in turn, the same rows the page prices from, and the
     * series itself prices each sampled day from its own rows. So the heap
     * holds a batch of price rows, not the collection's whole recent history.
     *
     * @param  list<ChartRange>  $ranges
     * @return array{computed: int, warm: int}
     */
    public function warm(PublicCollection $public, array $ranges): array
    {
        $cards = $public->cardsQuery()->take(self::MAX_CARDS)->get();
        $loader = app(DecidingPriceRows::class);
        $totals = [];

        foreach ($cards->chunk(self::WARM_CHUNK) as $chunk) {
            $batch = $loader->loadOnto(new EloquentCollection($chunk->all()));

            foreach ($this->valuation->totalsByCurrency($batch) as $currency => $minor) {
                $totals[$currency] = ($totals[$currency] ?? 0) + $minor;
            }

            $batch->each(fn (Card $card) => $card->unsetRelation('priceSnapshots'));
        }

        $currency = $this->primaryCurrency(Valuation::order($totals));
        $counts = ['computed' => 0, 'warm' => 0];

        if ($currency === null) {
            return $counts;
        }

        foreach ($ranges as $range) {
            $counts[$this->cachedSeries($cards, $currency, $range)[1] ? 'warm' : 'computed']++;
        }

        return $counts;
    }

    /**
     * The currency the page charts: the first of the totals as
     * Valuation::order() sorts them (USD, otherwise the currency that
     * came first).
     *
     * @param  array<string, int>  $totals  ordered by Valuation::order()
     */
    private function primaryCurrency(array $totals): ?string
    {
        return array_key_first($totals);
    }

    /**
     * @param  Collection<int, Card>  $cards  owned cards, collectionItems loaded
     * @return Collection<int, array{date: CarbonImmutable, minor: int}>
     */
    public function for(Collection $cards, string $currency, ChartRange $range): Collection
    {
        return $this->cachedSeries($cards, $currency, $range)[0];
    }

    /**
     * for(), and whether the series came from the cache (an empty
     * collection has nothing to compute and counts as cached).
     *
     * @param  Collection<int, Card>  $cards
     * @return array{0: Collection<int, array{date: CarbonImmutable, minor: int}>, 1: bool}
     */
    private function cachedSeries(Collection $cards, string $currency, ChartRange $range): array
    {
        if ($cards->isEmpty()) {
            return [collect(), true];
        }

        $today = CarbonImmutable::today()->toDateString();
        $key = 'collection-value-series:v'.self::CACHE_VERSION.":{$range->value}:{$currency}:{$today}:{$this->fingerprint($cards)}:".app(PriceHistoryEpoch::class)->current();
        $cached = Cache::get($key);

        if (is_array($cached)) {
            $this->earliest[$this->idArray($cards)] = $cached['earliest'];

            /** @var list<array{date: string, minor: int}> $points */
            $points = $cached['points'];

            return [new Collection(array_map(
                fn (array $p): array => ['date' => CarbonImmutable::parse($p['date']), 'minor' => $p['minor']],
                $points,
            )), true];
        }

        $series = $this->build($cards, $currency, $range);

        // Nothing priced yet: there is no series to keep, and the first
        // price that arrives must not wait for midnight to show.
        if ($this->earliestDay($cards) === null) {
            return [$series, false];
        }

        Cache::put($key, [
            'earliest' => $this->earliestDay($cards)->toDateString(),
            'points' => $series
                ->map(fn (array $p): array => ['date' => $p['date']->toDateString(), 'minor' => $p['minor']])
                ->all(),
        ], CarbonImmutable::now()->endOfDay());

        return [$series, false];
    }

    /**
     * @param  Collection<int, Card>  $cards
     * @return Collection<int, array{date: CarbonImmutable, minor: int}>
     */
    private function build(Collection $cards, string $currency, ChartRange $range): Collection
    {
        $earliest = $this->earliestDay($cards);

        if ($earliest === null) {
            return collect();
        }

        $days = $range->sampleDays(CarbonImmutable::today(), $earliest);

        if (count($days) < 2) {
            return collect();
        }

        $groups = json_encode($this->groupsOf($cards), JSON_THROW_ON_ERROR);

        return collect($days)->map(function (CarbonImmutable $day) use ($cards, $groups, $currency): array {
            $key = $day->toDateString();
            $byCard = $this->rowsOn($groups, $day)->groupBy('card_id');
            // Each day sees only its own rows: a row kept for an earlier
            // day's window must never price a later day.
            $dayCards = $cards->map(fn (Card $card) => (clone $card)->setRelation(
                'priceSnapshots',
                new EloquentCollection($byCard->get($card->id, collect())->all()),
            ));
            $totals = $this->valuation->totalsByCurrencyOver($dayCards, [$day]);

            return ['date' => $day, 'minor' => $totals[$key][$currency] ?? 0];
        })->values();
    }

    /**
     * What the series depends on besides the day, range, currency and
     * history epoch: the owned prints, as what the valuation reads from them.
     * Per print (card and variant, a NULL variant kept apart from an empty
     * one and from 'normal' because it takes the card-level chain), the sum
     * of its copies' max(quantity, 1). Copy ids, timestamps, condition, grade,
     * notes and photos never reach the price, so editing them keeps the key.
     *
     * @param  Collection<int, Card>  $cards
     */
    private function fingerprint(Collection $cards): string
    {
        $prints = [];

        foreach ($cards as $card) {
            foreach ($card->collectionItems as $item) {
                $key = json_encode([(int) $item->card_id, $item->variant], JSON_THROW_ON_ERROR);
                $prints[$key] ??= [(int) $item->card_id, $item->variant, 0];
                $prints[$key][2] += max((int) $item->quantity, 1);
            }
        }

        ksort($prints);

        return sha1(json_encode(array_values($prints), JSON_THROW_ON_ERROR));
    }

    /**
     * The first day any of the cards has a priced row, so the chart never
     * opens on zeros from rows that carry no price. Rows in any currency
     * count, as the headline's history does.
     *
     * @param  Collection<int, Card>  $cards
     */
    public function earliestDay(Collection $cards): ?CarbonImmutable
    {
        if ($cards->isEmpty()) {
            return null;
        }

        $memo = $this->idArray($cards);

        if (array_key_exists($memo, $this->earliest)) {
            return $this->earliest[$memo] === null ? null : CarbonImmutable::parse($this->earliest[$memo]);
        }

        $min = CardPriceSnapshot::query()
            ->whereRaw('card_id = ANY(?::bigint[])', [$this->idArray($cards)])
            ->whereNotNull('market_minor')
            ->min('captured_on');

        $this->earliest[$memo] = $min === null ? null : CarbonImmutable::parse($min)->toDateString();

        return $this->earliest[$memo] === null ? null : CarbonImmutable::parse($this->earliest[$memo]);
    }

    /**
     * Every (card, source, variant) the cards have a snapshot for, as the
     * rows of a JSON recordset.
     *
     * @param  Collection<int, Card>  $cards
     * @return list<array{card_id: int, source: string, variant: string}>
     */
    private function groupsOf(Collection $cards): array
    {
        $rows = DB::select(
            'SELECT DISTINCT card_id, source, variant FROM card_price_snapshots WHERE card_id = ANY(?::bigint[])',
            [$this->idArray($cards)],
        );

        return array_values(array_map(fn ($r): array => [
            'card_id' => (int) $r->card_id,
            'source' => (string) $r->source,
            'variant' => (string) $r->variant,
        ], $rows));
    }

    /**
     * The rows that decide one day's prices, in id order: per group, the
     * newest row in the day's window and the newest priced one (often the
     * same row). The resolver breaks same-day ties itself, so the order
     * here only keeps the result stable.
     *
     * @param  string  $groups  JSON from groupsOf()
     * @return Collection<int, CardPriceSnapshot>
     */
    private function rowsOn(string $groups, CarbonImmutable $day): Collection
    {
        $columns = implode(', ', array_map(fn (string $c): string => "s.{$c}", CardPriceSnapshot::CHART_COLUMNS));
        $window = <<<'SQL'
            WHERE s.card_id = g.card_id AND s.source = g.source AND s.variant = g.variant
              AND s.captured_on <= ?::date
              AND s.captured_on >= CASE WHEN g.source = 'manual' THEN '-infinity'::date ELSE ?::date - ?::int END
            SQL;
        $date = $day->toDateString();
        $bind = [$date, $date, CardPriceSnapshot::RECENT_DAYS];

        $rows = DB::select(<<<SQL
            SELECT x.*
            FROM jsonb_to_recordset(?::jsonb) AS g(card_id bigint, source text, variant text)
            CROSS JOIN LATERAL (
                (SELECT {$columns} FROM card_price_snapshots s {$window}
                    ORDER BY s.captured_on DESC LIMIT 1)
                UNION
                (SELECT {$columns} FROM card_price_snapshots s {$window}
                    AND s.market_minor IS NOT NULL
                    ORDER BY s.captured_on DESC LIMIT 1)
            ) x
            ORDER BY x.id
            SQL, [$groups, ...$bind, ...$bind]);

        return new Collection(CardPriceSnapshot::hydrate(array_map(fn ($r): array => (array) $r, $rows))->all());
    }

    /**
     * @param  Collection<int, Card>  $cards
     * @return list<int>
     */
    private function idsOf(Collection $cards): array
    {
        return array_values($cards->map(fn (Card $card): int => (int) $card->id)->all());
    }

    /**
     * The card ids as one Postgres array literal, so a query binds a single value however many cards there are.
     *
     * @param  Collection<int, Card>  $cards
     */
    private function idArray(Collection $cards): string
    {
        return '{'.implode(',', $this->idsOf($cards)).'}';
    }
}
