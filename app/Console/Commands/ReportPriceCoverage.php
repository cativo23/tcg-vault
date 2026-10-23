<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Catalog\Services\PriceCoverage;
use App\Support\DiscordAlerter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Names the holes in TCGplayer pricing before a collector meets them as a
 * price in the wrong currency: cards no link points at, sets with no mapped
 * group, prints whose TCGplayer price is old, and price feeds that stopped
 * updating (see PriceCoverage). Read-only; the only thing it writes is the
 * record of what it already told Discord, so a finding that stays open is
 * announced once a week, not every night.
 *
 * Scheduled after the evening tcgcsv run (see routes/console.php), so a
 * normal day's prices are in before it looks.
 */
final class ReportPriceCoverage extends Command
{
    /** How long a finding stays quiet once it has been sent. */
    private const REPEAT_AFTER_DAYS = 7;

    /** Names listed per finding in the alert; the console lists the count. */
    private const LISTED = 10;

    protected $signature = 'catalog:report-price-coverage';

    protected $description = 'Report unlinked cards, unmapped sets, stale TCGplayer prices and stale price feeds to Discord.';

    public function handle(PriceCoverage $coverage, DiscordAlerter $alerter): int
    {
        $unlinked = $coverage->unlinkedCards();
        $unmapped = $coverage->unmappedSets();
        $stale = $coverage->stalePrints();
        $feeds = $coverage->staleFeeds();

        $this->line(sprintf('Unlinked cards in mapped sets: %d; sets without a group: %d; stale prints: %d; stale feeds: %d.', count($unlinked), count($unmapped), count($stale), count($feeds)));

        $newUnlinked = array_values(array_filter($unlinked, fn (string $id) => $this->isNew('unlinked', $id)));
        $newUnmapped = array_values(array_filter($unmapped, fn (string $id) => $this->isNew('unmapped', $id)));
        $newStale = array_values(array_filter($stale, fn (array $p) => $this->isNew('stale', "{$p['card']}|{$p['variant']}")));
        $newFeeds = array_values(array_filter($feeds, fn (array $f) => $this->isNew('feed', $f['feed'])));

        $lines = [];
        if ($newUnlinked !== []) {
            $lines[] = sprintf('- %d card(s) in a mapped set with no TCGplayer link: %s', count($newUnlinked), $this->list($newUnlinked));
        }
        if ($newUnmapped !== []) {
            $lines[] = sprintf('- %d set(s) with cards and no TCGplayer group: %s', count($newUnmapped), $this->list($newUnmapped));
        }
        if ($newStale !== []) {
            $owned = count(array_filter($newStale, fn (array $p) => $p['owned']));
            $names = array_map(fn (array $p) => ($p['owned'] ? '*' : '')."{$p['card']} {$p['variant']}", $newStale);
            $lines[] = sprintf('- %d print(s) whose TCGplayer price is stale (owned: %d, marked *): %s', count($newStale), $owned, $this->list($names));
        }
        if ($newFeeds !== []) {
            $lines[] = '- price feed(s) stopped updating: '.implode(', ', array_map(fn (array $f) => "{$f['feed']} (newest {$f['newest']})", $newFeeds));
        }

        if ($lines !== []) {
            $alerter->send("⚠️ tcg-vault price coverage:\n".implode("\n", $lines));
        }

        return self::SUCCESS;
    }

    /** True the first time within the repeat window; records that it was sent. */
    private function isNew(string $kind, string $key): bool
    {
        return Cache::add("price-coverage:reported:{$kind}:{$key}", true, now()->addDays(self::REPEAT_AFTER_DAYS));
    }

    /** @param  list<string>  $names */
    private function list(array $names): string
    {
        $shown = implode(', ', array_slice($names, 0, self::LISTED));

        return count($names) > self::LISTED ? "{$shown} and ".(count($names) - self::LISTED).' more' : $shown;
    }
}
