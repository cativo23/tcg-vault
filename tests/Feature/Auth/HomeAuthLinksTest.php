<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Settings\RegistrationSettings;

test('a guest always sees a login link on the home page', function () {
    app(RegistrationSettings::class)->open = false;
    app(RegistrationSettings::class)->save();

    $this->get('/')->assertOk()->assertSee('Log in');
});

test('a guest sees a sign-up link and open-registration copy when registration is open', function () {
    app(RegistrationSettings::class)->open = true;
    app(RegistrationSettings::class)->save();

    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('Sign up');
    $response->assertSee('Create your account');
    $response->assertDontSee('Invite-only');
});

test('a guest sees invite-only copy and no sign-up link when registration is closed', function () {
    app(RegistrationSettings::class)->open = false;
    app(RegistrationSettings::class)->save();

    $response = $this->get('/');

    $response->assertOk();
    $response->assertDontSee('Sign up');
    $response->assertSee('Invite-only');
});

test('the privacy FAQ points to where the visibility control actually is', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('My Collection')
        ->assertDontSee('from your profile');
});

function publicDemo(string $email = 'demo@tcg-vault.invalid', bool $withItem = true): void
{
    config(['tcgvault.demo.username' => 'demo', 'tcgvault.demo.email' => 'demo@tcg-vault.invalid']);
    $demo = User::factory()->create(['username' => 'demo', 'email' => $email]);
    $collection = Collection::factory()->for($demo)->create(['is_public' => true]);

    if ($withItem) {
        $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
        $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
        CollectionItem::create([
            'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1,
        ]);
    }
}

test('the home page links to the example collection once it is public', function () {
    publicDemo();

    $this->get('/')
        ->assertOk()
        ->assertSee(route('gallery.index', ['username' => 'demo']))
        ->assertSee('See an example collection');
});

test('the home page hides the example link while there is no public demo collection', function () {
    config(['tcgvault.demo.username' => 'demo']);
    $demo = User::factory()->create(['username' => 'demo']);
    Collection::factory()->for($demo)->create(['is_public' => false]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('See an example collection');
});

test('the home page ignores a real member who took the demo username', function () {
    publicDemo(email: 'someone@example.test');

    $this->get('/')->assertOk()->assertDontSee('See an example collection');
});

test('the home page hides the example link while the demo collection has no cards', function () {
    publicDemo(withItem: false);

    $this->get('/')->assertOk()->assertDontSee('See an example collection');
});
