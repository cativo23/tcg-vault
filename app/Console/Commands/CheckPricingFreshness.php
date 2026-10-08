<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncTcgcsvPricesJob;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Support\DiscordAlerter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
 * The tcgcsv sync is watched separately, by when it last completed a
 * run rather than by the prices it writes — it writes only for gaps,
 * and may have none — so neither sync running fine hides the other one
 * stopping.
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

        // Only tcgdex's own rows: a hand-entered price or a tcgcsv fill
        // would otherwise mask a tcgdex sync that stopped. Manual rows are
        // excluded by source too, since origin is not enforced on them.
        $newest = CardPriceSnapshot::query()->where('source', '!=', 'manual')->where('origin', 'tcgdex')->max('created_at');

        if ($newest === null) {
            $alerter->send('⚠️ tcg-vault: no tcgdex price snapshot exists at all — the daily pricing sync may have never run.');
            $this->warn('No tcgdex price snapshot found.');

            return self::SUCCESS;
        }

        // Carbon 3's diffIn* methods return a signed difference by
        // default (negative when $newest is in the past) — absolute:
        // true is required to get "how many hours old", not "how many
        // hours until".
        $hoursSinceNewest = (int) floor(now()->diffInHours($newest, absolute: true));

        if ($hoursSinceNewest >= self::STALE_AFTER_HOURS) {
            $alerter->send("⚠️ tcg-vault: the newest price snapshot is {$hoursSinceNewest}h old — the daily pricing sync looks stuck.");
            $this->warn("Newest snapshot is {$hoursSinceNewest}h old.");

            return self::SUCCESS;
        }

        $this->info("Newest snapshot is {$hoursSinceNewest}h old — within the ".self::STALE_AFTER_HOURS.'h threshold.');

        return self::SUCCESS;
    }

    /**
     * In either tcgcsv mode — shadow still runs — once any TCGplayer
     * group is mapped, since with none the sync has nothing to fetch.
     */
    private function checkTcgcsv(DiscordAlerter $alerter): void
    {
        $mapped = DB::table('set_tcgplayer_groups')->exists() || CardTcgplayerLink::query()->whereNotNull('group_id')->exists();
        if (! $mapped) {
            return;
        }

        // The run record is the signal. Only when it is missing (a cache
        // flush, or a deploy after the day's build was already pulled) do
        // the last pulled build and the newest tcgcsv price stand in — the
        // build is recorded before linking and filling, so preferring it
        // would hide a run that keeps failing after the fetch.
        $seen = Cache::get(SyncTcgcsvPricesJob::LAST_RUN_CACHE_KEY)
            ?? Cache::get(SyncTcgcsvPricesJob::BUILD_CACHE_KEY)
            ?? CardPriceSnapshot::query()->where('origin', 'tcgcsv')->max('created_at');
        if (! is_string($seen) || $seen === '') {
            $alerter->send('⚠️ tcg-vault: no complete tcgcsv sync is on record — the tcgcsv sync looks stuck.');
            $this->warn('No complete tcgcsv sync on record.');

            return;
        }
        $lastRun = CarbonImmutable::parse($seen);

        $hours = (int) floor(now()->diffInHours($lastRun, absolute: true));
        if ($hours >= self::TCGCSV_STALE_AFTER_HOURS) {
            $alerter->send("⚠️ tcg-vault: the last complete tcgcsv sync was {$hours}h ago — the tcgcsv sync looks stuck.");
            $this->warn("Last complete tcgcsv sync was {$hours}h ago.");
        }
    }
}
