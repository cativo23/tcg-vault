<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\CardSummaryData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Exceptions\CatalogUnavailableException;
use App\Modules\Catalog\Exceptions\InvalidTcgdexIdException;
use App\Modules\Catalog\Exceptions\MalformedCatalogResponseException;
use App\Modules\Catalog\Exceptions\SetNotFoundException;
use App\Modules\Catalog\Support\CardVariants;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\DataCollection;

final class TcgdexCardCatalogProvider implements CardCatalogProvider
{
    /**
     * Single-resource lookups run inline in web requests (card page, search,
     * bulk import), so a hung tcgdex connection must not be allowed to hold a
     * php-fpm worker open until nginx gives up on it.
     */
    private const REQUEST_TIMEOUT = 8;

    /**
     * A whole-set payload is an order of magnitude larger and is only ever
     * fetched from a queued job, where a longer ceiling is safe.
     */
    private const SET_LISTING_TIMEOUT = 20;

    public const LOGO_URL_PREFIX = 'https://assets.tcgdex.net/';

    /**
     * tcgdex returns every match for a name search in one response unless
     * pagination:page/pagination:itemsPerPage are sent (both required —
     * https://tcgdex.dev/rest/filtering-sorting-pagination#pagination).
     * A common name unfiltered by set can match 200+ printings, which
     * would otherwise render as an unbounded wall of result tiles.
     */
    private const SEARCH_PAGE_SIZE = 24;

    // The "v2" segment is the cached row shape; bump it when the shape changes
    // so an old copy is never read as the new one.
    public const SET_LIST_FRESH_KEY = 'tcgdex:set-list:v2:fresh';

    public const SET_LIST_STALE_KEY = 'tcgdex:set-list:v2:stale';

    private const SET_LIST_FAILED_KEY = 'tcgdex:set-list:v2:failed';

    /** While tcgdex is failing, the stale copy is served as the fresh one for this long. */
    private const SET_LIST_BACKOFF_SECONDS = 300;

    private const SET_LIST_REFRESH_LOCK = 'tcgdex:sets:v2:refresh';

    // Longer than the request timeout, so a refresh that is still
    // fetching never loses its lock to a second one.
    private const SET_LIST_REFRESH_LOCK_SECONDS = 10;

    private const SET_LIST_REFRESH_WAIT_SECONDS = 5;

    /** With no copy to fall back on, a failure is remembered for this long. */
    private const SET_LIST_FAILURE_MEMORY_SECONDS = 60;

    private const SET_LIST_FRESH_SECONDS = 86400;

    /** How long the last good list is kept as a fallback for an outage. */
    private const SET_LIST_STALE_SECONDS = 2592000;

    public function __construct(private readonly string $baseUrl) {}

