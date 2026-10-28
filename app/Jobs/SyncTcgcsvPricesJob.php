<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Catalog\Exceptions\MalformedCatalogResponseException;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Services\CardPriceResolver;
use App\Modules\Catalog\Tcgcsv\IdentityOutcome;
use App\Modules\Catalog\Tcgcsv\TcgcsvClient;
use App\Modules\Catalog\Tcgcsv\TcgcsvExtraGroups;
use App\Modules\Catalog\Tcgcsv\TcgcsvFallbackPrices;
use App\Modules\Catalog\Tcgcsv\TcgcsvPriceRow;
use App\Modules\Catalog\Tcgcsv\TcgplayerIdentityMatcher;
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
use Throwable;
use UnexpectedValueException;

/**
 * Pulls each linked set's TCGplayer prices from tcgcsv once per tcgcsv
 * build (about one request per group) and links prints to products.
 *
 * Every run compares tcgcsv's price with the tcgdex-synced TCGplayer
 * price for each linked print and logs the result. In fill mode (the
 * default, config tcgcsv.mode) it also writes tcgcsv's price for the
 * linked prints tcgdex sent no current TCGplayer price for today — the
 * gaps — and never touches a price tcgdex has or one entered by hand. It
 * also keeps each price that passes its checks (TcgcsvFallbackPrices) so the
 * next tcgdex sync leaves that print's row to this run when tcgdex's price is
 * missing or stale. Only a build from today's UTC day is worked in fill
 * mode. Shadow mode writes and keeps nothing; it is the rollback.
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

    /**
     * A gap price this many times above or below the print's recent tcgcsv
     * price, or tcgdex's last TCGplayer price for it (see
     * TCGDEX_REFERENCE_DAYS), or its cardmarket price (a coarser bound:
     * EUR, another market), is held back as a likely wrong link or upstream glitch —
     * unless the move is under PLAUSIBLE_MOVE_MINOR, which a cheap print
     * can make without either. Only prices within the resolver's
     * staleness window count, so a real jump is held back for days at
     * most, never pinned to a price that is no longer shown.
     */
    private const MAX_RATIO_TO_LAST = 3.0;

    private const MAX_RATIO_TO_CARDMARKET = 4.0;

    /**
     * How long tcgdex's last TCGplayer price, dated by TCGplayer's own
     * update, still serves as a reference for a gap price. A frozen price
     * tcgdex keeps re-sending is judged by when TCGplayer last updated it,
     * not when we stored it, so it stops holding back real moves after
     * this many days.
     */
    private const TCGDEX_REFERENCE_DAYS = 14;

    /**
     * An admin-confirmed link gets a wider cardmarket bound: cardmarket
     * often files a stamped print (staff, Pokémon Center) under its plain
     * one, so a few times off is real there, but an upstream glitch of
     * an order of magnitude or more is still held back.
     */
    private const MAX_RATIO_TO_CARDMARKET_ADMIN = 15.0;

    /**
     * The bound against the print's last tcgcsv price once it is older than
     * the resolver's staleness window: a real jump is accepted after a few
     * days, an upstream glitch of an order of magnitude or more is not.
     */
    private const MAX_RATIO_TO_OLD_TCGCSV = 15.0;

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
     * An unpublished build (or one from an earlier day, in fill mode) is
     * retried until late evening; after that the day gets no tcgcsv row and
     * the print keeps its previous one. A run started later still gets one
     * hour.
     */
    public function retryUntil(): DateTimeInterface
    {
        $cutoff = now()->setTime(23, 30);

        return now()->lt($cutoff) ? $cutoff : now()->addHour();
    }

    public function handle(TcgcsvClient $client, TcgplayerLinkDiscovery $discovery, TcgplayerIdentityMatcher $matcher): void
    {
        $identityMode = $this->identityMode();
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

        // A row is dated with the UTC day it was read, so a build from an
        // earlier day (published after the last retry, or a retry that crosses
        // midnight) would show that day's prices as today's. It is left
        // unrecorded, so today's build is still pulled when it appears.
        // Shadow mode writes nothing, so an old build is compared as usual.
        if ($this->mode() === 'fill' && CarbonImmutable::parse($build)->utc()->lt(CarbonImmutable::today('UTC'))) {
            Log::channel('tcgcsv')->warning('tcgcsv build is from an earlier day; waiting for today\'s', ['build' => $build]);
            $this->retryLaterOrStop();

            return;
        }

        [$prices, $fetched, $failedGroups, $partialRetry, $identities] = $this->fetchPrices($client, $build, $identityMode === 'log');

        if ($failedGroups === []) {
            // Every group has this build; recorded before linking, so a
            // later failure never re-pulls it.
            Cache::forever(self::BUILD_CACHE_KEY, $build);
        } else {
            // One report per run, not one per group.
            report(new RuntimeException('tcgcsv price groups failed: '.implode(', ', $failedGroups)));
        }

        $skipped = 0;
        $identityOutcomes = [];
        $setIds = DB::table('set_tcgplayer_groups')->distinct()->pluck('set_id');
        Card::whereIn('set_id', $setIds)->chunkById(200, function ($cards) use ($discovery, $matcher, $identityMode, $prices, $identities, &$skipped, &$identityOutcomes) {
            foreach ($cards as $card) {
                $skipped += $discovery->discover($card, $prices, $identities);

                // After discovery, so a print's existing link is the one just
                // confirmed. Judges only; nothing it finds is stored.
                if ($identityMode === 'log') {
                    try {
                        array_push($identityOutcomes, ...$matcher->evaluate($card, $prices, $identities));
                    } catch (Throwable $e) {
                        // A report that fails must not cost the day's price fill.
                        Log::channel('tcgcsv')->warning('identity matcher failed', ['card' => $card->tcgdex_id, 'error' => class_basename($e)]);
                        $identityOutcomes[] = new IdentityOutcome($card->tcgdex_id, '*', IdentityOutcome::REFUSED, 'matcher-error');
                    }
                }
            }
        });

        $mode = $this->mode();
        [$comparison, $gaps, $priced] = $this->compare($prices);
        [$filled, $implausible, $implausibleCount, $unverified, $acceptedAfterHold, $kept, $rejected, $verdictShadow] = $mode === 'fill'
            ? $this->fillGaps($gaps, $priced, $build)
            : [0, [], 0, [], [], 0, [], []];

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
            'link_conflicts' => count($discovery->conflicts()),
            'link_conflicts_prints' => array_slice($discovery->conflicts(), 0, 10),
            ...$comparison,
            'gaps_filled' => $filled,
            'fallback_kept' => $kept,
            'fallback_rejected_count' => count($rejected),
            'fallback_rejected_prints' => $this->outlierLabels(array_slice($rejected, 0, 10)),
            'gaps_implausible_count' => $implausibleCount,
            'gaps_implausible' => $this->outlierLabels($implausible),
            'gaps_unverified' => count($unverified),
            'gaps_unverified_prints' => $this->outlierLabels(array_slice($unverified, 0, 10)),
            'gaps_accepted_after_hold' => $this->outlierLabels(array_slice($acceptedAfterHold, 0, 10)),
            'verdict_shadow_count' => count($verdictShadow),
            'verdict_shadow_prints' => $this->verdictShadowLabels(array_slice($verdictShadow, 0, 10)),
            ...($identityMode === 'log' ? ['identity' => $this->identitySummary($identityOutcomes, $partialRetry)] : []),
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

    /**
     * 'log' only when set to exactly that: the identity matcher judges every
     * base print and reports what it would link, and writes nothing. Anything
     * else leaves it off, so a typo in the switch fails safe.
     */
    private function identityMode(): string
    {
        return config('tcgcsv.identity_links') === 'log' ? 'log' : 'off';
    }

    /**
     * What the identity matcher concluded over the run: how many prints agree
     * with their link, would get a different product, or would get a new link,
     * and every refusal by reason, with the first few of each named.
     *
     * A retry that re-fetched only some groups refuses the cards of the rest,
     * so its block is not comparable with a full run's.
     *
     * @param  list<IdentityOutcome>  $outcomes
     * @return array<string, mixed>
     */
    private function identitySummary(array $outcomes, bool $partialRetry): array
    {
        $named = fn (string $status) => array_values(array_filter($outcomes, fn (IdentityOutcome $o) => $o->status === $status));
        $labels = fn (array $list) => array_map(fn (IdentityOutcome $o) => $o->label(), array_slice($list, 0, 10));
        $found = fn (IdentityOutcome $o) => "{$o->cardId} {$o->variant} → {$o->productId} {$o->subType}";
        $new = $named(IdentityOutcome::NEW);
        $different = $named(IdentityOutcome::DIFFERENT);
        $refused = $named(IdentityOutcome::REFUSED);

        $reasons = [];
        foreach ($refused as $o) {
            $reasons[(string) $o->reason] = ($reasons[(string) $o->reason] ?? 0) + 1;
        }
        ksort($reasons);

        return [
            'mode' => 'log',
            'partial_retry' => $partialRetry,
            'agree' => count($named(IdentityOutcome::AGREE)),
            'different' => count($different),
            'new' => count($new),
            'refused' => count($refused),
            'refused_reasons' => $reasons,
            'new_prints' => array_map($found, array_slice($new, 0, 10)),
            'different_prints' => array_map($found, array_slice($different, 0, 10)),
            'refused_prints' => $labels($refused),
        ];
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
     * The extra groups (config tcgcsv.extra_groups) are fetched too, with
     * their products' names and numbers, which identify a product for a card
     * no set maps it to; a group whose products can't be read counts as
     * failed as a whole.
     *
     * @return array{array<int, array<int, array<string, TcgcsvPriceRow>>>, list<int>, list<int>, bool, array<int, array<int, array{name: string, number: ?string}>>}
     */
    private function fetchPrices(TcgcsvClient $client, string $build, bool $identityLog = false): array
    {
        $extraGroups = collect(TcgcsvExtraGroups::ids());
        $all = DB::table('set_tcgplayer_groups')->distinct()->pluck('group_id')
            ->merge(CardTcgplayerLink::whereNotNull('group_id')->distinct()->pluck('group_id'))
            ->merge($extraGroups)
            ->map(fn ($g) => (int) $g)->unique()->sort()->values();
        $groups = $all->reject(fn (int $g) => Cache::get("tcgcsv:group:{$g}") === $build)->values();

        $prices = [];
        $identities = [];
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
                if ($extraGroups->contains($groupId)) {
                    usleep(self::PAUSE_MICROSECONDS);
                    $identities[$groupId] = $client->products($groupId);
                } elseif ($identityLog) {
                    usleep(self::PAUSE_MICROSECONDS);
                    $identities = $this->setIdentities($client, $groupId, $identities);
                }
                Cache::put("tcgcsv:group:{$groupId}", $build, now()->addDays(2));
                $fetched[] = $groupId;
            } catch (ConnectionException|RequestException|UnexpectedValueException|MalformedCatalogResponseException $e) {
                Log::channel('tcgcsv')->warning('tcgcsv group failed', ['group' => $groupId, 'error' => class_basename($e)]);
                $failed[] = $groupId;
                // Prices read before the products failed would be linked and
                // priced without an identity check; the retry re-reads both.
                unset($prices[$groupId]);

                if ($e instanceof ConnectionException || ($e instanceof RequestException && $e->response->status() === 429)) {
                    // Stop: tcgcsv throttles a client for 10 minutes, and a
                    // dropped connection won't come back mid-loop.
                    $failed = [...$failed, ...$groups->slice($i + 1)->values()->all()];

                    break;
                }
            }
        }

        return [$prices, $fetched, $failed, $groups->count() < $all->count(), $identities];
    }

    /**
     * A mapped group's products, for the identity matcher. Unlike an extra
     * group's, a failure here costs only the matcher that group's cards (it
     * refuses them): the group's prices are used as before. A throttle or a
     * lost connection still propagates, to stop the loop as for prices.
     *
     * @param  array<int, array<int, array{name: string, number: ?string}>>  $identities
     * @return array<int, array<int, array{name: string, number: ?string}>>
     */
    private function setIdentities(TcgcsvClient $client, int $groupId, array $identities): array
    {
        try {
            $identities[$groupId] = $client->products($groupId);
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw $e;
            }
            Log::channel('tcgcsv')->warning('tcgcsv products failed; identity matcher skips the group', ['group' => $groupId, 'error' => class_basename($e)]);
        } catch (UnexpectedValueException|MalformedCatalogResponseException $e) {
            Log::channel('tcgcsv')->warning('tcgcsv products failed; identity matcher skips the group', ['group' => $groupId, 'error' => class_basename($e)]);
        }

        return $identities;
    }

    /**
     * How tcgcsv's price for each linked print compares with the TCGplayer
     * price tcgdex synced for it today (last updated by TCGplayer within the
     * resolver's staleness window — a frozen price tcgdex keeps re-sending is
     * not a current one), and how many linked prints tcgdex sent no price for
     * today — the gaps tcgcsv fills, returned alongside the stats and the
     * linked prints tcgdex does price. Yesterday's tcgdex row doesn't count:
     * the midnight sync writes nothing for a print tcgdex stopped pricing, so
     * the evening run is what keeps it current. A row with no TCGplayer date
     * is judged by its capture day alone. Covers only the groups this run
     * fetched.
     *
     * @param  array<int, array<int, array<string, TcgcsvPriceRow>>>  $prices
     * @return array{array<string, mixed>, list<array{CardTcgplayerLink, TcgcsvPriceRow}>, list<array{CardTcgplayerLink, TcgcsvPriceRow}>}
     */
    private function compare(array $prices): array
    {
        $current = CarbonImmutable::today()->subDays(CardPriceResolver::STALE_AFTER_DAYS);
        $tcgdex = DB::table('card_price_snapshots')
            ->where('source', 'tcgplayer')->where('origin', 'tcgdex')
            ->where('captured_on', '>=', CarbonImmutable::today()->toDateString())
            ->where(fn ($q) => $q->whereNull('source_updated_at')->orWhere('source_updated_at', '>=', $current))
            ->whereNotNull('market_minor')
            ->selectRaw('DISTINCT ON (card_id, variant) card_id, variant, market_minor')
            ->orderBy('card_id')->orderBy('variant')->orderByDesc('captured_on')
            ->get()
            ->mapWithKeys(fn ($s) => ["{$s->card_id}|{$s->variant}" => (int) $s->market_minor]);

        $diffs = [];
        $outliers = [];
        $outlierCount = 0;
        $gaps = [];
        $priced = [];

        $links = CardTcgplayerLink::query()->whereNotNull('group_id')->get(['card_id', 'variant', 'product_id', 'sub_type', 'group_id', 'method', 'verified_at']);
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

            $priced[] = [$link, $row];
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
        ], $gaps, $priced];
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
     * A gap whose row for today tcgdex or a person already wrote is left
     * alone before any check, so it is neither held back nor reported for a
     * price that would not be written anyway. Every price that passes its
     * checks, a gap's or that of a print tcgdex does price, is also kept
     * for the next tcgdex sync to fall back on (TcgcsvFallbackPrices); only
     * a gap's check is held back and reported.
     *
     * @param  list<array{CardTcgplayerLink, TcgcsvPriceRow}>  $gaps
     * @param  list<array{CardTcgplayerLink, TcgcsvPriceRow}>  $priced  linked prints tcgdex does price
     * @return array{int, list<array{int, string}>, int, list<array{int, string}>, list<array{int, string}>, int, list<array{int, string}>, list<array{int, string, string, string}>} filled, the first implausible [card_id, variant], how many were implausible, the unverified, the accepted after a hold, how many prices were kept, the prices rejected for a print tcgdex already wrote today, the prints whose verdict differs between the checks and the ones without cardmarket [card_id, variant, verdict, verdict without cardmarket]
     */
    private function fillGaps(array $gaps, array $priced, string $build): array
    {
        $today = CarbonImmutable::today();
        $fallback = app(TcgcsvFallbackPrices::class);
        $cardIds = array_values(array_unique(array_map(fn (array $pair) => $pair[0]->card_id, [...$gaps, ...$priced])));
        $since = $today->subDays(CardPriceResolver::STALE_AFTER_DAYS)->toDateString();
        $lastTcgcsv = $this->latestMarket($cardIds, fn ($q) => $q->where('source', 'tcgplayer')->where('origin', 'tcgcsv')
            ->where('captured_on', '>=', $since)->where('captured_on', '<', $today->toDateString()));
        $cardmarket = $this->withCardmarketReferences(
            $this->latestMarket($cardIds, fn ($q) => $q->where('source', 'cardmarket')->where('captured_on', '>=', $since)),
            [...$gaps, ...$priced],
        );
        $lastTcgdex = $this->latestMarket($cardIds, fn ($q) => $q->where('source', 'tcgplayer')->where('origin', 'tcgdex')
            ->where('source_updated_at', '>=', $today->subDays(self::TCGDEX_REFERENCE_DAYS)));
        $lastTcgcsvAny = $this->latestMarket($cardIds, fn ($q) => $q->where('source', 'tcgplayer')->where('origin', 'tcgcsv')
            ->where('captured_on', '<', $today->toDateString()));
        $writtenToday = DB::table('card_price_snapshots')
            ->whereIn('card_id', $cardIds)->where('source', 'tcgplayer')
            ->where('captured_on', $today->toDateString())->where('origin', '<>', 'tcgcsv')
            ->get(['card_id', 'variant'])
            ->mapWithKeys(fn ($s) => ["{$s->card_id}|{$s->variant}" => true]);

        $filled = 0;
        $implausible = [];
        $implausibleCount = 0;
        $unverified = [];
        $acceptedAfterHold = [];
        $keep = [];
        $rejected = [];
        $verdictShadow = [];
        $shadow = function (CardTcgplayerLink $link, int $market, string $verdict) use (&$verdictShadow, $lastTcgcsv, $lastTcgcsvAny, $lastTcgdex): void {
            $key = "{$link->card_id}|{$link->variant}";
            $without = $this->verdictWithoutCardmarket($link, $market, $lastTcgcsv[$key] ?? null, $lastTcgcsvAny[$key] ?? null, $lastTcgdex[$key] ?? null);
            if ($without !== $verdict) {
                $verdictShadow[] = [(int) $link->card_id, (string) $link->variant, $verdict, $without];
            }
        };

        foreach ($gaps as [$link, $row]) {
            $key = "{$link->card_id}|{$link->variant}";
            $market = (int) $row->marketMinor;
            $entry = $fallback->entryFor($row, $build);
            if ($entry === null) {
                continue;
            }
            $verdict = $this->verdict($link, $market, $lastTcgcsv[$key] ?? null, $lastTcgdex[$key] ?? null, $cardmarket[$key] ?? null);
            $shadow($link, $market, $verdict);
            if (isset($writtenToday[$key])) {
                if ($verdict === 'accept') {
                    $keep[] = [(int) $link->card_id, (string) $link->variant, $row];
                } else {
                    $rejected[] = [(int) $link->card_id, (string) $link->variant];
                }

                continue;
            }
            if ($verdict === 'unverified') {
                $unverified[] = [(int) $link->card_id, (string) $link->variant];

                continue;
            }
            if ($verdict === 'implausible') {
                $implausibleCount++;
                Cache::put(self::HELD_CACHE_PREFIX.$key, true, now()->addDays(7));
                if (count($implausible) < 10) {
                    $implausible[] = [(int) $link->card_id, (string) $link->variant];
                }

                continue;
            }
            $keep[] = [(int) $link->card_id, (string) $link->variant, $row];

            $snapshot = CardPriceSnapshot::firstOrNew([
                'card_id' => $link->card_id,
                'source' => 'tcgplayer',
                'variant' => $link->variant,
                'captured_on' => $today->toDateString(),
            ]);
            if ($snapshot->exists && $snapshot->origin !== 'tcgcsv') {
                continue;
            }

            $snapshot->fill($fallback->snapshotAttributes($entry));

            try {
                $snapshot->save();
            } catch (UniqueConstraintViolationException) {
                // tcgdex wrote this print's row for today after the lookup; it wins.
                continue;
            }
            $filled++;
            // Reported only when nothing was left to check it against — a
            // price that came back in line passed its check.
            if (Cache::pull(self::HELD_CACHE_PREFIX.$key) !== null && ($lastTcgcsv[$key] ?? null) === null && ($lastTcgdex[$key] ?? null) === null && ($cardmarket[$key] ?? null) === null) {
                $acceptedAfterHold[] = [(int) $link->card_id, (string) $link->variant];
            }
        }

        foreach ($priced as [$link, $row]) {
            $key = "{$link->card_id}|{$link->variant}";
            $verdict = $this->verdict($link, (int) $row->marketMinor, $lastTcgcsv[$key] ?? null, $lastTcgdex[$key] ?? null, $cardmarket[$key] ?? null);
            $shadow($link, (int) $row->marketMinor, $verdict);
            if ($verdict === 'accept') {
                $keep[] = [(int) $link->card_id, (string) $link->variant, $row];
            }
        }

        // A print tcgdex already wrote today can't be held back or written,
        // but a price rejected for it is counted, so a frozen tcgdex row
        // that keeps being written doesn't go unnoticed.
        return [$filled, $implausible, $implausibleCount, $unverified, $acceptedAfterHold, $fallback->putMany($keep, $build), $rejected, $verdictShadow];
    }

    /**
     * The verdict without the cardmarket reference (EUR, usually another
     * print's price): a link only tcgdex's third-party ids vouch for needs a
     * tcgcsv or tcgdex price of the print, of any age, to check against; the
     * bound against tcgcsv's last price is 3x while that price is recent and
     * wider once it is not, so a held jump is accepted after a few days.
     * Run beside the verdict so the two can be compared before one replaces
     * the other.
     *
     * @return 'accept'|'implausible'|'unverified'
     */
    private function verdictWithoutCardmarket(CardTcgplayerLink $link, int $market, ?int $lastTcgcsv, ?int $lastTcgcsvAny, ?int $lastTcgdex): string
    {
        $vouched = $link->method === 'admin' || $this->identityVerified($link);

        if (! $vouched && $lastTcgcsvAny === null && $lastTcgdex === null && (CardTcgplayerLink::TRUST[$link->method] ?? 0) < CardTcgplayerLink::TRUST['tcgdex-price']) {
            return 'unverified';
        }

        if ($this->farFrom($market, $lastTcgcsv, self::MAX_RATIO_TO_LAST)
            || $this->farFrom($market, $lastTcgdex, self::MAX_RATIO_TO_LAST)
            || ($lastTcgcsv === null && $this->farFrom($market, $lastTcgcsvAny, self::MAX_RATIO_TO_OLD_TCGCSV))) {
            return 'implausible';
        }

        return 'accept';
    }

    /**
     * @param  list<array{int, string, string, string}>  $differences
     * @return list<string>
     */
    private function verdictShadowLabels(array $differences): array
    {
        $labels = $this->outlierLabels(array_map(fn (array $d) => [$d[0], $d[1]], $differences));

        return array_map(fn (string $label, array $d) => "{$label} {$d[2]}→{$d[3]}", $labels, $differences);
    }

    /**
     * Whether a tcgcsv price may be used: 'unverified' when its link only
     * tcgdex's third-party ids vouch for has no recent tcgcsv or cardmarket
     * price to check it against, 'implausible' when it is far from any
     * reference (see MAX_RATIO_TO_LAST), otherwise 'accept'.
     *
     * A link to an extra group's product whose identity was verified (name
     * and number match the card's) is vouched for like an admin's: it is
     * never 'unverified', and gets the wider cardmarket bound. Its
     * cardmarket reference is the print's own average (withCardmarketReferences),
     * the one on file for the card being the main product's.
     *
     * @return 'accept'|'implausible'|'unverified'
     */
    private function verdict(CardTcgplayerLink $link, int $market, ?int $lastTcgcsv, ?int $lastTcgdex, ?int $cardmarket): string
    {
        $vouched = $link->method === 'admin' || $this->identityVerified($link);

        if (! $vouched && $lastTcgcsv === null && $cardmarket === null && (CardTcgplayerLink::TRUST[$link->method] ?? 0) < CardTcgplayerLink::TRUST['tcgdex-price']) {
            return 'unverified';
        }

        $cardmarketRatio = $vouched ? self::MAX_RATIO_TO_CARDMARKET_ADMIN : self::MAX_RATIO_TO_CARDMARKET;
        if ($this->farFrom($market, $lastTcgcsv, self::MAX_RATIO_TO_LAST)
            || $this->farFrom($market, $lastTcgdex, self::MAX_RATIO_TO_LAST)
            || $this->farFrom($market, $cardmarket, $cardmarketRatio)) {
            return 'implausible';
        }

        return 'accept';
    }

    /**
     * Whether the link is to an extra group's product whose name and number
     * were checked against the card's. An admin link is vouched for by the
     * admin and keeps the cardmarket reference on file, whatever its group.
     */
    private function identityVerified(CardTcgplayerLink $link): bool
    {
        return $link->method !== 'admin' && $link->verified_at !== null
            && in_array((int) $link->group_id, TcgcsvExtraGroups::ids(), true);
    }

    /**
     * Adjusts the cardmarket reference of two kinds of link, loading the
     * cards' `raw` once for both:
     *
     * - An identity-verified link's reference becomes the print's own average
     *   from the card's `variants_detailed` entry for that product (EUR, in
     *   minor units), not the card's cardmarket price on file, which is the
     *   main product's; a print with no average has no reference.
     * - A third-party link for a `normal` or `holofoil` print that has no
     *   cardmarket price under its own variant takes the card's `default` one:
     *   tcgdex files the price of a card with a single base product there, as
     *   with every 30th Celebration holo. It only counts when every entry
     *   carrying the link's TCGplayer product is on the card's top-level
     *   cardmarket product, so the price is that print's and not another's.
     *
     * @param  array<string, int>  $cardmarket  "card_id|variant" → the cardmarket price on file
     * @param  list<array{CardTcgplayerLink, TcgcsvPriceRow}>  $pairs
     * @return array<string, int>
     */
    private function withCardmarketReferences(array $cardmarket, array $pairs): array
    {
        $verified = [];
        $defaulted = [];

        foreach ($pairs as [$link]) {
            $key = "{$link->card_id}|{$link->variant}";

            if ($this->identityVerified($link)) {
                $verified[$key] = $link;
            } elseif ($link->method === 'tcgdex-thirdparty' && in_array($link->variant, ['normal', 'holofoil'], true)
                && ! isset($cardmarket[$key]) && isset($cardmarket["{$link->card_id}|default"])) {
                $defaulted[$key] = $link;
            }
        }

        if ($verified === [] && $defaulted === []) {
            return $cardmarket;
        }

        $raws = Card::whereIn('id', array_unique(array_map(fn (CardTcgplayerLink $l) => $l->card_id, [...$verified, ...$defaulted])))->pluck('raw', 'id');

        foreach ($verified as $key => $link) {
            unset($cardmarket[$key]);

            foreach ($this->entriesOf($raws[$link->card_id] ?? null, $link->product_id) as $entry) {
                $average = $entry['pricing']['cardmarket']['avg'] ?? null;

                if (is_numeric($average) && $average > 0) {
                    $cardmarket[$key] = (int) round((float) $average * 100);

                    break;
                }
            }
        }

        foreach ($defaulted as $key => $link) {
            $raw = $raws[$link->card_id] ?? null;
            $main = is_array($raw) ? ($raw['pricing']['cardmarket']['idProduct'] ?? null) : null;
            $entries = $this->entriesOf($raw, $link->product_id);

            if ($entries !== [] && is_int($main) && array_filter($entries, fn (array $e) => ($e['thirdParty']['cardmarket'] ?? null) !== $main) === []) {
                $cardmarket[$key] = $cardmarket["{$link->card_id}|default"];
            }
        }

        return $cardmarket;
    }

    /**
     * The card's `variants_detailed` entries that carry the TCGplayer product.
     *
     * @return list<array<string, mixed>>
     */
    private function entriesOf(mixed $raw, int $productId): array
    {
        $entries = is_array($raw) && is_array($raw['variants_detailed'] ?? null) ? $raw['variants_detailed'] : [];

        return array_values(array_filter($entries, fn ($e) => is_array($e) && ($e['thirdParty']['tcgplayer'] ?? null) === $productId));
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
