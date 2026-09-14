<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
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
    public function __construct(private readonly string $baseUrl) {}

    public function findCard(string $tcgdexId): CardDetailData
    {
        $this->assertValidTcgdexId($tcgdexId);

        $response = Http::baseUrl($this->baseUrl)->get("cards/{$tcgdexId}");

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

        $response = Http::baseUrl($this->baseUrl)->get("sets/{$tcgdexId}");

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

        $response = Http::baseUrl($this->baseUrl)->get("sets/{$setTcgdexId}");

        if ($response->status() === 404) {
            throw SetNotFoundException::forTcgdexId($setTcgdexId);
        }

        $response->throw();

        $cards = $response->json('cards', []);

        return array_map(static fn (array $card): string => $card['id'], $cards);
    }

    /**
     * @param array<string, mixed> $pricing
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
        if (! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/i', $id)) {
            throw InvalidTcgdexIdException::forId($id);
        }
    }

    /**
     * @param mixed $json
     */
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

    /**
     * @param mixed $json
     */
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

    /**
     * @param array<string, mixed> $pricing
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
