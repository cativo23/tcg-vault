<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Set;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(['tcgcsv.com/tcgplayer/3/groups' => Http::response(['success' => true, 'errors' => [], 'results' => [
        ['groupId' => 24587, 'name' => 'ME03: Perfect Order', 'abbreviation' => 'POR', 'isSupplemental' => false, 'publishedOn' => '2026-03-27T00:00:00'],
        ['groupId' => 24722, 'name' => 'ME: 30th Celebration', 'abbreviation' => '30C', 'isSupplemental' => false, 'publishedOn' => '2026-09-16T00:00:00'],
        ['groupId' => 24837, 'name' => 'ME: 30th Celebration Classic Collection', 'abbreviation' => '30C', 'isSupplemental' => true, 'publishedOn' => '2026-09-16T00:00:00'],
    ]], 200)]);
    Set::create(['tcgdex_id' => 'me03', 'name' => 'Perfect Order', 'abbreviation' => 'POR']);
    Set::create(['tcgdex_id' => '30th', 'name' => '30th Celebration', 'abbreviation' => '30C']);
    Set::create(['tcgdex_id' => 'svp', 'name' => 'SVP Black Star Promos']);
});

test('it proposes a group for each set by abbreviation and writes nothing by default', function () {
    $this->artisan('catalog:propose-tcgplayer-groups')
        ->expectsOutputToContain('24587 ME03: Perfect Order')
        ->expectsOutputToContain('ambiguous')
        ->expectsOutputToContain('no abbreviation')
        ->assertSuccessful();

    expect(DB::table('set_tcgplayer_groups')->count())->toBe(0);
});

test('--write stores only the unambiguous matches', function () {
    $this->artisan('catalog:propose-tcgplayer-groups', ['--write' => true])->assertSuccessful();

    expect(DB::table('set_tcgplayer_groups')->pluck('group_id')->all())->toBe([24587]);
});

test('an explicit set and group pair is stored after checking the group exists', function () {
    $this->artisan('catalog:propose-tcgplayer-groups', ['--set' => '30th', '--group' => '24722'])->assertSuccessful();
    $this->artisan('catalog:propose-tcgplayer-groups', ['--set' => '30th', '--group' => '99999'])->assertFailed();

    expect(DB::table('set_tcgplayer_groups')->pluck('group_id')->all())->toBe([24722]);
});

test('an explicit pair for an unknown set is refused', function () {
    $this->artisan('catalog:propose-tcgplayer-groups', ['--set' => 'nope', '--group' => '24722'])->assertFailed();

    expect(DB::table('set_tcgplayer_groups')->count())->toBe(0);
});
