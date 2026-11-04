<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Settings\RegistrationSettings;
use Illuminate\Support\Facades\DB;

function setRegistration(bool $open): void
{
    app(RegistrationSettings::class)->open = $open;
    app(RegistrationSettings::class)->save();
}

function landingDemo(string $email = 'demo@tcg-vault.invalid'): void
{
    config(['tcgvault.demo.username' => 'demo', 'tcgvault.demo.email' => 'demo@tcg-vault.invalid']);
    $demo = User::factory()->create(['username' => 'demo', 'email' => $email]);
    $collection = Collection::factory()->for($demo)->create(['is_public' => true]);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black']);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create([
        'collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1,
    ]);
}

test('the landing uses the revamp shell with a theme toggle, login link and legal footer', function () {
    $this->get('/')->assertOk()
        ->assertSee('data-theme-toggle', escape: false)
        ->assertSee('href="'.route('login').'"', escape: false)
        ->assertSee(route('privacy'), escape: false)
        ->assertSee(route('terms'), escape: false)
        ->assertSee('tcgdex.dev')
        ->assertSee('Not affiliated with Nintendo, Creatures, GAME FREAK or The Pokémon Company.')
        ->assertSee('<header class="bar">', escape: false)
        ->assertSee('<a class="skip" href="#main">', escape: false)
        ->assertSee('class="theme-toggle" data-theme-toggle', escape: false)
        ->assertSee('<footer class="foot">', escape: false)
        ->assertDontSee('nw-topbar', escape: false);
});

test('the landing is indexable, describes itself and uses no client-side navigation', function () {
    $this->get('/')->assertOk()
        ->assertDontSee('wire:navigate', escape: false)
        ->assertDontSee('noindex', escape: false)
        ->assertSee('<meta name="description" content="Every card you own, on one page.">', escape: false);
});

test('the landing keeps the site title and does not call a third-party font host', function () {
    $this->get('/')->assertOk()
        ->assertSee('<title>tcg-vault</title>', escape: false)
        ->assertDontSee('fonts.googleapis.com')
        ->assertDontSee('fonts.gstatic.com');
});

test('the landing shows every approved copy block verbatim', function () {
    $this->get('/')->assertOk()->assertSeeInOrder([
        'Every card you&nbsp;own, on one page.',
        'Make a list of your Pokémon cards, from the chase card to the bulk commons. tcg-vault shows each one with its picture and today\'s price, and gives your whole collection a link you can send to anyone.',
        'Send a link instead of a spreadsheet.',
        'Your page lives at',
        'tcgvault.cativo.dev/yourname',
        'Anyone who opens it sees your cards set by set and what they\'re worth. They don\'t need an account.',
        'Prices update every day.',
        'Each card gets a tcgplayer price in dollars and a Cardmarket price in euros. The total at the top of your page is today\'s number, and a chart shows how it has moved.',
        'Open a set to see what\'s missing.',
        'The cards you have show in color. The ones you don\'t stay in place, greyed out, with their price, so you can see what you still need and what it costs.',
        'For now, it\'s a place for your collection.',
        'Building decks from your cards or marking some for sale are ideas for later. Today it keeps your list priced and makes it look good when you share it.',
        'Free. Export your whole collection to CSV at any time.',
    ], escape: false);
});

test('closed registration shows the invitation note and never the sign-up button', function () {
    setRegistration(false);

    $this->get('/')->assertOk()
        ->assertSee('Accounts are by invitation for now.')
        ->assertDontSee('Create your account');
});

test('open registration shows Create your account', function () {
    setRegistration(true);

    $this->get('/')->assertOk()
        ->assertSee('Create your account')
        ->assertSee(route('register'), escape: false)
        ->assertDontSee('Accounts are by invitation for now.');
});

test('the demo button appears only when a public demo collection has cards', function () {
    setRegistration(false);
    $this->get('/')->assertOk()->assertDontSee('See the demo collection');

    landingDemo();

    $this->get('/')->assertOk()
        ->assertSee('See the demo collection')
        ->assertSee(route('gallery.index', ['username' => 'demo']), escape: false);
});

test('a real member holding the demo username never gets advertised as the demo', function () {
    landingDemo(email: 'someone@example.test');

    $this->get('/')->assertOk()->assertDontSee('See the demo collection');
});

test('the landing no longer carries the old marketing copy or the owner\'s photo', function () {
    $this->get('/')->assertOk()
        ->assertDontSee('Track every')
        ->assertDontSee('carlos-mega-darkrai');
});

test('the landing runs a bounded number of queries without a demo user', function () {
    DB::enableQueryLog();
    $this->get('/')->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(2);
});

test('the landing mounts the stage and the build ships its script', function () {
    $this->get('/')->assertOk()->assertSee('id="stage"', escape: false);
    expect(file_get_contents(public_path('build/manifest.json')))->toContain('resources/js/landing-stage.js');
});
