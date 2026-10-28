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
 * group and prints whose TCGplayer price is old (see PriceCoverage).
 * Read-only; the only thing it writes is the
 * record of what it already told Discord, so a finding that stays open is
 * announced once a week, not every night (a long list is named ten at a time).
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

    protected $description = 'Report unlinked cards, unmapped sets, and stale TCGplayer prices to Discord.';

    public function handle(PriceCoverage $coverage, DiscordAlerter $alerter): int
    {
        $unlinked = $coverage->unlinkedCards();
        $unmapped = $coverage->unmappedSets();
        $stale = $coverage->stalePrints();

        $this->line(sprintf('Unlinked cards in mapped sets: %d; sets without a group: %d; stale prints: %d.', count($unlinked), count($unmapped), count($stale)));

        $newUnlinked = $this->unsent('unlinked', $unlinked, fn (string $id) => $id);
        $newUnmapped = $this->unsent('unmapped', $unmapped, fn (string $id) => $id);
        $newStale = $this->unsent('stale', $stale, fn (array $p) => "{$p['card']}|{$p['variant']}");

        // What a message names is what counts as announced: the rest of a long
        // list is named on the following nights, not silenced for a week.
        $named = [];
        $lines = [];
        if ($newUnlinked !== []) {
            $lines[] = sprintf('- %d card(s) in a mapped set with no TCGplayer link: %s', count($newUnlinked), $this->list($newUnlinked));
            $named = [...$named, ...$this->keys('unlinked', array_slice($newUnlinked, 0, self::LISTED))];
        }
        if ($newUnmapped !== []) {
            $lines[] = sprintf('- %d set(s) with cards and no TCGplayer group: %s', count($newUnmapped), $this->list($newUnmapped));
            $named = [...$named, ...$this->keys('unmapped', array_slice($newUnmapped, 0, self::LISTED))];
        }
        if ($newStale !== []) {
            $owned = count(array_filter($newStale, fn (array $p) => $p['owned']));
            $names = array_map(fn (array $p) => ($p['owned'] ? '*' : '')."{$p['card']} {$p['variant']}", $newStale);
            $lines[] = sprintf('- %d print(s) whose TCGplayer price is stale (owned: %d, marked *): %s', count($newStale), $owned, $this->list($names));
            $named = [...$named, ...$this->keys('stale', array_map(fn (array $p) => "{$p['card']}|{$p['variant']}", array_slice($newStale, 0, self::LISTED)))];
        }

        if ($lines !== []) {
            $alerter->send("⚠️ tcg-vault price coverage:\n".implode("\n", $lines));

            // DiscordAlerter logs a failed post instead of throwing, so a
            // delivery failure cannot be told apart from a sent message here.
            foreach ($named as $key) {
                Cache::put($key, true, now()->addDays(self::REPEAT_AFTER_DAYS));
            }
        }

        return self::SUCCESS;
    }

    /**
     * The findings no message has named within the repeat window.
     *
     * @template T
     *
     * @param  list<T>  $findings
     * @param  callable(T): string  $key
     * @return list<T>
     */
    private function unsent(string $kind, array $findings, callable $key): array
    {
        return array_values(array_filter($findings, fn ($f) => ! Cache::has($this->cacheKey($kind, $key($f)))));
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function keys(string $kind, array $ids): array
    {
        return array_map(fn (string $id) => $this->cacheKey($kind, $id), $ids);
    }

    private function cacheKey(string $kind, string $id): string
    {
        return "price-coverage:reported:{$kind}:{$id}";
    }

    /** @param  list<string>  $names */
    private function list(array $names): string
    {
        $shown = implode(', ', array_slice($names, 0, self::LISTED));

        return count($names) > self::LISTED ? "{$shown} and ".(count($names) - self::LISTED).' more' : $shown;
    }
}
