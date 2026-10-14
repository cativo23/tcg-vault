<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
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
 * series is cached for the day, per range and currency. The key carries a
 * fingerprint that changes whenever the result could: the count and newest
 * updated_at of the cards' price rows that can reach the range (a new, edited or deleted price) and
 * each owned copy's id, card, variant, quantity and updated_at (a copy
 * added, removed or edited, or made public or private). A hit costs the one
 * aggregate query that computes the fingerprint.
 */
final class CollectionValueSeries
{
    /** Bump when the series' calculation changes, so a deploy doesn't serve a series the old code computed. */
    private const CACHE_VERSION = 2;

    public function __construct(private readonly Valuation $valuation) {}

    /** @var array<string, ?string> earliest day by card-id array, so a render asks the database once */
    private array $earliest = [];

    /**
     * @param  Collection<int, Card>  $cards  owned cards, collectionItems loaded
     * @return Collection<int, array{date: CarbonImmutable, minor: int}>
     */
    public function for(Collection $cards, string $currency, ChartRange $range): Collection
    {
        if ($cards->isEmpty()) {
            return collect();
        }

        $today = CarbonImmutable::today()->toDateString();
        $key = 'collection-value-series:v'.self::CACHE_VERSION.":{$range->value}:{$currency}:{$today}:{$this->fingerprint($cards, $range)}";
        $cached = Cache::get($key);

        if (is_array($cached)) {
            $this->earliest[$this->idArray($cards)] = $cached['earliest'];

            /** @var list<array{date: string, minor: int}> $points */
            $points = $cached['points'];

            return new Collection(array_map(
                fn (array $p): array => ['date' => CarbonImmutable::parse($p['date']), 'minor' => $p['minor']],
                $points,
            ));
        }

        $series = $this->build($cards, $currency, $range);

        Cache::put($key, [
            'earliest' => $this->earliestDay($cards)?->toDateString(),
            'points' => $series
                ->map(fn (array $p): array => ['date' => $p['date']->toDateString(), 'minor' => $p['minor']])
                ->all(),
        ], CarbonImmutable::now()->endOfDay());

        return $series;
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
     * What the series depends on besides the day, range and currency: the
     * cards' price rows and the owned copies. Only the price rows that can
     * price a sampled day count: those captured from the range's start
     * minus the RECENT_DAYS window on, and manual ones at any age. An older
     * row cannot move the series, so it must not cost a rebuild.
     *
     * @param  Collection<int, Card>  $cards
     */
    private function fingerprint(Collection $cards, ChartRange $range): string
    {
        $from = $range->start(CarbonImmutable::today())->subDays(CardPriceSnapshot::RECENT_DAYS)->toDateString();
        $prices = DB::selectOne(
            "SELECT count(*) AS n, max(updated_at) AS newest FROM card_price_snapshots WHERE card_id = ANY(?::bigint[]) AND (captured_on >= ?::date OR source = 'manual')",
            [$this->idArray($cards), $from],
        );
        $holdings = [];

        foreach ($cards as $card) {
            foreach ($card->collectionItems as $item) {
                $holdings[(int) $item->id] = [
                    (int) $item->id,
                    (int) $item->card_id,
                    $item->variant,
                    (int) $item->quantity,
                    (string) $item->updated_at,
                ];
            }
        }

        ksort($holdings);

        return sha1(json_encode([$prices->n ?? 0, $prices->newest ?? null, array_values($holdings)], JSON_THROW_ON_ERROR));
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
