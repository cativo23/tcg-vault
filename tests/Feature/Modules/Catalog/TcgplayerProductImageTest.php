<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardTcgplayerLink;
use App\Modules\Catalog\Models\Set;
use App\Modules\Catalog\Tcgcsv\TcgplayerProductImage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function imageCard(array $links): Card
{
    $set = Set::firstOrCreate(['tcgdex_id' => '30th-c'], ['name' => '30th Classic Collection']);
    $card = Card::create(['tcgdex_id' => '30th-c-007', 'set_id' => $set->id, 'local_id' => '007', 'name' => 'Sneasel']);

    foreach ($links as [$variant, $productId, $method]) {
        CardTcgplayerLink::create(['card_id' => $card->id, 'variant' => $variant, 'product_id' => $productId, 'sub_type' => 'Holofoil', 'group_id' => 24837, 'method' => $method]);
    }

    return $card;
}

function imageUrl(int $productId): string
{
    return "https://tcgplayer-cdn.tcgplayer.com/product/{$productId}_in_1000x1000.jpg";
}

function fakeImage(int $productId, int $status = 200, string $type = 'image/jpeg'): void
{
    Http::fake([imageUrl($productId) => Http::response('', $status, ['Content-Type' => $type])]);
}

test('a card linked to one product gets that product\'s image once the CDN confirms it', function () {
    $card = imageCard([['holofoil', 716161, 'admin']]);
    fakeImage(716161);

    expect(app(TcgplayerProductImage::class)->resolve($card))->toBe(imageUrl(716161));
});

test('an image the CDN does not have is not used', function (int $status, string $type) {
    $card = imageCard([['holofoil', 716161, 'admin']]);
    fakeImage(716161, $status, $type);

    expect(app(TcgplayerProductImage::class)->resolve($card))->toBeNull();
})->with([
    'missing product' => [403, 'application/xml'],
    'not found' => [404, 'image/jpeg'],
    'server error' => [503, 'image/jpeg'],
    'not an image' => [200, 'text/html'],
]);

test('a redirect is not followed', function () {
    $card = imageCard([['holofoil', 716161, 'admin']]);
    Http::fake([imageUrl(716161) => Http::response('', 302, ['Location' => 'https://elsewhere.test/x.jpg'])]);

    expect(app(TcgplayerProductImage::class)->resolve($card))->toBeNull();
    Http::assertSentCount(1);
});

test('a lost connection means no image', function () {
    $card = imageCard([['holofoil', 716161, 'admin']]);
    Http::fake([imageUrl(716161) => fn () => throw new ConnectionException('down')]);

    expect(app(TcgplayerProductImage::class)->resolve($card))->toBeNull();
});

test('a card with no link makes no request', function () {
    $card = imageCard([]);
    Http::fake();

    expect(app(TcgplayerProductImage::class)->resolve($card))->toBeNull();
    Http::assertNothingSent();
});

test('the print\'s own product wins over a stamped one linked to the same card', function () {
    $card = imageCard([['holofoil', 685494, 'tcgdex-thirdparty'], ['holofoil+set-logo+staff', 685498, 'tcgdex-thirdparty']]);
    fakeImage(685494);

    expect(app(TcgplayerProductImage::class)->resolve($card))->toBe(imageUrl(685494));
    Http::assertSent(fn (Request $r) => $r->url() === imageUrl(685494));
});

test('a card whose only links are special prints gets no image', function () {
    $card = imageCard([['holofoil+set-logo+staff', 685498, 'tcgdex-thirdparty']]);
    Http::fake();

    expect(app(TcgplayerProductImage::class)->resolve($card))->toBeNull();
    Http::assertNothingSent();
});

test('the most trusted link decides when the prints\' products differ', function () {
    $card = imageCard([['normal', 111, 'tcgdex-thirdparty'], ['holofoil', 222, 'admin']]);
    fakeImage(222);

    expect(app(TcgplayerProductImage::class)->resolve($card))->toBe(imageUrl(222));
});

test('products that still differ at the same trust leave the card without an image', function () {
    $card = imageCard([['normal', 111, 'tcgdex-price'], ['holofoil', 222, 'tcgdex-price']]);
    Http::fake();

    expect(app(TcgplayerProductImage::class)->resolve($card))->toBeNull();
    Http::assertNothingSent();
});

test('a link to product 0 is not an image', function () {
    $card = imageCard([['holofoil', 0, 'admin']]);
    Http::fake();

    expect(app(TcgplayerProductImage::class)->resolve($card))->toBeNull();
    Http::assertNothingSent();
});

test('a link of an unknown method only counts when no known method links the card', function () {
    $card = imageCard([['normal', 111, 'something-else'], ['holofoil', 222, 'tcgdex-thirdparty']]);
    fakeImage(222);

    expect(app(TcgplayerProductImage::class)->resolve($card))->toBe(imageUrl(222));
});

test('two variants of one product make one request', function () {
    $card = imageCard([['normal', 656263, 'tcgdex-thirdparty'], ['reverse-holofoil', 656263, 'tcgdex-thirdparty']]);
    fakeImage(656263);

    expect(app(TcgplayerProductImage::class)->resolve($card))->toBe(imageUrl(656263));
    Http::assertSentCount(1);
});
