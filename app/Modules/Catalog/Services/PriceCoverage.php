<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\CardPriceSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Where the catalog's TCGplayer pricing has a hole, read from the database
 * alone: cards that nothing links to a TCGplayer product, sets no group is
 * mapped for, prints whose newest TCGplayer price is old by TCGplayer's own
 * date, and price feeds (a marketplace and the origin it arrives through)
 * that stopped updating. Each is judged on its own: a fresh Cardmarket feed
 * says nothing about TCGplayer's.
 */
final class PriceCoverage
{
    /**
     * Each feed, the timestamp that says when it last moved, and how old that
     * may get. tcgdex's TCGplayer feed is judged by TCGplayer's own date
     * (`source_updated_at`), because tcgdex keeps re-sending a frozen price
     * and stamps it with today's capture day.
     *
     * @var list<array{source: string, origin: string, column: string, hours: int}>
     */
    private const FEEDS = [
        ['source' => 'cardmarket', 'origin' => 'tcgdex', 'column' => 'created_at', 'hours' => 26],
        ['source' => 'tcgplayer', 'origin' => 'tcgdex', 'column' => 'source_updated_at', 'hours' => CardPriceResolver::STALE_AFTER_DAYS * 24],
        // tcgcsv's build lands around 20:00 UTC and may slip by hours.
        ['source' => 'tcgplayer', 'origin' => 'tcgcsv', 'column' => 'created_at', 'hours' => 30],
    ];

    /**
     * Cards of a set with a mapped TCGplayer group that no link points at.
     *
     * @return list<string> tcgdex ids
     */
    public function unlinkedCards(): array
    {
        return array_values(DB::table('cards as c')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('set_tcgplayer_groups as g')->whereColumn('g.set_id', 'c.set_id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('card_tcgplayer_links as l')->whereColumn('l.card_id', 'c.id'))
            ->orderBy('c.tcgdex_id')
            ->pluck('c.tcgdex_id')
            ->map(fn ($id) => (string) $id)
            ->all());
    }

    /**
     * Sets that hold cards but have no mapped TCGplayer group, so nothing in
     * them can ever be linked.
     *
     * @return list<string> tcgdex ids
     */
    public function unmappedSets(): array
    {
        return array_values(DB::table('sets as s')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('cards as c')->whereColumn('c.set_id', 's.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('set_tcgplayer_groups as g')->whereColumn('g.set_id', 's.id'))
            ->orderBy('s.tcgdex_id')
            ->pluck('s.tcgdex_id')
            ->map(fn ($id) => (string) $id)
            ->all());
    }

    /**
     * Prints whose newest TCGplayer price, within the recent window, was last
     * updated by TCGplayer more than STALE_AFTER_DAYS ago (or captured that
     * long ago when the row has no TCGplayer date). A print with nothing in
     * the window is unpriced everywhere in the app and is not listed.
     *
     * @return list<array{card: string, variant: string, owned: bool}>
     */
    public function stalePrints(): array
    {
        $cutoff = CarbonImmutable::now()->subDays(CardPriceResolver::STALE_AFTER_DAYS);
        $owned = DB::table('collection_items')->distinct()->pluck('card_id')->flip();

        return array_values(DB::table('card_price_snapshots as s')
            ->join('cards as c', 'c.id', '=', 's.card_id')
            ->where('s.source', 'tcgplayer')
            ->where('s.captured_on', '>=', now()->subDays(CardPriceSnapshot::RECENT_DAYS)->toDateString())
            ->selectRaw('DISTINCT ON (s.card_id, s.variant) s.card_id, c.tcgdex_id, s.variant, COALESCE(s.source_updated_at, s.captured_on::timestamp) AS dated')
            ->orderBy('s.card_id')->orderBy('s.variant')->orderByDesc('s.captured_on')
            ->get()
            ->filter(fn ($row) => CarbonImmutable::parse($row->dated)->lt($cutoff))
            ->map(fn ($row) => ['card' => (string) $row->tcgdex_id, 'variant' => (string) $row->variant, 'owned' => $owned->has($row->card_id)])
            ->sortBy(fn (array $print) => $print['card'].' '.$print['variant'])
            ->all());
    }

    /**
     * Feeds whose newest row is older than they may be. A feed with no rows at
     * all is not listed: that is a feed this install never had.
     *
     * @return list<array{feed: string, newest: string}>
     */
    public function staleFeeds(): array
    {
        $stale = [];

        foreach (self::FEEDS as $feed) {
            $newest = DB::table('card_price_snapshots')
                ->where('source', $feed['source'])->where('origin', $feed['origin'])
                ->max($feed['column']);

            if ($newest !== null && CarbonImmutable::parse($newest)->lt(CarbonImmutable::now()->subHours($feed['hours']))) {
                $stale[] = ['feed' => "{$feed['source']}/{$feed['origin']}", 'newest' => CarbonImmutable::parse($newest)->toDateString()];
            }
        }

        return $stale;
    }
}
