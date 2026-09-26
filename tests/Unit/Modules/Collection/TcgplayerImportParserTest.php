<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Data\CardDetailData;
use App\Modules\Catalog\Data\PriceEntryData;
use App\Modules\Catalog\Exceptions\CardNotFoundException;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Services\TcgplayerImportParser;
use Illuminate\Support\Facades\DB;

function fakeImportCard(string $tcgdexId, string $setTcgdexId, string $localId, string $name, array $prices = []): CardDetailData
{
    return CardDetailData::from([
        'tcgdexId' => $tcgdexId,
        'setTcgdexId' => $setTcgdexId,
        'localId' => $localId,
        'name' => $name,
        'rarity' => 'Common',
        'variants' => [],
        'officialImageUrl' => null,
        'prices' => $prices,
        'raw' => [],
    ]);
}

function fakePriceEntry(string $variant): PriceEntryData
{
    return new PriceEntryData(
        source: 'tcgplayer',
        variant: $variant,
        currency: 'USD',
        marketMinor: 1000,
        lowMinor: 900,
        trendMinor: null,
        sourceUpdatedAt: null,
        raw: [],
    );
}

test('parses a normal line and matches it against the catalog', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-068')->once()
        ->andReturn(fakeImportCard('me05-068', 'me05', '068', 'Toucannon'));

    $result = (new TcgplayerImportParser($provider))->parse('1 Toucannon - 068/084 [PBL] 068/084');

    expect($result->matched)->toHaveCount(1);
    expect($result->matched->first()->qty)->toBe(1);
    expect($result->matched->first()->tcgdexId)->toBe('me05-068');
    expect($result->matched->first()->name)->toBe('Toucannon');
    expect($result->unmatched)->toHaveCount(0);
});

test('resolves two lines with the same name and different disambiguators as two distinct cards', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-093')->once()
        ->andReturn(fakeImportCard('me05-093', 'me05', '093', 'Bastiodon'));
    $provider->shouldReceive('findCard')->with('me05-062')->once()
        ->andReturn(fakeImportCard('me05-062', 'me05', '062', 'Bastiodon'));

    $result = (new TcgplayerImportParser($provider))->parse(
        "1 Bastiodon - 093/084 [PBL] 093/084\n1 Bastiodon - 062/084 [PBL] 062/084"
    );

    expect($result->matched)->toHaveCount(2);
    expect($result->matched->pluck('tcgdexId')->all())->toEqualCanonicalizing(['me05-093', 'me05-062']);
});

test('parses a basic energy line under the MEE set code', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('mee-002')->once()
        ->andReturn(fakeImportCard('mee-002', 'mee', '002', 'Fire Energy'));

    $result = (new TcgplayerImportParser($provider))->parse('1 Basic Fire Energy - 002 [MEE] 2');

    expect($result->matched)->toHaveCount(1);
    expect($result->matched->first()->tcgdexId)->toBe('mee-002');
    expect($result->matched->first()->name)->toBe('Fire Energy');
});

test('flags an unrecognized set code without calling the catalog', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldNotReceive('findCard');

    $result = (new TcgplayerImportParser($provider))->parse('1 Some Card [ZZZ] 001/100');

    expect($result->matched)->toHaveCount(0);
    expect($result->unmatched)->toHaveCount(1);
    expect($result->unmatched->first()->reason)->toBe('unknown_set');
    expect($result->unmatched->first()->rawLine)->toBe('1 Some Card [ZZZ] 001/100');
});

test('flags a card tcgdex does not recognize', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-999')->once()
        ->andThrow(CardNotFoundException::forTcgdexId('me05-999'));

    $result = (new TcgplayerImportParser($provider))->parse('1 Ghost Card [PBL] 999/084');

    expect($result->matched)->toHaveCount(0);
    expect($result->unmatched)->toHaveCount(1);
    expect($result->unmatched->first()->reason)->toBe('card_not_found');
});

test('flags a line that does not match the export format at all', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldNotReceive('findCard');

    $result = (new TcgplayerImportParser($provider))->parse('this is not a valid export line');

    expect($result->unmatched)->toHaveCount(1);
    expect($result->unmatched->first()->reason)->toBe('unparsed');
});

test('merges quantities for two lines resolving to the same card and looks it up only once', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-037')->once()
        ->andReturn(fakeImportCard('me05-037', 'me05', '037', 'Lampent'));

    $result = (new TcgplayerImportParser($provider))->parse("2 Lampent [PBL] 037/084\n1 Lampent [PBL] 037/084");

    expect($result->matched)->toHaveCount(1);
    expect($result->matched->first()->qty)->toBe(3);
});

test('resolves an already-synced card from the local catalog without calling tcgdex', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);

    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldNotReceive('findCard')->with('me05-068');
    $provider->shouldReceive('findCard')->with('me05-052')->once()
        ->andReturn(fakeImportCard('me05-052', 'me05', '052', 'Malamar'));

    $result = (new TcgplayerImportParser($provider))->parse(
        "1 Toucannon - 068/084 [PBL] 068/084\n1 Malamar [PBL] 052/084"
    );

    expect($result->matched)->toHaveCount(2);
    expect($result->matched->firstWhere('tcgdexId', 'me05-068')->name)->toBe('Toucannon');
    expect($result->matched->firstWhere('tcgdexId', 'me05-052')->name)->toBe('Malamar');
    expect($result->unmatched)->toHaveCount(0);
});

