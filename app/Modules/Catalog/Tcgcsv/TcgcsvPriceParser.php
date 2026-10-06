<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tcgcsv;

use App\Modules\Catalog\Exceptions\MalformedCatalogResponseException;

/**
 * Turns a tcgcsv `/tcgplayer/3/{groupId}/prices` payload into price rows.
 * The whole group is rejected on any malformed row, so a schema change
 * writes nothing rather than half a set. Uses `marketPrice` and
 * `lowPrice` only — `highPrice` is easily distorted by parked listings.
 */
final class TcgcsvPriceParser
{
    /** @var array<string, string> TCGplayer subtype → this app's base variant key */
    private const BASE_VARIANTS = [
        'Normal' => 'normal',
        'Holofoil' => 'holofoil',
        'Reverse Holofoil' => 'reverse-holofoil',
    ];

    /** @return list<TcgcsvPriceRow> */
    public function parse(int $groupId, mixed $payload): array
    {
        if (! is_array($payload) || ($payload['success'] ?? null) !== true) {
            throw MalformedCatalogResponseException::forTcgcsvGroup($groupId, 'response is not a successful JSON object.');
        }

        $results = $payload['results'] ?? null;
        if (! is_array($results) || ! array_is_list($results)) {
            throw MalformedCatalogResponseException::forTcgcsvGroup($groupId, '"results" is missing or not a list.');
        }

        return array_map(fn (mixed $row) => $this->row($groupId, $row), $results);
    }

    /** The base variant key a TCGplayer subtype prices, or null for one this app has no key for. */
    public static function baseVariantFor(string $subType): ?string
    {
        return self::BASE_VARIANTS[$subType] ?? null;
    }

    /** The TCGplayer subtype that prices a variant key's base print. */
    public static function subTypeFor(string $variant): ?string
    {
        $base = explode(':', explode('+', $variant, 2)[0], 2)[0];

        return array_flip(self::BASE_VARIANTS)[$base] ?? null;
    }

    private function row(int $groupId, mixed $row): TcgcsvPriceRow
    {
        if (! is_array($row) || ! is_int($row['productId'] ?? null) || ! is_string($row['subTypeName'] ?? null)) {
            throw MalformedCatalogResponseException::forTcgcsvGroup($groupId, 'a row lacks an integer "productId" or a string "subTypeName".');
        }

        return new TcgcsvPriceRow(
            groupId: $groupId,
            productId: $row['productId'],
            subType: $row['subTypeName'],
            marketMinor: $this->minor($groupId, $row['marketPrice'] ?? null),
            lowMinor: $this->minor($groupId, $row['lowPrice'] ?? null),
            raw: $row,
        );
    }

    private function minor(int $groupId, mixed $amount): ?int
    {
        if ($amount === null) {
            return null;
        }

        if (! is_int($amount) && ! is_float($amount)) {
            throw MalformedCatalogResponseException::forTcgcsvGroup($groupId, 'a price is neither a number nor null.');
        }

        return (int) round($amount * 100);
    }
}
