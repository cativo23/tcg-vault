<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Records every query that touches card_price_snapshots while a callback
 * runs, split into the DecidingPriceRows query and everything else. The
 * value series' own reads (its fingerprint count, earliest-day min(), group
 * list and per-day lookups) are set aside: they query the database by
 * design and never fill a card's relation. Anything left in `stray` read
 * price rows some other way: an eager load of the recent window, or a lazy
 * one-card-at-a-time load of a card's whole history. Not used by the app.
 */
final class PriceRowReads
{
    /** @var list<array{sql: string, bindings: array<int, mixed>}> */
    public array $deciding = [];

    /** @var list<string> */
    public array $series = [];

    /** @var list<string> */
    public array $stray = [];

    public static function during(callable $callback): self
    {
        $reads = new self;
        $listening = true;

        DB::listen(function (QueryExecuted $query) use ($reads, &$listening): void {
            if (! $listening || ! preg_match('/card_price_snapshots/i', $query->sql)) {
                return;
            }

            if (preg_match('/row_number\(\) over \(partition by card_id, source, variant order by captured_on desc\)/i', $query->sql)) {
                $reads->deciding[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            } elseif (self::isSeriesRead($query->sql)) {
                $reads->series[] = $query->sql;
            } else {
                $reads->stray[] = $query->sql;
            }
        });

        try {
            $callback();
        } finally {
            $listening = false;
        }

        return $reads;
    }

    /** The card ids the n-th deciding query was bound to. */
    public function decidingIds(int $n): array
    {
        $literal = (string) ($this->deciding[$n]['bindings'][0] ?? '{}');

        return array_map('intval', array_filter(explode(',', trim($literal, '{}')), fn (string $id) => $id !== ''));
    }

    private static function isSeriesRead(string $sql): bool
    {
        return preg_match('/^SELECT count\(\*\) AS n, max\(updated_at\) AS newest FROM card_price_snapshots/', $sql) === 1
            || preg_match('/^select min\("captured_on"\) as "?aggregate"? from "card_price_snapshots"/', $sql) === 1
            || preg_match('/^SELECT DISTINCT card_id, source, variant FROM card_price_snapshots/', $sql) === 1
            || str_contains($sql, 'jsonb_to_recordset');
    }
}
