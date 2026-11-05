<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\CardPriceSnapshot;
use App\Modules\Catalog\Models\Set;
use Illuminate\Support\Facades\DB;

test('the command fills the week baselines, and running it again changes nothing', function () {
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    foreach ([0 => 1240, 7 => 1000] as $days => $minor) {
        CardPriceSnapshot::create(['card_id' => $card->id, 'source' => 'tcgplayer', 'variant' => 'normal', 'captured_on' => today()->subDays($days), 'currency' => 'USD', 'market_minor' => $minor]);
    }

    $this->artisan('prices:refresh-week-baselines')->expectsOutput('Week baselines refreshed: 1 print has one.')->assertSuccessful();
    $first = DB::table('card_price_week_baselines')->get()->map(fn (object $r) => (array) $r)->all();
    $this->artisan('prices:refresh-week-baselines')->assertSuccessful();

    expect(DB::table('card_price_week_baselines')->get()->map(fn (object $r) => (array) $r)->all())->toBe($first)
        ->and($first)->toHaveCount(1);
});
