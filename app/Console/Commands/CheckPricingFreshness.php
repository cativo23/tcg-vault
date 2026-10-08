<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\CardTcgplayerLink;
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
 *
 * In tcgcsv fill mode the tcgcsv sync is watched separately, by its own
 * rows: each sync is judged only by what it writes, so one running fine
 * never hides the other one stopping.
 */
final class CheckPricingFreshness extends Command
{
    private const STALE_AFTER_HOURS = 26;

    /** tcgcsv's build lands around 20:00 UTC and may slip by hours. */
    private const TCGCSV_STALE_AFTER_HOURS = 30;

    protected $signature = 'catalog:check-pricing-freshness';

    protected $description = 'Alert Discord if the newest card price snapshot is older than expected.';

    public function handle(DiscordAlerter $alerter): int
    {
        $this->checkTcgcsv($alerter);

        // A hand-entered price is written whenever someone saves one, so
        // counting it would let a single save mask a sync that stopped.
        $newest = CardPriceSnapshot::query()->where('source', '!=', 'manual')->where('origin', 'tcgdex')->max('created_at');

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

    /**
     * Only once tcgcsv has written a price: a fill-mode sync with no gaps
     * to fill writes nothing, which is not a stall. If tcgdex later prices
     * every gap, tcgcsv stops writing and this alerts once a day until
     * the mode is set to shadow.
     */
    private function checkTcgcsv(DiscordAlerter $alerter): void
    {
        if (config('tcgcsv.mode') === 'shadow' || ! CardTcgplayerLink::query()->exists()) {
            return;
        }

        $newest = CardPriceSnapshot::query()->where('origin', 'tcgcsv')->max('created_at');
        if ($newest === null) {
            return;
        }

        $hours = now()->diffInHours($newest, absolute: true);
        if ($hours >= self::TCGCSV_STALE_AFTER_HOURS) {
            $alerter->send("⚠️ tcg-vault: the newest tcgcsv price is {$hours}h old — the tcgcsv sync looks stuck.");
            $this->warn("Newest tcgcsv price is {$hours}h old.");
        }
    }
}
