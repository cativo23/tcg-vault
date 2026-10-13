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
 * day, the newest row and the newest priced row on or before that day:
 * every chain step picks the newest row of some of those groups, and the
 * staleness reference is the newest priced marketplace row. Postgres
 * reduces each day's window to those rows, so a year-long chart loads a
 * few rows per print per sample day instead of the whole history.
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

        $rowsByDay = $this->rowsOn($this->idsOf($cards), $days);

        return collect($days)->map(function (CarbonImmutable $day) use ($cards, $rowsByDay, $currency): array {
            $key = $day->toDateString();
            $byCard = $rowsByDay->get($key, collect())->groupBy('card_id');
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

    /** @param  Collection<int, Card>  $cards */
    public function earliestDay(Collection $cards): ?CarbonImmutable
    {
        if ($cards->isEmpty()) {
            return null;
        }

        $min = CardPriceSnapshot::query()->whereIn('card_id', $this->idsOf($cards))->min('captured_on');

        return $min === null ? null : CarbonImmutable::parse($min);
    }

    /**
     * Per sample day, the rows that decide its prices, in id order (the
     * resolver breaks same-day ties by load order, and the headline's
     * eager load comes back in id order).
     *
     * @param  list<int>  $cardIds
     * @param  list<CarbonImmutable>  $days
     * @return Collection<string, Collection<int, CardPriceSnapshot>>
     */
    private function rowsOn(array $cardIds, array $days): Collection
    {
        $bindings = [
            '{'.implode(',', array_map(fn (CarbonImmutable $d) => $d->toDateString(), $days)).'}',
            CardPriceSnapshot::RECENT_DAYS,
            '{'.implode(',', $cardIds).'}',
        ];

        $pairs = DB::select(<<<'SQL'
            WITH days AS (SELECT unnest(?::date[]) AS day),
            windowed AS (
                SELECT d.day, s.id, s.card_id, s.source, s.variant, s.captured_on, s.market_minor
                FROM days d
                JOIN card_price_snapshots s
                  ON s.captured_on <= d.day
                 AND (s.captured_on >= d.day - ?::int OR s.source = 'manual')
                WHERE s.card_id = ANY(?::bigint[])
            )
            (SELECT DISTINCT ON (day, card_id, source, variant) day, id
                FROM windowed
                ORDER BY day, card_id, source, variant, captured_on DESC)
            UNION
            (SELECT DISTINCT ON (day, card_id, source, variant) day, id
                FROM windowed
                WHERE market_minor IS NOT NULL
                ORDER BY day, card_id, source, variant, captured_on DESC)
            SQL, $bindings);

        $rows = CardPriceSnapshot::query()
            ->select(CardPriceSnapshot::CHART_COLUMNS)
            ->whereIn('id', array_unique(array_map(fn ($p) => (int) $p->id, $pairs)))
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        return collect($pairs)
            ->groupBy(fn ($p) => substr((string) $p->day, 0, 10))
            ->map(fn (Collection $dayPairs) => $dayPairs
                ->map(fn ($p) => $rows->get((int) $p->id))
                ->filter()
                ->sortBy('id')
                ->values());
    }

    /**
     * @param  Collection<int, Card>  $cards
     * @return list<int>
     */
    private function idsOf(Collection $cards): array
    {
        return array_values($cards->map(fn (Card $card): int => (int) $card->id)->all());
    }
}
