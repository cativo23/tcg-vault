<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncTcgcsvPricesJob;
use App\Modules\Catalog\Models\Card;
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
 * otherwise. The signal that catches it is how many cards the sync
 * reached in the last STALE_AFTER_HOURS: the nightly run touches every card,
 * so a stall leaves nearly all of them behind, while a user adding one card
 * mid-day touches only that one.
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

    /** Below this share of cards synced within STALE_AFTER_HOURS the sync counts as stalled. */
    private const MIN_SYNCED_SHARE = 0.5;

    /** tcgcsv's build lands around 20:00 UTC and may slip by hours. */
    private const TCGCSV_STALE_AFTER_HOURS = 30;

    protected $signature = 'catalog:check-pricing-freshness';

    protected $description = 'Alert Discord if the newest card sync is older than expected.';

    public function handle(DiscordAlerter $alerter): int
    {
        $this->checkTcgcsv($alerter);

        // When the catalog sync last touched cards, not when a price row was
        // written: the sync stamps every card it processes, so a run that wrote
        // no price rows (tcgdex's frozen prices are skipped) is still a run.
        $newest = Card::query()->max('synced_at');

        if ($newest === null) {
            $alerter->send('⚠️ tcg-vault: no card has been synced yet — the daily pricing sync may have never run.');
            $this->warn('No card has been synced.');

            return self::SUCCESS;
        }

        // Carbon 3's diffIn* methods return a signed difference by
        // default (negative when $newest is in the past) — absolute:
        // true is required to get "how many hours old", not "how many
        // hours until".
        $hoursSinceNewest = (int) floor(now()->diffInHours($newest, absolute: true));

        $total = Card::query()->count();
        $recent = Card::query()->where('synced_at', '>=', now()->subHours(self::STALE_AFTER_HOURS))->count();

        if ($hoursSinceNewest >= self::STALE_AFTER_HOURS || $recent < $total * self::MIN_SYNCED_SHARE) {
            $alerter->send("⚠️ tcg-vault: {$recent} of {$total} cards were synced in the last ".self::STALE_AFTER_HOURS."h (newest {$hoursSinceNewest}h ago) — the daily pricing sync looks stuck.");
            $this->warn("{$recent} of {$total} cards synced in the last ".self::STALE_AFTER_HOURS.'h.');

            return self::SUCCESS;
        }

        $this->info("{$recent} of {$total} cards synced in the last ".self::STALE_AFTER_HOURS."h, newest {$hoursSinceNewest}h ago — within the threshold.");

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
