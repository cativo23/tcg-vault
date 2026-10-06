<?php

declare(strict_types=1);

use App\Modules\Catalog\Tcgcsv\TcgcsvClient;
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
