<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Catalog\Exceptions\MalformedCatalogResponseException;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Services\CardPriceResolver;
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
            Log::channel('tcgcsv')->warning('tcgcsv build check failed; retrying later', ['error' => class_basename($e)]);
            $this->retryLaterOrStop();

            return;
        }

        if (Cache::get(self::BUILD_CACHE_KEY) === $build) {
            // Never re-pull a build already fetched (tcgcsv's guidance).
            $this->retryLaterOrStop();

            return;
        }

        [$prices, $fetched, $failedGroups, $partialRetry] = $this->fetchPrices($client, $build);

        if ($failedGroups === []) {
            // Every group has this build; recorded before linking, so a
            // later failure never re-pulls it.
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

        $summary = [
            'build' => $build,
            // A retry after a partial failure compares only the groups it
            // re-fetched, not the whole build.
            'partial_retry' => $partialRetry,
            'groups_ok' => count($fetched),
            'groups_fetched' => $fetched,
            'groups_failed' => count($failedGroups),
            'skipped_no_matching_subtype' => $skipped,
            ...$this->compare($prices),
        ];

        Log::channel('tcgcsv')->info('tcgcsv shadow sync', $summary);
        // Kept for the shadow-phase review even after logs rotate.
        $day = 'tcgcsv:shadow:'.now()->toDateString();
        Cache::put($day, [...Cache::get($day, []), $summary], now()->addDays(30));

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
     * This build's prices for every configured group not already fetched
     * from it, groupId → productId → subType → row. Each group remembers
     * the build it last fetched, so a retry after a partial failure pulls
     * only the groups that failed. A group that fails for an expected
     * reason is skipped and named; a throttle (429) or a lost connection
     * stops the loop, since every later request would fail the same way,
     * and the remaining groups wait for the hourly retry. Anything else is
     * a bug and propagates.
     *
     * @return array{array<int, array<int, array<string, TcgcsvPriceRow>>>, list<int>, list<int>, bool}
     */
    private function fetchPrices(TcgcsvClient $client, string $build): array
    {
        $all = DB::table('set_tcgplayer_groups')->distinct()->pluck('group_id')
            ->merge(CardTcgplayerLink::whereNotNull('group_id')->distinct()->pluck('group_id'))
            ->map(fn ($g) => (int) $g)->unique()->sort()->values();
        $groups = $all->reject(fn (int $g) => Cache::get("tcgcsv:group:{$g}") === $build)->values();

        $prices = [];
        $fetched = [];
        $failed = [];

        foreach ($groups as $i => $groupId) {
            if ($i > 0) {
                usleep(self::PAUSE_MICROSECONDS);
            }

            try {
                foreach ($client->prices($groupId) as $row) {
                    $prices[$groupId][$row->productId][$row->subType] = $row;
                }
                Cache::put("tcgcsv:group:{$groupId}", $build, now()->addDays(2));
                $fetched[] = $groupId;
            } catch (ConnectionException|RequestException|UnexpectedValueException|MalformedCatalogResponseException $e) {
                Log::channel('tcgcsv')->warning('tcgcsv group failed', ['group' => $groupId, 'error' => class_basename($e)]);
                $failed[] = $groupId;

                if ($e instanceof ConnectionException || ($e instanceof RequestException && $e->response->status() === 429)) {
                    // Stop: tcgcsv throttles a client for 10 minutes, and a
                    // dropped connection won't come back mid-loop.
                    $failed = [...$failed, ...$groups->slice($i + 1)->values()->all()];

                    break;
                }
            }
        }

        return [$prices, $fetched, $failed, $groups->count() < $all->count()];
    }

    /**
     * How tcgcsv's price for each linked print compares with the latest
     * tcgdex-synced TCGplayer price for it (within the resolver's staleness
     * window), and how many linked prints tcgdex doesn't price at all —
     * the gaps tcgcsv would fill. Covers only the groups this run fetched.
     *
     * @param  array<int, array<int, array<string, TcgcsvPriceRow>>>  $prices
     * @return array<string, mixed>
     */
    private function compare(array $prices): array
    {
        $tcgdex = DB::table('card_price_snapshots')
            ->where('source', 'tcgplayer')->where('origin', 'tcgdex')
            ->where('captured_on', '>=', CarbonImmutable::today()->subDays(CardPriceResolver::STALE_AFTER_DAYS)->toDateString())
            ->whereNotNull('market_minor')
            ->selectRaw('DISTINCT ON (card_id, variant) card_id, variant, market_minor')
            ->orderBy('card_id')->orderBy('variant')->orderByDesc('captured_on')
            ->get()
            ->mapWithKeys(fn ($s) => ["{$s->card_id}|{$s->variant}" => (int) $s->market_minor]);

        $diffs = [];
        $outliers = [];
        $outlierCount = 0;
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
            if ($diff > 0.25) {
                $outlierCount++;
                if (count($outliers) < 10) {
                    $outliers[] = [$link->card_id, $link->variant];
                }
            }
        }

        return [
            'compared' => count($diffs),
            'within_2_percent' => count(array_filter($diffs, fn (float $d) => $d <= 0.02)),
            'median_diff_percent' => $this->medianPercent($diffs),
            'over_25_percent_count' => $outlierCount,
            'over_25_percent' => $this->outlierLabels($outliers),
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
     * The first outliers as "tcgdex id + variant" labels for the log.
     *
     * @param  list<array{int, string}>  $outliers  [card_id, variant]
     * @return list<string>
     */
    private function outlierLabels(array $outliers): array
    {
        $ids = Card::whereIn('id', array_column($outliers, 0))->pluck('tcgdex_id', 'id');

        return array_map(fn (array $o) => ($ids[$o[0]] ?? "card {$o[0]}")." {$o[1]}", $outliers);
    }
}
