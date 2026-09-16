<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\CardSummaryData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Catalog\Data\SetSummaryData;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Exceptions\InvalidTcgdexIdException;
use App\Modules\Catalog\Exceptions\MalformedCatalogResponseException;
use App\Modules\Catalog\Exceptions\SetNotFoundException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
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
    private function http(int $timeoutSeconds): \Illuminate\Http\Client\PendingRequest
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
            prices: new DataCollection(PriceEntryData::class, $this->extractPrices($json['pricing'] ?? [], $json['variants'] ?? [])),
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
            logoUrl: isset($json['logo']) ? "{$json['logo']}.png" : null,
        );
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

    public function searchCardsByName(string $query, ?string $setTcgdexId = null): array
    {
        $params = ['name' => $query];

        // 'set.id' narrows results server-side (dot notation for
        // nested-field filters, per tcgdex's own filtering docs), so a
        // name search doesn't need to fetch every cross-set printing and
        // filter in PHP.
        if ($setTcgdexId !== null) {
            $params['set.id'] = $setTcgdexId;
        }

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
     * @param  array<string, mixed>  $variants  the card's own tcgdex `variants` flags
     * @return array<int, PriceEntryData>
     */
    private function extractPrices(array $pricing, array $variants = []): array
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
                    marketMinor: $this->toMinorUnits($variantData['marketPrice'] ?? null),
                    lowMinor: $this->toMinorUnits($variantData['lowPrice'] ?? null),
                    trendMinor: null, // tcgplayer's per-variant payload has no trend figure
                    sourceUpdatedAt: $updated,
                    raw: $variantData,
                );
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
