<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Catalog\Exceptions\MalformedCatalogResponseException;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Tcgcsv\TcgcsvClient;
use App\Modules\Catalog\Tcgcsv\TcgcsvPriceRow;
use App\Modules\Catalog\Tcgcsv\TcgplayerLinkDiscovery;
use App\Support\DiscordAlerter;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
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
 * Every run compares tcgcsv's price with the tcgdex-synced TCGplayer
 * price for each linked print and logs the result. In fill mode (the
 * default, config tcgcsv.mode) it also writes tcgcsv's price for the
 * linked prints tcgdex has no recent TCGplayer price for — the gaps —
 * and never touches a price tcgdex has or one entered by hand. Shadow
 * mode writes nothing; it is the rollback.
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

    public const BUILD_CACHE_KEY = 'tcgcsv:last_build';

    /** When the last run that fetched every group finished; CheckPricingFreshness watches it. */
    public const LAST_RUN_CACHE_KEY = 'tcgcsv:last_run';

    /** A build older than this is not stamped as today's price. */
    private const MAX_FILL_BUILD_AGE_HOURS = 36;

    /**
     * A gap price this many times above or below the print's recent tcgcsv
     * price, or its cardmarket price (a coarser bound: EUR, another
     * market), is held back as a likely wrong link or upstream glitch —
     * unless the move is under PLAUSIBLE_MOVE_MINOR, which a cheap print
     * can make without either. Only prices within the resolver's
     * staleness window count, so a real jump is held back for days at
     * most, never pinned to a price that is no longer shown.
     */
    private const MAX_RATIO_TO_LAST = 3.0;

    private const MAX_RATIO_TO_CARDMARKET = 4.0;

    private const PLAUSIBLE_MOVE_MINOR = 200;

    /** Marks a print whose gap price was held back, keyed by "card_id|variant". */
    private const HELD_CACHE_PREFIX = 'tcgcsv:held:';

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

        $mode = $this->mode();
        [$comparison, $gaps] = $this->compare($prices);
        $buildTooOld = CarbonImmutable::parse($build)->lt(now()->subHours(self::MAX_FILL_BUILD_AGE_HOURS));
        [$filled, $implausible, $implausibleCount, $unverified, $acceptedAfterHold] = $mode === 'fill' && ! $buildTooOld
            ? $this->fillGaps($gaps, $build)
            : [0, [], 0, [], []];

        $summary = [
            'mode' => $mode,
            'build' => $build,
            // A retry after a partial failure compares only the groups it
            // re-fetched, not the whole build.
            'partial_retry' => $partialRetry,
            'groups_ok' => count($fetched),
            'groups_fetched' => $fetched,
            'groups_failed' => count($failedGroups),
            'skipped_no_matching_subtype' => $skipped,
            ...$comparison,
            'build_too_old_to_fill' => $buildTooOld,
            'gaps_filled' => $filled,
            'gaps_implausible_count' => $implausibleCount,
            'gaps_implausible' => $this->outlierLabels($implausible),
            'gaps_unverified' => count($unverified),
            'gaps_unverified_prints' => $this->outlierLabels(array_slice($unverified, 0, 10)),
            'gaps_accepted_after_hold' => $this->outlierLabels(array_slice($acceptedAfterHold, 0, 10)),
        ];

        Log::channel('tcgcsv')->info('tcgcsv sync', $summary);
        if ($implausibleCount > 0) {
            app(DiscordAlerter::class)->send("⚠️ tcg-vault: tcgcsv held back {$implausibleCount} implausible TCGplayer price(s) — check their links: ".implode(', ', $summary['gaps_implausible']));
        }
        if ($acceptedAfterHold !== []) {
            app(DiscordAlerter::class)->send('ℹ️ tcg-vault: tcgcsv wrote '.count($acceptedAfterHold).' TCGplayer price(s) after a hold expired — check their links if nobody did: '.implode(', ', $summary['gaps_accepted_after_hold']));
        }
        // Kept for reviewing tcgcsv against tcgdex even after logs rotate.
        $day = 'tcgcsv:shadow:'.now()->toDateString();
        Cache::put($day, [...Cache::get($day, []), $summary], now()->addDays(30));

        if ($failedGroups !== []) {
            // Some groups are missing from this build; try them again later.
            $this->retryLaterOrStop();

            return;
        }

        Cache::forever(self::LAST_RUN_CACHE_KEY, now()->toIso8601String());
    }

    /**
     * 'fill' only when set to exactly that; anything else compares and
     * writes nothing, so a typo in the rollback switch fails safe.
     */
    private function mode(): string
    {
        $mode = config('tcgcsv.mode');
        // Once a day: retries would otherwise repeat it every hour.
        if ($mode !== 'fill' && $mode !== 'shadow' && Cache::add('tcgcsv:mode_warned:'.now()->toDateString(), true, now()->addDay())) {
            Log::channel('tcgcsv')->warning('unknown TCGCSV_MODE; running in shadow mode', ['mode' => is_scalar($mode) ? (string) $mode : gettype($mode)]);
        }

        return $mode === 'fill' ? 'fill' : 'shadow';
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
     * the gaps tcgcsv fills, returned alongside the stats. Covers only the
     * groups this run fetched.
     *
     * @param  array<int, array<int, array<string, TcgcsvPriceRow>>>  $prices
     * @return array{array<string, mixed>, list<array{CardTcgplayerLink, TcgcsvPriceRow}>}
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
        $gaps = [];

        $links = CardTcgplayerLink::query()->whereNotNull('group_id')->get(['card_id', 'variant', 'product_id', 'sub_type', 'group_id', 'method']);
        foreach ($links as $link) {
            $row = $prices[(int) $link->group_id][$link->product_id][$link->sub_type] ?? null;
            if ($row === null || $row->marketMinor === null || $row->marketMinor <= 0) {
                continue;
            }

            $theirs = $tcgdex["{$link->card_id}|{$link->variant}"] ?? null;
            if ($theirs === null || $theirs === 0) {
                $gaps[] = [$link, $row];

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

        return [[
            'compared' => count($diffs),
            'within_2_percent' => count(array_filter($diffs, fn (float $d) => $d <= 0.02)),
            'median_diff_percent' => $this->medianPercent($diffs),
            'over_25_percent_count' => $outlierCount,
            'over_25_percent' => $this->outlierLabels($outliers),
            'linked_without_tcgdex_price' => count($gaps),
        ], $gaps];
    }

    /**
     * Writes tcgcsv's price as today's TCGplayer price for each gap,
     * except an implausible one (see MAX_RATIO_TO_LAST), or one whose link
     * only tcgdex's third-party ids vouch for (TRUST 1, served
     * inconsistently) while no recent price exists to check it against —
     * those are counted as unverified. A held-back print is remembered for
     * a week (HELD_CACHE_PREFIX), so a price written once its hold has
     * expired, with nothing left to check it against, is reported rather
     * than going live unnoticed. A row tcgdex or a
     * person wrote for today is never overwritten — tcgdex's sync may land
     * between the comparison and this write — so only a missing row or
     * tcgcsv's own earlier one for today is set.
     *
     * @param  list<array{CardTcgplayerLink, TcgcsvPriceRow}>  $gaps
     * @return array{int, list<array{int, string}>, int, list<array{int, string}>, list<array{int, string}>} filled, the first implausible [card_id, variant], how many were implausible, the unverified, the accepted after a hold
     */
    private function fillGaps(array $gaps, string $build): array
    {
        $today = CarbonImmutable::today();
        $cardIds = array_values(array_unique(array_map(fn (array $gap) => $gap[0]->card_id, $gaps)));
        $since = $today->subDays(CardPriceResolver::STALE_AFTER_DAYS)->toDateString();
        $lastTcgcsv = $this->latestMarket($cardIds, fn ($q) => $q->where('source', 'tcgplayer')->where('origin', 'tcgcsv')
            ->where('captured_on', '>=', $since)->where('captured_on', '<', $today->toDateString()));
        $cardmarket = $this->latestMarket($cardIds, fn ($q) => $q->where('source', 'cardmarket')->where('captured_on', '>=', $since));
        $updatedAt = CarbonImmutable::parse($build)->utc();

        $filled = 0;
        $implausible = [];
        $implausibleCount = 0;
        $unverified = [];
        $acceptedAfterHold = [];

        foreach ($gaps as [$link, $row]) {
            $key = "{$link->card_id}|{$link->variant}";
            $market = (int) $row->marketMinor;
            $references = [$lastTcgcsv[$key] ?? null, $cardmarket[$key] ?? null];
            if ($references === [null, null] && (CardTcgplayerLink::TRUST[$link->method] ?? 0) < CardTcgplayerLink::TRUST['tcgdex-price']) {
                $unverified[] = [(int) $link->card_id, (string) $link->variant];

                continue;
            }
            // An admin-confirmed link skips the cardmarket bound: cardmarket
            // often files a stamped print (staff, Pokémon Center) under its
            // plain one, so the gap there is real, not a wrong link.
            if ($this->farFrom($market, $lastTcgcsv[$key] ?? null, self::MAX_RATIO_TO_LAST)
                || ($link->method !== 'admin' && $this->farFrom($market, $cardmarket[$key] ?? null, self::MAX_RATIO_TO_CARDMARKET))) {
                $implausibleCount++;
                Cache::put(self::HELD_CACHE_PREFIX.$key, true, now()->addDays(7));
                if (count($implausible) < 10) {
                    $implausible[] = [(int) $link->card_id, (string) $link->variant];
                }

                continue;
            }

            $snapshot = CardPriceSnapshot::firstOrNew([
                'card_id' => $link->card_id,
                'source' => 'tcgplayer',
                'variant' => $link->variant,
                'captured_on' => $today->toDateString(),
            ]);
            if ($snapshot->exists && $snapshot->origin !== 'tcgcsv') {
                continue;
            }

            $snapshot->fill([
                'origin' => 'tcgcsv',
                'currency' => 'USD',
                'market_minor' => $market,
                'low_minor' => $row->lowMinor,
                'trend_minor' => null,
                'source_updated_at' => $updatedAt,
                'raw' => ['productId' => $row->productId, 'groupId' => $row->groupId, 'subType' => $row->subType, 'build' => $build],
            ]);

            try {
                $snapshot->save();
            } catch (UniqueConstraintViolationException) {
                // tcgdex wrote this print's row for today after the lookup; it wins.
                continue;
            }
            $filled++;
            // Reported only when nothing was left to check it against — a
            // price that came back in line passed its check.
            if (Cache::pull(self::HELD_CACHE_PREFIX.$key) !== null && ($lastTcgcsv[$key] ?? null) === null && ($cardmarket[$key] ?? null) === null) {
                $acceptedAfterHold[] = [(int) $link->card_id, (string) $link->variant];
            }
        }

        return [$filled, $implausible, $implausibleCount, $unverified, $acceptedAfterHold];
    }

    /**
     * The newest priced market_minor per "card_id|variant" among the given
     * cards' snapshots that $scope selects.
     *
     * @param  list<int>  $cardIds
     * @param  callable(Builder): mixed  $scope
     * @return array<string, int>
     */
    private function latestMarket(array $cardIds, callable $scope): array
    {
        if ($cardIds === []) {
            return [];
        }

        $query = DB::table('card_price_snapshots')->whereIn('card_id', $cardIds)->whereNotNull('market_minor');
        $scope($query);

        return $query->selectRaw('DISTINCT ON (card_id, variant) card_id, variant, market_minor')
            ->orderBy('card_id')->orderBy('variant')->orderByDesc('captured_on')
            ->get()
            ->mapWithKeys(fn ($s) => ["{$s->card_id}|{$s->variant}" => (int) $s->market_minor])
            ->all();
    }

    /** Whether $price is more than $ratio times off $reference, by more than a cheap print's move. */
    private function farFrom(int $price, ?int $reference, float $ratio): bool
    {
        if ($reference === null || $reference <= 0 || abs($price - $reference) <= self::PLAUSIBLE_MOVE_MINOR) {
            return false;
        }

        return $price > $reference * $ratio || $price * $ratio < $reference;
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
