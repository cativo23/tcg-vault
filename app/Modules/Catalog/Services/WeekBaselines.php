<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\CardPriceSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * The read model behind CardPriceResolver::weekDeltaFor(): for every
 * marketplace print (card, source, variant, currency) with a priced row in
 * the recent window, the newest priced row captured WEEK_DAYS to WEEK_DAYS +
 * WEEK_SLACK_DAYS days before the print's newest priced day (`anchor_on`).
 * Origin is not part of the print: a tcgcsv-filled day is a baseline like a
 * tcgdex one. Manual prices have none (a typed-in price is not a market
 * movement).
 *
 * Every write path that adds marketplace prices refreshes the cards it
 * wrote (CatalogSyncService::syncCard() inside its own transaction, the
 * tcgcsv fill after its loop), and `prices:refresh-week-baselines` rebuilds
 * the whole table nightly and after a write made outside the app. A print
 * whose newest price is newer than its stored anchor shows no move until
 * then (the resolver checks the anchor), never one against the wrong week.
 *
 * A refresh deletes the rows of its cards (or all rows) and inserts them
 * again in one transaction. Locks, all transaction-scoped: a full rebuild
 * takes the global key exclusively; a refresh of cards takes it shared plus
 * one lock per card (in id order), so a rebuild and a card's refresh never
 * interleave, two refreshes of the same card never interleave, and refreshes
 * of different cards run in parallel. A refresh called inside a larger
 * transaction (syncCard) holds its locks until that transaction commits.
 * Without them, under READ COMMITTED a per-card delete would not see rows a
 * concurrent rebuild inserted and its insert would hit the primary key.
 *
 * The refresh does not bump the price-data version itself: its insert reads
 * card_price_snapshots, which BumpPriceDataVersion counts as a write, so the
 * version moves after commit and cached deciding rows carrying the old
 * baselines are read again.
 */
final class WeekBaselines
{
    /** The attributes DecidingPriceRows adds to each loaded row, in this order. */
    public const ATTRIBUTES = ['week_anchor_on', 'week_baseline_id', 'week_baseline_on', 'week_baseline_origin', 'week_baseline_minor'];

    /** pg_advisory_xact_lock key of the whole table: exclusive for a rebuild, shared for a card's refresh. */
    private const TABLE_LOCK_KEY = 703_202_611;

    /** First key of the per-card lock pair (this, card id). */
    private const CARD_LOCK_KEY = 703_202_612;

    /**
     * Rebuilds the baselines of these cards only.
     *
     * @param  array<int, int|string>  $cardIds
     * @return int rows written
     */
    public function refreshCards(array $cardIds): int
    {
        $ids = array_values(array_unique(array_map('intval', $cardIds)));

        if ($ids === []) {
            return 0;
        }

        sort($ids);

        return $this->rebuild($ids);
    }

    /** @return int rows written */
    public function refreshAll(): int
    {
        return $this->rebuild(null);
    }

    /** @param  list<int>|null  $ids  sorted card ids, or null for every card */
    private function rebuild(?array $ids): int
    {
        $from = CardPriceSnapshot::recentFrom()->toDateString();
        $week = CardPriceResolver::WEEK_DAYS;
        $reach = CardPriceResolver::WEEK_DAYS + CardPriceResolver::WEEK_SLACK_DAYS;
        $scope = $ids === null ? '' : 'and card_id = any(?::bigint[])';
        $bindings = $ids === null ? [] : ['{'.implode(',', $ids).'}'];

        return DB::transaction(function () use ($ids, $from, $week, $reach, $scope, $bindings): int {
            if ($ids === null) {
                DB::select('select pg_advisory_xact_lock(?::bigint)', [self::TABLE_LOCK_KEY]);
            } else {
                DB::select('select pg_advisory_xact_lock_shared(?::bigint)', [self::TABLE_LOCK_KEY]);

                foreach ($ids as $id) {
                    DB::select('select pg_advisory_xact_lock(?::int, ?::int)', [self::CARD_LOCK_KEY, $id]);
                }
            }

            DB::delete("delete from card_price_week_baselines where true {$scope}", $bindings);

            return DB::affectingStatement(<<<SQL
                insert into card_price_week_baselines (card_id, source, variant, currency, anchor_on, baseline_id, baseline_on, baseline_origin, baseline_minor)
                select distinct on (g.card_id, g.source, g.variant, g.currency)
                    g.card_id, g.source, g.variant, g.currency, g.anchor_on, s.id, s.captured_on, s.origin, s.market_minor
                from (
                    select card_id, source, variant, currency, max(captured_on) as anchor_on
                    from card_price_snapshots
                    where source <> 'manual' and market_minor is not null and captured_on >= ?::date {$scope}
                    group by card_id, source, variant, currency
                ) as g
                join card_price_snapshots as s
                    on s.card_id = g.card_id and s.source = g.source and s.variant = g.variant and s.currency = g.currency
                   and s.market_minor is not null
                   and s.captured_on between g.anchor_on - {$reach} and g.anchor_on - {$week}
                order by g.card_id, g.source, g.variant, g.currency, s.captured_on desc
                SQL, [$from, ...$bindings]);
        });
    }
}
