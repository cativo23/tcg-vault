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
     * Found live in production 2026-09-15: api.tcgdex.net's DNS record
     * resolves to an IPv6 address, and the production container's IPv6
     * egress route to it is dead (100% failure in repeated `curl -6`
     * tests), while IPv4 succeeds 100% of the time. The system `curl`
     * binary hides this via its own Happy-Eyeballs fallback, but
     * Guzzle/cURL inside PHP does not fall back the same way here — jobs
     * failed intermittently with "Could not resolve host", which is
     * curl error 6, not a connection-refused error. Forcing IPv4 on
     * every tcgdex request sidesteps the broken IPv6 route entirely
     * rather than depending on a resolver-level fix outside this app.
     *
     * Regression found live 2026-09-15 (same day): the first fix passed
     * a raw CURLOPT_IPRESOLVE via the 'curl' options array, which Guzzle
     * itself rejects — "conflicts with Guzzle-managed request handling"
     * — because Guzzle already manages that option internally and
     * refuses to let a caller set it directly. This broke EVERY tcgdex
     * call in production (search, add-card, imports) with an uncaught
     * GuzzleHttp\Exception\InvalidArgumentException. Guzzle's own
     * request-options API has a dedicated option for exactly this case:
     * 'force_ip_resolve', which is what must be used instead.
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
            prices: new DataCollection(PriceEntryData::class, $this->extractPrices($json['pricing'] ?? [])),
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
            cardCount: $json['cardCount']['official'] ?? null,
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

    public function searchCardsByName(string $query): array
    {
        $response = $this->http(self::REQUEST_TIMEOUT)->get('cards', ['name' => $query]);

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
     * @return array<int, PriceEntryData>
     */
    private function extractPrices(array $pricing): array
    {
        $entries = [];

        if (isset($pricing['cardmarket'])) {
            $cm = $pricing['cardmarket'];
            $updated = isset($cm['updated']) ? CarbonImmutable::parse($cm['updated']) : null;

            $entries[] = new PriceEntryData(
                source: 'cardmarket',
                variant: 'default',
                currency: $cm['unit'] ?? 'EUR',
                marketMinor: $this->toMinorUnits($cm['avg'] ?? null),
                lowMinor: $this->toMinorUnits($cm['low'] ?? null),
                trendMinor: $this->toMinorUnits($cm['trend'] ?? null),
                sourceUpdatedAt: $updated,
                raw: $cm,
            );

            if (isset($cm['avg-holo'])) {
                $entries[] = new PriceEntryData(
                    source: 'cardmarket',
                    variant: 'holofoil',
                    currency: $cm['unit'] ?? 'EUR',
                    marketMinor: $this->toMinorUnits($cm['avg-holo'] ?? null),
                    lowMinor: $this->toMinorUnits($cm['low-holo'] ?? null),
                    trendMinor: $this->toMinorUnits($cm['trend-holo'] ?? null),
                    sourceUpdatedAt: $updated,
                    raw: $cm,
                );
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
        // Heroes) — a card ID from one of those is e.g. "sv08.5-048".
        // Found live 2026-09-15: the original hyphen-only pattern rejected
        // these as InvalidTcgdexIdException, even though they're real
        // tcgdex IDs, not attacker input. Still SSRF-safe: no "/", ":",
        // or consecutive/leading/trailing "." or "-" can ever match, so
        // path traversal and protocol/host injection stay impossible.
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
