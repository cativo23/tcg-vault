<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Support\ChartRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
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
 */
final class CollectionValueSeries
{
    public function __construct(private readonly Valuation $valuation) {}

    /**
     * @param  Collection<int, Card>  $cards  owned cards, collectionItems loaded
     * @return Collection<int, array{date: CarbonImmutable, minor: int}>
     */
    public function for(Collection $cards, string $currency, ChartRange $range): Collection
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

        $min = CardPriceSnapshot::query()
            ->whereRaw('card_id = ANY(?::bigint[])', [$this->idArray($cards)])
            ->whereNotNull('market_minor')
            ->min('captured_on');

        return $min === null ? null : CarbonImmutable::parse($min);
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

        return array_values(array_map(fn ($r): array => ['card_id' => (int) $r->card_id, 'source' => (string) $r->source, 'variant' => (string) $r->variant], $rows));
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