test('flags a locally-synced card as variant-ambiguous when it has more than one known price variant', function () {
    // TCGplayer's export never marks which physical copy is holofoil vs
    // normal, so two separately-exported lines for a card with a known
    // holofoil variant merge into one "qty 3, no variant" entry with zero
    // signal for which copies are which.
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-037', 'set_id' => $set->id, 'local_id' => '037', 'name' => 'Lampent']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 500]);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'holofoil', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 1500]);

    $provider = Mockery::mock(CardCatalogProvider::class);

    $result = (new TcgplayerImportParser($provider))->parse(
        "2 Lampent [PBL] 037/084\n1 Lampent [PBL] 037/084"
    );

    expect($result->matched)->toHaveCount(1);
    expect($result->matched->first()->qty)->toBe(3);
    expect($result->matched->first()->variantAmbiguous)->toBeTrue();
});

test('does not flag a locally-synced card with only one known price variant', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-068', 'set_id' => $set->id, 'local_id' => '068', 'name' => 'Toucannon']);
    CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 500]);

    $provider = Mockery::mock(CardCatalogProvider::class);

    $result = (new TcgplayerImportParser($provider))->parse('1 Toucannon - 068/084 [PBL] 068/084');

    expect($result->matched->first()->variantAmbiguous)->toBeFalse();
});

test('resolving multiple locally-synced lines runs a constant number of queries, not one per line', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    foreach (['068' => 'Toucannon', '037' => 'Lampent', '052' => 'Malamar'] as $localId => $name) {
        $card = Card::create(['tcgdex_id' => "me05-{$localId}", 'set_id' => $set->id, 'local_id' => $localId, 'name' => $name]);
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today(), 'currency' => 'USD', 'market_minor' => 500]);
    }

    // Without this, a fixture that silently stopped resolving locally would
    // fall through to findCard(), get swallowed as lookup_failed, and both
    // runs would log the same single query — passing without testing anything.
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldNotReceive('findCard');
    $parser = new TcgplayerImportParser($provider);

    // One line resolved locally shouldn't cost noticeably less than three —
    // Card::where()->first() and priceSnapshots()->count() per candidate
    // (the original bug) would instead scale 1:1 with the line count.
    DB::enableQueryLog();
    $parser->parse('1 Toucannon - 068/084 [PBL] 068/084');
    $oneLineQueries = count(DB::getQueryLog());

    DB::flushQueryLog();
    $result = $parser->parse("1 Toucannon - 068/084 [PBL] 068/084\n1 Lampent [PBL] 037/084\n1 Malamar [PBL] 052/084");
    $threeLineQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($result->matched)->toHaveCount(3);
    expect($result->unmatched)->toHaveCount(0);
    expect($threeLineQueries)->toBe($oneLineQueries);
});

test('flags a card resolved via tcgdex as variant-ambiguous when it reports more than one price variant', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-068')->once()
        ->andReturn(fakeImportCard('me05-068', 'me05', '068', 'Toucannon', [
            fakePriceEntry('normal'),
            fakePriceEntry('holofoil'),
        ]));

    $result = (new TcgplayerImportParser($provider))->parse('1 Toucannon - 068/084 [PBL] 068/084');

    expect($result->matched->first()->variantAmbiguous)->toBeTrue();
});

test('a transient catalog failure lands the line in unmatched instead of propagating', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-068')->once()
        ->andThrow(new RuntimeException('connection timed out'));

    $result = (new TcgplayerImportParser($provider))->parse('1 Toucannon - 068/084 [PBL] 068/084');

    expect($result->matched)->toHaveCount(0);
    expect($result->unmatched)->toHaveCount(1);
    expect($result->unmatched->first()->reason)->toBe('lookup_failed');
    expect($result->unmatched->first()->rawLine)->toBe('1 Toucannon - 068/084 [PBL] 068/084');
});

test('rejects a zero-quantity line instead of importing a card nobody owns', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldNotReceive('findCard');

    $result = (new TcgplayerImportParser($provider))->parse('0 Toucannon - 068/084 [PBL] 068/084');

    expect($result->matched)->toHaveCount(0);
    expect($result->unmatched)->toHaveCount(1);
    expect($result->unmatched->first()->reason)->toBe('unparsed');
});

test('ignores blank lines between real lines', function () {
    $provider = Mockery::mock(CardCatalogProvider::class);
    $provider->shouldReceive('findCard')->with('me05-068')->once()
        ->andReturn(fakeImportCard('me05-068', 'me05', '068', 'Toucannon'));

    $result = (new TcgplayerImportParser($provider))->parse("\n1 Toucannon - 068/084 [PBL] 068/084\n\n");

    expect($result->matched)->toHaveCount(1);
    expect($result->unmatched)->toHaveCount(0);
});
