<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Support\AccountDeleter;
use Illuminate\Support\Facades\Storage;

/** A member with one item whose photo is on disk. */
function memberWithPhoto(string $photo): User
{
    $member = User::factory()->create();
    $set = Set::firstOrCreate(['tcgdex_id' => 'me05'], ['name' => 'Pitch Black']);
    $card = Card::firstOrCreate(['tcgdex_id' => 'me05-116'], ['set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    $collection = Collection::factory()->for($member)->create();
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116',
        'condition' => 'NM', 'quantity' => 1, 'photo_path' => $photo,
    ]);
    Storage::disk('collection-photos')->put($photo, 'bytes');

    return $member;
}

test('deleting someone else’s account removes their photos too, not just the signed-in user’s', function () {
    Storage::fake('collection-photos');
    $member = memberWithPhoto('member.jpg');
    $staff = memberWithPhoto('staff.jpg');
    $this->actingAs($staff);

    app(AccountDeleter::class)->delete($member);

    expect(User::whereKey($member->id)->exists())->toBeFalse()
        ->and(Storage::disk('collection-photos')->exists('member.jpg'))->toBeFalse()
        ->and(Storage::disk('collection-photos')->exists('staff.jpg'))->toBeTrue();
});

test('deleting an account with nobody signed in still removes its photos', function () {
    Storage::fake('collection-photos');
    $member = memberWithPhoto('member.jpg');

    app(AccountDeleter::class)->delete($member);

    expect(Storage::disk('collection-photos')->exists('member.jpg'))->toBeFalse();
});
