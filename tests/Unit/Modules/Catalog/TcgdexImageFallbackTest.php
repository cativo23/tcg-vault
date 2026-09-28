<?php

declare(strict_types=1);

use App\Modules\Catalog\Services\TcgdexImageFallback;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function imageFallback(): TcgdexImageFallback
{
    return new TcgdexImageFallback('https://api.tcgdex.net/v2/en');
}

test('builds the image address from the set’s series when the file exists', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/sets/mep' => Http::response(['id' => 'mep', 'serie' => ['id' => 'me', 'name' => 'Mega Evolution']]),
        'assets.tcgdex.net/en/me/mep/031/high.webp' => Http::response('', 200),
    ]);

    expect(imageFallback()->resolve('mep', '031'))->toBe('https://assets.tcgdex.net/en/me/mep/031/high.webp');
});

test('returns nothing when the built address does not exist, so no broken link is stored', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/sets/mep' => Http::response(['id' => 'mep', 'serie' => ['id' => 'me']]),
        'assets.tcgdex.net/*' => Http::response('', 404),
    ]);

    expect(imageFallback()->resolve('mep', '031'))->toBeNull();
});

test('returns nothing when the set has no series', function () {
    Http::fake(['api.tcgdex.net/v2/en/sets/odd' => Http::response(['id' => 'odd'])]);

    expect(imageFallback()->resolve('odd', '001'))->toBeNull();
    Http::assertSentCount(1);
});

test('returns nothing instead of failing when tcgdex is unreachable', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('down')]);

    expect(imageFallback()->resolve('mep', '031'))->toBeNull();
});

test('looks the series up once per set, not once per card', function () {
    Http::fake([
        'api.tcgdex.net/v2/en/sets/mep' => Http::response(['id' => 'mep', 'serie' => ['id' => 'me']]),
        'assets.tcgdex.net/*' => Http::response('', 200),
    ]);

    imageFallback()->resolve('mep', '031');
    imageFallback()->resolve('mep', '032');

    Http::assertSentCount(3); // one set lookup, two image checks
    Http::assertSent(fn (Request $r) => $r->method() === 'HEAD' && str_ends_with($r->url(), '/mep/032/high.webp'));
});

test('ignores ids that are not plain tcgdex segments', function () {
    Http::fake();

    expect(imageFallback()->resolve('../x', '031'))->toBeNull()
        ->and(imageFallback()->resolve('mep', '0/../1'))->toBeNull();
    Http::assertNothingSent();
});
