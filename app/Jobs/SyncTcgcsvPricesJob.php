<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Tcgcsv\TcgcsvClient;
use App\Modules\Catalog\Tcgcsv\TcgcsvPriceRow;
use App\Modules\Catalog\Tcgcsv\TcgplayerLinkDiscovery;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Pulls each linked set's TCGplayer prices from tcgcsv once per tcgcsv
 * build (about one request per group) and links prints to products.
 *
 * Shadow mode (the only mode so far): compares tcgcsv's price with the
 * tcgdex-synced TCGplayer price for each linked print and logs the
 * result — it writes no prices. That comparison decides whether tcgcsv
 * can be trusted to fill gaps (ROADMAP: tcgcsv as the primary TCGplayer
 * source).
 *
 * Retries are deliberately slow: tcgcsv throttles or bans clients that
 * hammer it, so a failure or an unpublished build waits an hour, and a
 * build already pulled is never pulled again.
 */
final class SyncTcgcsvPricesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** A minutes-long pull; its connection's retry_after (900) must stay above this. */
    public int $timeout = 600;

    public bool $failOnTimeout = true;

    /** Unexpected errors (not the ones handled below) give up after this many. */
    public int $maxExceptions = 3;

    /** tcgcsv asks for a gap between requests; 150 ms keeps well inside it. */
    private const PAUSE_MICROSECONDS = 150_000;

    private const RETRY_SECONDS = 3600;

    private const BUILD_CACHE_KEY = 'tcgcsv:last_build';

    public function __construct()
    {
        $this->onConnection('redis-long');
        $this->onQueue('tcgcsv');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [600, 1800, 3600];
    }

    /**
     * An unpublished build is retried until late evening; after that the
     * day is left to tcgdex's midnight sync. A run started later still
     * gets one hour.
     */
    public function retryUntil(): DateTimeInterface
    {
        $cutoff = now()->setTime(23, 30);

        return now()->lt($cutoff) ? $cutoff : now()->addHour();
    }

    public function handle(TcgcsvClient $client, TcgplayerLinkDiscovery $discovery): void
    {
        try {
            $build = $client->lastUpdated()->toIso8601String();
        } catch (ConnectionException|RequestException|UnexpectedValueException $e) {
            Log::warning('tcgcsv build check failed; retrying later', ['error' => class_basename($e)]);
            $this->retryLaterOrStop();

            return;
        }

        if (Cache::get(self::BUILD_CACHE_KEY) === $build) {
            // Never re-pull a build already fetched (tcgcsv's guidance).
            $this->retryLaterOrStop();

            return;
        }

        [$prices, $ok, $failedGroups] = $this->fetchPrices($client);

        if ($failedGroups === []) {
            // Recorded before linking, so a later failure never re-pulls it.
            Cache::forever(self::BUILD_CACHE_KEY, $build);
        } else {
            // One report per run, not one per group.
            report(new RuntimeException('tcgcsv price groups failed: '.implode(', ', $failedGroups)));
        }

        $skipped = 0;
        $setIds = DB::table('set_tcgplayer_groups')->distinct()->pluck('set_id');
        Card::whereIn('set_id', $setIds)->chunkById(200, function ($cards) use ($discovery, $prices, &$skipped) {
            foreach ($cards as $card) {
                $skipped += $discovery->discover($card, $prices);
            }
        });

        Log::info('tcgcsv shadow sync', [
            'build' => $build,
            'groups_ok' => $ok,
            'groups_failed' => count($failedGroups),
            'skipped_unknown_subtype' => $skipped,
            ...$this->compare($prices),
        ]);

        if ($failedGroups !== []) {
            // Some groups are missing from this build; try them again later.
            $this->retryLaterOrStop();
        }
    }

    /** Release for an hour, or end quietly when the day's window is over. */
    private function retryLaterOrStop(): void
    {
        if (now()->addSeconds(self::RETRY_SECONDS)->lt($this->retryUntil())) {
            $this->release(self::RETRY_SECONDS);
        }
    }

    /**
     * Every configured group's prices, groupId → productId → subType → row.
     * A group that fails is skipped and named, never fatal to the rest.
     *
     * @return array{array<int, array<int, array<string, TcgcsvPriceRow>>>, int, list<int>}
     */
    private function fetchPrices(TcgcsvClient $client): array
    {
        $groups = DB::table('set_tcgplayer_groups')->distinct()->pluck('group_id')
            ->merge(CardTcgplayerLink::whereNotNull('group_id')->distinct()->pluck('group_id'))
            ->map(fn ($g) => (int) $g)->unique()->sort()->values();

        $prices = [];
        $ok = 0;
        $failed = [];

        foreach ($groups as $i => $groupId) {
            if ($i > 0) {
                usleep(self::PAUSE_MICROSECONDS);
            }

            try {
                foreach ($client->prices($groupId) as $row) {
                    $prices[$groupId][$row->productId][$row->subType] = $row;
                }
                $ok++;
            } catch (Throwable) {
                $failed[] = $groupId;
            }
        }

        return [$prices, $ok, $failed];
    }

    /**
     * How tcgcsv's price for each linked print compares with the latest
     * tcgdex-synced TCGplayer price for it (within the resolver's 3-day
     * window), and how many linked prints tcgdex doesn't price at all —
     * the gaps tcgcsv would fill.
     *
     * @param  array<int, array<int, array<string, TcgcsvPriceRow>>>  $prices
     * @return array<string, mixed>
     */
    private function compare(array $prices): array
    {
        $tcgdex = DB::table('card_price_snapshots')
            ->where('source', 'tcgplayer')->where('origin', 'tcgdex')
            ->where('captured_on', '>=', CarbonImmutable::today()->subDays(3)->toDateString())
            ->whereNotNull('market_minor')
            ->selectRaw('DISTINCT ON (card_id, variant) card_id, variant, market_minor')
            ->orderBy('card_id')->orderBy('variant')->orderByDesc('captured_on')
            ->get()
            ->mapWithKeys(fn ($s) => ["{$s->card_id}|{$s->variant}" => (int) $s->market_minor]);

        $diffs = [];
        $outliers = [];
        $gaps = 0;

        $links = CardTcgplayerLink::query()->whereNotNull('group_id')->get(['card_id', 'variant', 'product_id', 'sub_type', 'group_id']);
        foreach ($links as $link) {
            $row = $prices[(int) $link->group_id][$link->product_id][$link->sub_type] ?? null;
            if ($row === null || $row->marketMinor === null) {
                continue;
            }

            $theirs = $tcgdex["{$link->card_id}|{$link->variant}"] ?? null;
            if ($theirs === null || $theirs === 0) {
                $gaps++;

                continue;
            }

            $diff = abs($row->marketMinor - $theirs) / $theirs;
            $diffs[] = $diff;
            if ($diff > 0.25 && count($outliers) < 10) {
                $outliers[] = [$link->card_id, $link->variant];
            }
        }

        return [
            'compared' => count($diffs),
            'within_2_percent' => count(array_filter($diffs, fn (float $d) => $d <= 0.02)),
            'median_diff_percent' => $this->medianPercent($diffs),
            'over_25_percent' => $this->name($outliers),
            'linked_without_tcgdex_price' => $gaps,
        ];
    }

    /** @param  list<float>  $values */
    private function medianPercent(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $mid = intdiv(count($values), 2);
        $median = count($values) % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;

        return round($median * 100, 2);
    }

    /**
     * @param  list<array{int, string}>  $outliers  [card_id, variant]
     * @return list<string>
     */
    private function name(array $outliers): array
    {
        $ids = Card::whereIn('id', array_column($outliers, 0))->pluck('tcgdex_id', 'id');

        return array_map(fn (array $o) => ($ids[$o[0]] ?? "card {$o[0]}")." {$o[1]}", $outliers);
    }
}
