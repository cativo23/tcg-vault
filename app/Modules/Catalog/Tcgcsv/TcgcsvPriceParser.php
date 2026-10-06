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
    /** No single card's market price is anywhere near this; above it the feed is wrong. */
    private const MAX_PRICE = 1_000_000;

    /** card_tcgplayer_links.product_id is a Postgres integer. */
    private const MAX_PRODUCT_ID = 2_147_483_647;

    /** card_tcgplayer_links.sub_type is 32 characters. */
    private const MAX_SUBTYPE_LENGTH = 32;

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

    /** The TCGplayer subtype that prices a variant key's base print. */
    public static function subTypeFor(string $variant): ?string
    {
        $base = explode(':', explode('+', $variant, 2)[0], 2)[0];

        return array_flip(self::BASE_VARIANTS)[$base] ?? null;
    }

    private function row(int $groupId, mixed $row): TcgcsvPriceRow
    {
        if (! is_array($row) || ! is_int($row['productId'] ?? null) || $row['productId'] <= 0 || $row['productId'] > self::MAX_PRODUCT_ID
            || ! is_string($row['subTypeName'] ?? null) || strlen($row['subTypeName']) > self::MAX_SUBTYPE_LENGTH
            // Letters, digits and simple punctuation only: the subtype is
            // stored and logged, so no line breaks or control bytes (D: `$`
            // must not match before a trailing newline).
            || preg_match('/^[\p{L}\p{N} ._()\/-]+$/Du', $row['subTypeName']) !== 1) {
            throw MalformedCatalogResponseException::forTcgcsvGroup($groupId, 'a row lacks a positive integer "productId" or a short plain-text "subTypeName".');
        }

        return new TcgcsvPriceRow(
            groupId: $groupId,
            productId: $row['productId'],
            subType: $row['subTypeName'],
            marketMinor: $this->minor($groupId, $row['marketPrice'] ?? null),
            lowMinor: $this->minor($groupId, $row['lowPrice'] ?? null),
        );
    }

    private function minor(int $groupId, mixed $amount): ?int
    {
        if ($amount === null) {
            return null;
        }

        if ((! is_int($amount) && ! is_float($amount)) || ! is_finite((float) $amount) || $amount < 0 || $amount > self::MAX_PRICE) {
            throw MalformedCatalogResponseException::forTcgcsvGroup($groupId, 'a price is not a sane non-negative number or null.');
        }

        return (int) round($amount * 100);
    }
}
