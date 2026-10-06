<?php

declare(strict_types=1);

use App\Modules\Catalog\Tcgcsv\TcgcsvClient;
use App\Modules\Catalog\Tcgcsv\TcgcsvPriceParser;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

test('reads the build time tcgcsv publishes, identifying itself as tcgcsv asks', function () {
    Http::fake(['tcgcsv.com/last-updated.txt' => Http::response("2026-10-05T20:05:57+0000\n", 200)]);

    $built = app(TcgcsvClient::class)->lastUpdated();

    expect($built->toIso8601String())->toBe('2026-10-05T20:05:57+00:00');
    Http::assertSent(fn ($request) => str_starts_with($request->header('User-Agent')[0] ?? '', 'tcg-vault/')
        && str_contains($request->header('User-Agent')[0], 'tcgvault.cativo.dev'));
});

test('fetches and parses one group\'s prices', function () {
    Http::fake(['tcgcsv.com/tcgplayer/3/24688/prices' => Http::response([
        'success' => true, 'errors' => [],
        'results' => [['productId' => 704873, 'lowPrice' => 150.0, 'marketPrice' => 160.63, 'subTypeName' => 'Holofoil']],
    ], 200)]);

    $rows = app(TcgcsvClient::class)->prices(24688);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->marketMinor)->toBe(16063);
});

test('a server error surfaces instead of reading as an empty group', function () {
    Http::fake(['tcgcsv.com/tcgplayer/3/24688/prices' => Http::response('down', 503)]);

    expect(fn () => app(TcgcsvClient::class)->prices(24688))->toThrow(RequestException::class);
});

test('a build time in any other shape is rejected rather than read as now', function (string $body) {
    Http::fake(['tcgcsv.com/last-updated.txt' => Http::response($body, 200)]);

    expect(fn () => app(TcgcsvClient::class)->lastUpdated())->toThrow(UnexpectedValueException::class);
})->with(['', "not a date\nfake log line", '2026-10-05']);

test('a groups payload that is not a success is an error, not an empty list', function () {
    Http::fake(['tcgcsv.com/tcgplayer/3/groups' => Http::response(['success' => false], 200)]);

    expect(fn () => app(TcgcsvClient::class)->groups())->toThrow(UnexpectedValueException::class);
});

test('a response past the size cap is refused while reading, whatever its headers say', function () {
    Http::fake(['tcgcsv.com/tcgplayer/3/24688/prices' => Http::response(str_repeat(' ', 5_000_001), 200)]);

    expect(fn () => app(TcgcsvClient::class)->prices(24688))->toThrow(UnexpectedValueException::class);
});

test('only an https base URL is accepted', function () {
    expect(fn () => new TcgcsvClient('http://tcgcsv.com', 'ua', new TcgcsvPriceParser))
        ->toThrow(InvalidArgumentException::class);
});
