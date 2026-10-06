<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Set;
use Illuminate\Support\Facades\Http;

test('a group name\'s console style tags are shown as text, not interpreted', function () {
    Http::fake(['tcgcsv.com/tcgplayer/3/groups' => Http::response(['success' => true, 'errors' => [], 'results' => [
        ['groupId' => 24587, 'name' => '<error>ME03</error>: Perfect Order', 'abbreviation' => 'POR', 'isSupplemental' => false],
    ]], 200)]);
    Set::create(['tcgdex_id' => 'me03', 'name' => 'Perfect Order', 'abbreviation' => 'POR']);

    $this->artisan('catalog:propose-tcgplayer-groups')
        ->expectsOutputToContain('24587 <error>ME03</error>: Perfect Order')
        ->assertSuccessful();
});
