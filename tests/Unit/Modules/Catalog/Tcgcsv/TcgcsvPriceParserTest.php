<?php

declare(strict_types=1);

use App\Modules\Catalog\Exceptions\MalformedCatalogResponseException;
use App\Modules\Catalog\Tcgcsv\TcgcsvPriceParser;

function tcgcsvPrices(array $results): array
{
    return ['success' => true, 'errors' => [], 'results' => $results];
}

test('parses a group\'s prices into rows keyed by product and subtype, in minor units', function () {
    // Cut from https://tcgcsv.com/tcgplayer/3/24688/prices (Pitch Black).
    $rows = (new TcgcsvPriceParser)->parse(24688, tcgcsvPrices([
        ['productId' => 704873, 'lowPrice' => 150.0, 'midPrice' => 170.0, 'highPrice' => 999.0, 'marketPrice' => 160.63, 'directLowPrice' => null, 'subTypeName' => 'Holofoil'],
        ['productId' => 704805, 'lowPrice' => 0.5, 'midPrice' => 0.8, 'highPrice' => 5.0, 'marketPrice' => 0.7, 'directLowPrice' => null, 'subTypeName' => 'Holofoil'],
    ]));

    expect($rows)->toHaveCount(2);
    expect($rows[0]->productId)->toBe(704873);
    expect($rows[0]->subType)->toBe('Holofoil');
    expect($rows[0]->marketMinor)->toBe(16063);
    expect($rows[0]->lowMinor)->toBe(15000);
    expect($rows[0]->groupId)->toBe(24688);
});

test('a row with no market price keeps a null price instead of zero', function () {
    $rows = (new TcgcsvPriceParser)->parse(1, tcgcsvPrices([
        ['productId' => 1, 'lowPrice' => null, 'marketPrice' => null, 'subTypeName' => 'Normal'],
    ]));

    expect($rows[0]->marketMinor)->toBeNull();
    expect($rows[0]->lowMinor)->toBeNull();
});

test('maps TCGplayer subtypes to base variant keys and leaves others unmapped', function () {
    expect(TcgcsvPriceParser::baseVariantFor('Normal'))->toBe('normal');
    expect(TcgcsvPriceParser::baseVariantFor('Holofoil'))->toBe('holofoil');
    expect(TcgcsvPriceParser::baseVariantFor('Reverse Holofoil'))->toBe('reverse-holofoil');
    expect(TcgcsvPriceParser::baseVariantFor('1st Edition Holofoil'))->toBeNull();

    expect(TcgcsvPriceParser::subTypeFor('reverse-holofoil:pokeball'))->toBe('Reverse Holofoil');
    expect(TcgcsvPriceParser::subTypeFor('holofoil:cosmos+player-rewards-program'))->toBe('Holofoil');
    expect(TcgcsvPriceParser::subTypeFor('normal'))->toBe('Normal');
});

test('a malformed payload is rejected whole', function (mixed $payload) {
    expect(fn () => (new TcgcsvPriceParser)->parse(24688, $payload))->toThrow(MalformedCatalogResponseException::class);
})->with([
    'not an object' => ['nope'],
    'success false' => [['success' => false, 'results' => []]],
    'no results' => [['success' => true]],
    'results not a list' => [['success' => true, 'results' => ['a' => 1]]],
    'string productId' => [tcgcsvPrices([['productId' => '704873', 'marketPrice' => 1.0, 'subTypeName' => 'Holofoil']])],
    'missing subtype' => [tcgcsvPrices([['productId' => 1, 'marketPrice' => 1.0]])],
    'non-numeric price' => [tcgcsvPrices([['productId' => 1, 'marketPrice' => 'cheap', 'subTypeName' => 'Normal']])],
    'negative price' => [tcgcsvPrices([['productId' => 1, 'marketPrice' => -5.5, 'subTypeName' => 'Normal']])],
    'absurd price' => [tcgcsvPrices([['productId' => 1, 'marketPrice' => 1e300, 'subTypeName' => 'Normal']])],
    'infinite price' => [tcgcsvPrices([['productId' => 1, 'marketPrice' => INF, 'subTypeName' => 'Normal']])],
    'product id beyond the column' => [tcgcsvPrices([['productId' => 2_147_483_648, 'marketPrice' => 1.0, 'subTypeName' => 'Normal']])],
    'over-long subtype' => [tcgcsvPrices([['productId' => 1, 'marketPrice' => 1.0, 'subTypeName' => str_repeat('x', 40)]])],
]);
