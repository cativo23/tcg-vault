<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Support\DiscordAlerter;
use Illuminate\Console\Command;

/**
 * Catches a stalled catalog:refresh-prices run — the daily sync
 * dispatching successfully but never actually completing (or never even
 * dispatching, if the scheduler process itself died) stays silent
 * otherwise. Confirmed live: a 30-hour stall on 2026-09-24/25 went
 * unnoticed until someone asked, because nothing was watching the one
 * signal that would have caught it — how old the newest price snapshot
 * actually is.
 *
 * Scheduled well after catalog:refresh-prices's own daily dispatch (see
 * routes/console.php), so a normal day's run has hours of buffer before
 * this ever fires.
 */
final class CheckPricingFreshness extends Command
{
    private const STALE_AFTER_HOURS = 26;

    protected $signature = 'catalog:check-pricing-freshness';

    protected $description = 'Alert Discord if the newest card price snapshot is older than expected.';

    public function handle(DiscordAlerter $alerter): int
    {
        $newest = CardPriceSnapshot::query()->max('created_at');

        if ($newest === null) {
            $alerter->send('⚠️ tcg-vault: no price snapshot exists at all — the daily pricing sync may have never run.');
            $this->warn('No price snapshot found.');

            return self::SUCCESS;
        }

        // Carbon 3's diffIn* methods return a signed difference by
        // default (negative when $newest is in the past) — absolute:
        // true is required to get "how many hours old", not "how many
        // hours until".
        $hoursSinceNewest = now()->diffInHours($newest, absolute: true);

        if ($hoursSinceNewest >= self::STALE_AFTER_HOURS) {
            $alerter->send("⚠️ tcg-vault: the newest price snapshot is {$hoursSinceNewest}h old — the daily pricing sync looks stuck.");
            $this->warn("Newest snapshot is {$hoursSinceNewest}h old.");

            return self::SUCCESS;
        }

        $this->info("Newest snapshot is {$hoursSinceNewest}h old — within the ".self::STALE_AFTER_HOURS.'h threshold.');

        return self::SUCCESS;
    }
}