    /**
     * api.tcgdex.net's DNS record resolves to an IPv6 address, but the
     * production container's IPv6 egress route to it is dead while IPv4
     * succeeds reliably; Guzzle/cURL does not fall back to IPv4 the way
     * the system `curl` binary's Happy-Eyeballs logic does, so requests
     * fail intermittently with "Could not resolve host" otherwise.
     * Forcing IPv4 on every tcgdex request sidesteps the broken IPv6
     * route rather than depending on a resolver-level fix outside this
     * app. This must go through Guzzle's 'force_ip_resolve' request
     * option — Guzzle manages CURLOPT_IPRESOLVE internally and rejects a
     * raw curl option for it.
     */
    private function http(int $timeoutSeconds): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($timeoutSeconds)
            ->withOptions(['force_ip_resolve' => 'v4']);
    }

    public function findCard(string $tcgdexId): CardDetailData
    {
        $this->assertValidTcgdexId($tcgdexId);

        $response = $this->http(self::REQUEST_TIMEOUT)->get("cards/{$tcgdexId}");

        if ($response->status() === 404) {
            throw CardNotFoundException::forTcgdexId($tcgdexId);
        }

        $response->throw();

        $json = $response->json();

        $this->assertValidCardShape($tcgdexId, $json);

        return new CardDetailData(
            tcgdexId: $json['id'],
            setTcgdexId: $json['set']['id'],
            localId: $json['localId'], // never cast to int — zero-padding must survive
            name: $json['name'],
            rarity: $json['rarity'] ?? null,
            variants: $json['variants'] ?? [],
            officialImageUrl: isset($json['image']) ? "{$json['image']}/high.webp" : null,
            prices: new DataCollection(PriceEntryData::class, [
                ...$this->extractPrices($json['pricing'] ?? [], $this->basePrintFlags($json), $this->hasDetailedPrints($json)),
                ...$this->extractSpecialPrintPrices($json),
            ]),
            raw: $json,
        );
    }

    public function findSet(string $tcgdexId): SetSummaryData
    {
        $this->assertValidTcgdexId($tcgdexId);

        $response = $this->http(self::REQUEST_TIMEOUT)->get("sets/{$tcgdexId}");

        if ($response->status() === 404) {
            throw SetNotFoundException::forTcgdexId($tcgdexId);
        }

        $response->throw();

        $json = $response->json();

        $this->assertValidSetShape($tcgdexId, $json);

        return new SetSummaryData(
            tcgdexId: $json['id'],
            name: $json['name'],
            series: $json['serie']['name'] ?? null,
            releasedOn: isset($json['releaseDate']) ? CarbonImmutable::parse($json['releaseDate']) : null,
            // 'total' includes secret rares (this app already imports
            // them in full via ImportSetJob) — 'official' is only the
            // set's PRINTED checklist number (every card, secrets
            // included, still prints e.g. "116/084" on itself), which
            // undercounts what's actually collectible and would let a
            // collector hit "100%" while missing every secret rare.
            cardCount: $json['cardCount']['total'] ?? $json['cardCount']['official'] ?? null,
            logoUrl: $this->logoUrl($json['logo'] ?? null),
            // The collector shorthand printed on the card itself and used
            // across the hobby (e.g. "ASC" for Ascended Heroes) — not every
            // set has one (older/promo sets), so this stays null rather
            // than falling back to a made-up abbreviation.
            abbreviation: $json['abbreviation']['official'] ?? null,
        );
    }

    public function listSets(): array
    {
        $fresh = $this->cachedSetRows(self::SET_LIST_FRESH_KEY);

        if ($fresh !== null) {
            return $this->hydrateSetList($fresh);
        }

        // Concurrent misses queue behind one fetch instead of each calling
        // tcgdex; whoever waits reads the copy the first one stored.
        try {
            return Cache::lock(self::SET_LIST_REFRESH_LOCK, self::SET_LIST_REFRESH_LOCK_SECONDS)
                ->block(self::SET_LIST_REFRESH_WAIT_SECONDS, fn (): array => $this->refreshSetList());
        } catch (LockTimeoutException $e) {
            $stale = $this->cachedSetRows(self::SET_LIST_STALE_KEY);

            if ($stale !== null) {
                return $this->hydrateSetList($stale);
            }

            // Another request holds the refresh and is already fetching, so
            // this wait is not a new outage for callers to report.
            throw CatalogUnavailableException::forSetListingBackoff();
        }
    }

    /**
     * Runs under the refresh lock: re-checks the fresh copy a competing
     * refresh may have stored, then fetches and caches the list.
     *
     * @return array<int, SetSummaryData>
     */
    private function refreshSetList(): array
    {
        $fresh = $this->cachedSetRows(self::SET_LIST_FRESH_KEY);

        if ($fresh !== null) {
            return $this->hydrateSetList($fresh);
        }

        // The failure marker is only written when no stale copy existed, and
        // a stale copy is only written together with a fresh one, so there
        // is nothing to serve while the marker is set.
        if (Cache::has(self::SET_LIST_FAILED_KEY)) {
            throw CatalogUnavailableException::forSetListingBackoff();
        }

        try {
            $rows = $this->fetchSetList();
        } catch (HttpClientException|MalformedCatalogResponseException $e) {
            $stale = $this->cachedSetRows(self::SET_LIST_STALE_KEY);

            if ($stale !== null) {
                Log::warning('tcgdex set list unavailable, serving the cached copy.', ['exception' => $e::class]);

                // Serve it as the fresh copy for a short while so every
                // page load does not wait out the request timeout again.
                Cache::put(self::SET_LIST_FRESH_KEY, $stale, self::SET_LIST_BACKOFF_SECONDS);

                return $this->hydrateSetList($stale);
            }

            Cache::put(self::SET_LIST_FAILED_KEY, true, self::SET_LIST_FAILURE_MEMORY_SECONDS);

            // One type for callers whichever way the attempt failed; the cause
            // stays on the exception chain.
            throw CatalogUnavailableException::forSetListing($e);
        }

        Cache::put(self::SET_LIST_FRESH_KEY, $rows, self::SET_LIST_FRESH_SECONDS);
        Cache::put(self::SET_LIST_STALE_KEY, $rows, self::SET_LIST_STALE_SECONDS);

        return $this->hydrateSetList($rows);
    }

    /**
     * The cached rows under a key, or null when there is no copy or the
     * stored value does not have the expected shape (treated as a miss).
     *
     * @return list<array{id: string, name: string, cardCount: ?int, logoUrl: ?string}>|null
     */
    private function cachedSetRows(string $key): ?array
    {
        $rows = Cache::get($key);

        if (! is_array($rows) || $rows === [] || ! array_is_list($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (
                ! is_array($row)
                || ! is_string($row['id'] ?? null)
                || ! is_string($row['name'] ?? null)
                || ! array_key_exists('cardCount', $row) || ! (is_int($row['cardCount']) || $row['cardCount'] === null)
                || ! array_key_exists('logoUrl', $row) || ! (is_string($row['logoUrl']) || $row['logoUrl'] === null)
                || (is_string($row['logoUrl']) && ! str_starts_with($row['logoUrl'], self::LOGO_URL_PREFIX))
            ) {
                return null;
            }
        }

        /** @var list<array{id: string, name: string, cardCount: ?int, logoUrl: ?string}> $rows */
        return $rows;
    }

    /**
     * The listing reduced to what the picker uses, in a shape that
     * serializes into any cache store.
     *
     * @return list<array{id: string, name: string, cardCount: ?int, logoUrl: ?string}>
     */
    private function fetchSetList(): array
    {
        $response = $this->http(self::REQUEST_TIMEOUT)->get('sets');
        $response->throw();

        $json = $response->json();

        if (! is_array($json) || ! array_is_list($json)) {
            throw MalformedCatalogResponseException::forSetListing('response body is not a JSON array.');
        }

        $rows = [];

        foreach ($json as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null) || ! is_string($entry['name'] ?? null)) {
                continue;
            }

            $counts = is_array($entry['cardCount'] ?? null) ? $entry['cardCount'] : [];
            $total = $counts['total'] ?? $counts['official'] ?? null;

            $rows[] = [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'cardCount' => is_int($total) ? $total : null,
                'logoUrl' => $this->logoUrl($entry['logo'] ?? null),
            ];
        }

        if ($rows === []) {
            throw MalformedCatalogResponseException::forSetListing('no usable set entries.');
        }

        return $rows;
    }

    /**
     * The png logo url for tcgdex's extension-less logo path, or null when
     * it is missing or not on tcgdex's own assets host: the value ends up
     * in an image src and a stored column, so a foreign host or scheme is
     * never passed along.
     */
    private function logoUrl(mixed $logo): ?string
    {
        if (! is_string($logo) || ! str_starts_with($logo, self::LOGO_URL_PREFIX)) {
            return null;
        }

        return "{$logo}.png";
    }

    /**
     * @param  list<array{id: string, name: string, cardCount: ?int, logoUrl: ?string}>  $rows
     * @return array<int, SetSummaryData>
     */
    private function hydrateSetList(array $rows): array
    {
        $sets = [];

        foreach ($rows as $row) {
            $sets[] = new SetSummaryData(
                tcgdexId: $row['id'],
                name: $row['name'],
                series: null,
                releasedOn: null,
                cardCount: $row['cardCount'],
                logoUrl: $row['logoUrl'],
            );
        }

        return $sets;
    }

    public function listSetCardIds(string $setTcgdexId): array
    {
        $this->assertValidTcgdexId($setTcgdexId);

        $response = $this->http(self::SET_LISTING_TIMEOUT)->get("sets/{$setTcgdexId}");

        if ($response->status() === 404) {
            throw SetNotFoundException::forTcgdexId($setTcgdexId);
        }

        $response->throw();

        $cards = $response->json('cards', []);

        return array_map(static fn (array $card): string => $card['id'], $cards);
    }

    public function searchCardsByName(string $query, ?string $setTcgdexId = null, int $page = 1): array
    {
        $params = ['name' => $query];

        // 'set.id' narrows results server-side (dot notation for
        // nested-field filters, per tcgdex's own filtering docs), so a
        // name search doesn't need to fetch every cross-set printing and
        // filter in PHP.
        if ($setTcgdexId !== null) {
            $params['set.id'] = $setTcgdexId;
        }

        $params['pagination:page'] = $page;
        $params['pagination:itemsPerPage'] = self::SEARCH_PAGE_SIZE;

        $response = $this->http(self::REQUEST_TIMEOUT)->get('cards', $params);

        $response->throw();

        $json = $response->json();

        $this->assertValidSearchShape($query, $json);

        return array_map(
            fn (array $card): CardSummaryData => new CardSummaryData(
                tcgdexId: $card['id'],
                setTcgdexId: explode('-', $card['id'])[0],
                localId: $card['localId'],
                name: $card['name'],
                imageUrl: isset($card['image']) ? "{$card['image']}/high.webp" : null,
            ),
            $json,
        );
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @param  array<string, mixed>  $variants  the card's base-print flags (see basePrintFlags())
     * @param  bool  $flagsCorrected  whether $variants came from the corrected print list
     * @return array<int, PriceEntryData>
     */
    private function extractPrices(array $pricing, array $variants = [], bool $flagsCorrected = false): array
    {
        $entries = [];

        if (isset($pricing['cardmarket'])) {
            $cm = $pricing['cardmarket'];
            $updated = isset($cm['updated']) ? CarbonImmutable::parse($cm['updated']) : null;

            $entries[] = new PriceEntryData(
                source: 'cardmarket',
                // cardmarket's 'avg'/'low'/'trend' price the card's
                // PRIMARY product listing. That's 'normal' whenever the
                // card genuinely has a normal print; 'default' otherwise
                // (e.g. a straight-holo-only card, where this same figure
                // IS the holo price and 'avg-holo' is simply absent —
                // CardPriceResolver's priority chain already treats
                // cardmarket 'default' as a valid card-level price).
                variant: ($variants['normal'] ?? false) === true ? 'normal' : 'default',
                currency: $cm['unit'] ?? 'EUR',
                marketMinor: $this->toMinorUnits($cm['avg'] ?? null),
                lowMinor: $this->toMinorUnits($cm['low'] ?? null),
                trendMinor: $this->toMinorUnits($cm['trend'] ?? null),
                sourceUpdatedAt: $updated,
                raw: $cm,
            );

            if (isset($cm['avg-holo'])) {
                // cardmarket's 'avg-holo'/'low-holo'/'trend-holo' price
                // whatever the card's OTHER foil-tier print is — tcgdex
                // does not disambiguate straight-holo from reverse-holo
                // in the field name itself, so a normal+reverse card with
                // no straight holo print would otherwise get its
                // reverse-holo price mislabeled 'holofoil'. Cross-reference
                // the card's own `variants` flags instead of guessing from
                // the field name.
                $foilVariants = match (true) {
                    // A meaningful share of the catalog has BOTH holo and
                    // reverse true — a common case, not an edge case.
                    // cardmarket's single aggregated figure can't tell
                    // those two prints apart, so attributing it to only
                    // one label would silently leave the other print
                    // unpriced despite real market data existing. Surface
                    // the same figure under BOTH labels instead of
                    // dropping one — a shared estimate beats total silence.
                    ($variants['holo'] ?? false) === true && ($variants['reverse'] ?? false) === true => ['holofoil', 'reverse-holofoil'],
                    ($variants['holo'] ?? false) === true => ['holofoil'],
                    ($variants['reverse'] ?? false) === true => ['reverse-holofoil'],
                    // The corrected print list has no plain foil print (only
                    // pattern reverses, each priced on its own entry): the
                    // figure belongs to none of the card's base prints.
                    $flagsCorrected => [],
                    // No usable flags (older/incomplete sync): keep the
                    // prior fallback rather than guess wrong with silence.
                    default => ['holofoil'],
                };

                foreach ($foilVariants as $foilVariant) {
                    $entries[] = new PriceEntryData(
                        source: 'cardmarket',
                        variant: $foilVariant,
                        currency: $cm['unit'] ?? 'EUR',
                        marketMinor: $this->toMinorUnits($cm['avg-holo'] ?? null),
                        lowMinor: $this->toMinorUnits($cm['low-holo'] ?? null),
                        trendMinor: $this->toMinorUnits($cm['trend-holo'] ?? null),
                        sourceUpdatedAt: $updated,
                        raw: $cm,
                    );
                }
            }
        }

        if (isset($pricing['tcgplayer'])) {
            $tp = $pricing['tcgplayer'];
            $updated = isset($tp['updated']) ? CarbonImmutable::parse($tp['updated']) : null;
            $currency = $tp['unit'] ?? 'USD';

            foreach ($tp as $variantKey => $variantData) {
                if (! is_array($variantData) || ! isset($variantData['marketPrice'])) {
                    continue; // skip 'unit' / 'updated' scalar keys
                }

                $entries[] = new PriceEntryData(
                    source: 'tcgplayer',
                    variant: $variantKey,
                    currency: $currency,
                    marketMinor: $this->toMinorUnits($variantData['marketPrice']),
                    lowMinor: $this->toMinorUnits($variantData['lowPrice'] ?? null),
                    trendMinor: null, // tcgplayer's per-variant payload has no trend figure
                    sourceUpdatedAt: $updated,
                    raw: $variantData,
                );
            }
        }

        return $entries;
    }

    /**
     * Which base prints the card really has, as tcgdex-style flags. tcgdex's
     * own flags also count a special print — Boss's Orders is flagged holo
     * only for its Prize Pack cosmos — so base prices are labelled from the
     * corrected list instead (CardVariants::available()), and the raw flags
     * only when there is nothing to correct them with.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, bool>
     */
    private function basePrintFlags(array $json): array
    {
        $flags = is_array($json['variants'] ?? null) ? $json['variants'] : [];

        if (! $this->hasDetailedPrints($json)) {
            return $flags;
        }

        $offered = CardVariants::available($flags, $json);

        return [
            'normal' => in_array('normal', $offered, true),
            'holo' => in_array('holofoil', $offered, true),
            'reverse' => in_array('reverse-holofoil', $offered, true),
        ];
    }

    /** @param  array<string, mixed>  $json */
    private function hasDetailedPrints(array $json): bool
    {
        return is_array($json['variants_detailed'] ?? null) && $json['variants_detailed'] !== [];
    }

    /**
     * Prices for the prints only `variants_detailed` can tell apart — a
     * Poké Ball/Master Ball pattern reverse, a cosmos holo, a stamped
     * promo. Each is its own product with its own `pricing`, stored
     * under its CardVariants key so it never borrows the base print's
     * price (a Master Ball reverse sells for ten times the plain one).
     * Base prints — including one tcgdex files with its foil or stamp,
     * see CardVariants::specialPrints() — are skipped: the top-level
     * `pricing` already covers them.
     *
     * @param  array<string, mixed>  $json  the card's full tcgdex payload
     * @return array<int, PriceEntryData>
     */
    private function extractSpecialPrintPrices(array $json): array
    {
        $entries = [];

        foreach (CardVariants::specialPrints($json) as ['key' => $key, 'entry' => $entry]) {
            if (! is_array($entry['pricing'] ?? null)) {
                continue;
            }

            $pricing = $entry['pricing'];

            if (is_array($pricing['cardmarket'] ?? null)) {
                $cm = $pricing['cardmarket'];
                // A pattern/special-foil product is foil-only, so its
                // -holo figures are its price; the plain ones are a
                // fallback for entries that only carry those.
                $entries[] = new PriceEntryData(
                    source: 'cardmarket',
                    variant: $key,
                    currency: $cm['unit'] ?? 'EUR',
                    marketMinor: $this->toMinorUnits($cm['avg-holo'] ?? $cm['avg'] ?? null),
                    lowMinor: $this->toMinorUnits($cm['low-holo'] ?? $cm['low'] ?? null),
                    trendMinor: $this->toMinorUnits($cm['trend-holo'] ?? $cm['trend'] ?? null),
                    sourceUpdatedAt: isset($cm['updated']) ? CarbonImmutable::parse($cm['updated']) : null,
                    raw: $cm,
                );
            }

            if (is_array($pricing['tcgplayer'] ?? null)) {
                $tp = $pricing['tcgplayer'];
                // tcgplayer files the product under whichever printing
                // label it uses ('holofoil' for a pattern reverse); the
                // entry is one product, so its first priced label is it.
                $priced = collect($tp)->first(fn (mixed $v) => is_array($v) && isset($v['marketPrice']));

                if ($priced !== null) {
                    $entries[] = new PriceEntryData(
                        source: 'tcgplayer',
                        variant: $key,
                        currency: $tp['unit'] ?? 'USD',
                        marketMinor: $this->toMinorUnits($priced['marketPrice']),
                        lowMinor: $this->toMinorUnits($priced['lowPrice'] ?? null),
                        trendMinor: null,
                        sourceUpdatedAt: isset($tp['updated']) ? CarbonImmutable::parse($tp['updated']) : null,
                        raw: $priced,
                    );
                }
            }
        }

        return $entries;
    }

    private function toMinorUnits(int|float|string|null $amount): ?int
    {
        if ($amount === null) {
            return null;
        }

        if (! is_numeric($amount)) {
            return null;
        }

        return (int) round((float) $amount * 100);
    }

    private function assertValidTcgdexId(string $id): void
    {
        // tcgdex genuinely uses dotted set IDs for "half sets"/special
        // releases (e.g. "sv08.5" Prismatic Evolutions, "me02.5" Ascended
        // Heroes) — a card ID from one of those is e.g. "sv08.5-048", so
        // the pattern must allow "." as well as "-". Still SSRF-safe: no
        // "/", ":", or consecutive/leading/trailing "." or "-" can ever
        // match, so path traversal and protocol/host injection stay
        // impossible.
        if (! preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/i', $id)) {
            throw InvalidTcgdexIdException::forId($id);
        }
    }

    private function assertValidCardShape(string $tcgdexId, mixed $json): void
    {
        if (! is_array($json)) {
            throw MalformedCatalogResponseException::forCard($tcgdexId, 'response body is not a JSON object.');
        }

        if (! isset($json['id']) || ! is_string($json['id'])) {
            throw MalformedCatalogResponseException::forCard($tcgdexId, 'missing or non-string "id".');
        }

        if (! isset($json['name']) || ! is_string($json['name'])) {
            throw MalformedCatalogResponseException::forCard($tcgdexId, 'missing or non-string "name".');
        }

        if (! isset($json['localId']) || ! is_string($json['localId'])) {
            throw MalformedCatalogResponseException::forCard($tcgdexId, 'missing or non-string "localId".');
        }

        if (! isset($json['set']['id']) || ! is_string($json['set']['id'])) {
            throw MalformedCatalogResponseException::forCard($tcgdexId, 'missing or non-string "set.id".');
        }

        $this->assertValidCurrencies($tcgdexId, $json['pricing'] ?? []);

        foreach ($json['variants_detailed'] ?? [] as $entry) {
            if (is_array($entry) && is_array($entry['pricing'] ?? null)) {
                $this->assertValidCurrencies($tcgdexId, $entry['pricing']);
            }
        }
    }

    private function assertValidSetShape(string $tcgdexId, mixed $json): void
    {
        if (! is_array($json)) {
            throw MalformedCatalogResponseException::forSet($tcgdexId, 'response body is not a JSON object.');
        }

        if (! isset($json['id']) || ! is_string($json['id'])) {
            throw MalformedCatalogResponseException::forSet($tcgdexId, 'missing or non-string "id".');
        }

        if (! isset($json['name']) || ! is_string($json['name'])) {
            throw MalformedCatalogResponseException::forSet($tcgdexId, 'missing or non-string "name".');
        }
    }

    private function assertValidSearchShape(string $query, mixed $json): void
    {
        if (! is_array($json) || ! array_is_list($json)) {
            throw MalformedCatalogResponseException::forSearch($query, 'response body is not a JSON array.');
        }

        foreach ($json as $card) {
            if (! is_array($card)) {
                throw MalformedCatalogResponseException::forSearch($query, 'a result entry is not a JSON object.');
            }

            if (! isset($card['id']) || ! is_string($card['id'])) {
                throw MalformedCatalogResponseException::forSearch($query, 'a result is missing a non-string "id".');
            }

            if (! isset($card['localId']) || ! is_string($card['localId'])) {
                throw MalformedCatalogResponseException::forSearch($query, 'a result is missing a non-string "localId".');
            }

            if (! isset($card['name']) || ! is_string($card['name'])) {
                throw MalformedCatalogResponseException::forSearch($query, 'a result is missing a non-string "name".');
            }

            if (isset($card['image']) && ! is_string($card['image'])) {
                throw MalformedCatalogResponseException::forSearch($query, 'a result has a non-string "image".');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $pricing
     */
    private function assertValidCurrencies(string $tcgdexId, array $pricing): void
    {
        $currencies = [];

        if (isset($pricing['cardmarket']['unit'])) {
            $currencies[] = $pricing['cardmarket']['unit'];
        }

        if (isset($pricing['tcgplayer']['unit'])) {
            $currencies[] = $pricing['tcgplayer']['unit'];
        }

        foreach ($currencies as $currency) {
            if (! is_string($currency) || strlen($currency) !== 3) {
                throw MalformedCatalogResponseException::forCard($tcgdexId, "invalid currency code [{$currency}].");
            }
        }
    }
}
