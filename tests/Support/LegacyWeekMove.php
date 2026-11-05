<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Services\PriceDelta;
use Carbon\Carbon;

/**
 * The 7-day move computed by scanning a card's loaded rows: the newest
 * priced row of the pick's source, variant and currency captured WEEK_DAYS
 * to WEEK_DAYS + WEEK_SLACK_DAYS before the pick; null for no pick, an
 * unpriced or manual pick, or a pick STALE_AFTER_DAYS behind the card's
 * newest priced marketplace day. Over the whole recent window
 * (FullRecentPriceRows) this is the reference the read model must match.
 * Not used by the app.
 */
final class LegacyWeekMove
{
    public static function of(Card $card, ?CardPriceSnapshot $latest): ?PriceDelta
    {
        if ($latest === null || $latest->market_minor === null || $latest->source === 'manual') {
            return null;
        }

        $rows = $card->priceSnapshots;
        $synced = $rows->filter(fn (CardPriceSnapshot $s) => $s->source !== 'manual' && $s->market_minor !== null)
            ->max(fn (CardPriceSnapshot $s) => $s->capturedOnKey());
        $latestKey = $latest->capturedOnKey();

        if ($synced === null || $latestKey < Carbon::parse($synced)->subDays(CardPriceResolver::STALE_AFTER_DAYS)->toDateString()) {
            return null;
        }

        $day = Carbon::parse($latestKey);
        $newest = $day->copy()->subDays(CardPriceResolver::WEEK_DAYS)->toDateString();
        $oldest = $day->copy()->subDays(CardPriceResolver::WEEK_DAYS + CardPriceResolver::WEEK_SLACK_DAYS)->toDateString();
        $wanted = $latest->getAttributes();

        $previous = $rows
            ->filter(fn (CardPriceSnapshot $s) => $s->capturedOnKey() <= $newest && $s->capturedOnKey() >= $oldest
                && $s->getAttributes()['source'] === $wanted['source']
                && $s->getAttributes()['variant'] === $wanted['variant']
                && $s->getAttributes()['currency'] === $wanted['currency']
                && $s->market_minor !== null)
            ->sortByDesc(fn (CardPriceSnapshot $s) => $s->capturedOnKey())
            ->first();

        return $previous === null ? null : new PriceDelta($latest, $previous, $latest->market_minor - $previous->market_minor);
    }
}
