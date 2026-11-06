<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalog\Models\Card;
use App\Modules\Catalog\Models\Set;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;

/** Every <a> whose href is exactly the collection URL but that opts into client-side navigation. */
function navigatingCollectionLinks(string $html): array
{
    $url = route('gallery.index', ['username' => 'carlos']);
    preg_match_all('#<a\b[^>]*>#', $html, $tags);

    return array_values(array_filter($tags[0], fn (string $tag) => str_contains($tag, 'href="'.$url.'"') && str_contains($tag, 'wire:navigate')));
}

test('no page wire:navigates into the revamp collection page', function (string $path) {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $html = $this->get($path)->assertOk()->getContent();

    // The page really links to the collection (brand and nav), so an empty list is not vacuous.
    expect($html)->toContain('href="'.route('gallery.index', ['username' => 'carlos']).'"')
        ->and(navigatingCollectionLinks($html))->toBe([]);
})->with([
    'a set page' => ['/carlos/me05'],
    'sets' => ['/carlos/sets'],
    'activity' => ['/carlos/activity'],
    'a card page' => ['/carlos/me05/116'],
]);

/** Every <a> whose href is exactly the sets URL but that opts into client-side navigation. */
function navigatingSetsLinks(string $html): array
{
    $url = route('gallery.sets', ['username' => 'demo']);
    preg_match_all('#<a\b[^>]*>#', $html, $tags);

    return array_values(array_filter($tags[0], fn (string $tag) => str_contains($tag, 'href="'.$url.'"') && str_contains($tag, 'wire:navigate')));
}

// A page on the old layout must never client-side-navigate into the revamp sets page (the swap would keep app.css);
// each row stays a guard once its page moves to the revamp shell.
test('no page wire:navigates into the revamp sets page', function (string $path) {
    $user = User::factory()->create(['username' => 'demo', 'name' => 'Demo']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $html = $this->get($path)->assertOk()->getContent();

    // The page really links to the sets page, so an empty list is not vacuous.
    expect($html)->toContain('href="'.route('gallery.sets', ['username' => 'demo']).'"')
        ->and(navigatingSetsLinks($html))->toBe([]);
})->with([
    'a set page' => ['/demo/me05'],
    'activity' => ['/demo/activity'],
    'a card page' => ['/demo/me05/116'],
]);

/** Every <a> whose href is exactly the activity URL but that opts into client-side navigation. */
function activityNavigatingLinks(string $html): array
{
    $url = route('gallery.activity', ['username' => 'carlos']);
    preg_match_all('#<a\b[^>]*>#', $html, $tags);

    return array_values(array_filter($tags[0], fn (string $tag) => str_contains($tag, 'href="'.$url.'"') && str_contains($tag, 'wire:navigate')));
}

test('no page wire:navigates into the revamp activity page', function (string $path) {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $html = $this->get($path)->assertOk()->getContent();

    // The page really links here (nav), so an empty list is not vacuous.
    expect($html)->toContain('href="'.route('gallery.activity', ['username' => 'carlos']).'"')
        ->and(activityNavigatingLinks($html))->toBe([]);
})->with([
    'a set page' => ['/carlos/me05'],
    'sets' => ['/carlos/sets'],
    'a card page' => ['/carlos/me05/116'],
    'the collection' => ['/carlos'],
]);

/** Every <a> pointing at the card page that opts into client-side navigation. */
function navigatingCardLinks(string $html, string $cardUrl): array
{
    preg_match_all('#<a\b[^>]*>#', $html, $tags);

    return array_values(array_filter($tags[0], fn (string $tag) => str_contains($tag, 'href="'.$cardUrl.'"') && str_contains($tag, 'wire:navigate')));
}

test('no page wire:navigates into the revamp card page', function (string $path) {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);
    $cardUrl = route('gallery.card', ['username' => 'carlos', 'setTcgdexId' => 'me05', 'localId' => '116']);

    $html = $this->get($path)->assertOk()->getContent();

    // The page really links to the card (set tile, activity row), so an empty list is not vacuous.
    expect($html)->toContain('href="'.$cardUrl.'"')
        ->and(navigatingCardLinks($html, $cardUrl))->toBe([]);
})->with([
    'a set page' => ['/carlos/me05'],
    'activity' => ['/carlos/activity'],
]);

/** Every <a> whose href is exactly the set page's URL but that opts into client-side navigation. */
function navigatingSetPageLinks(string $html): array
{
    $url = route('gallery.show', ['username' => 'carlos', 'setTcgdexId' => 'me05']);
    preg_match_all('#<a\b[^>]*>#', $html, $tags);

    return array_values(array_filter($tags[0], fn (string $tag) => str_contains($tag, 'href="'.$url.'"') && str_contains($tag, 'wire:navigate')));
}

test('no page wire:navigates into the revamp set page', function (string $path) {
    $user = User::factory()->create(['username' => 'carlos']);
    $collection = Collection::factory()->for($user)->create(['is_public' => true, 'slug' => 'main']);
    $set = Set::create(['tcgdex_id' => 'me05', 'name' => 'Pitch Black', 'card_count' => 120]);
    $card = Card::create(['tcgdex_id' => 'me05-116', 'set_id' => $set->id, 'local_id' => '116', 'name' => 'Mega Darkrai ex']);
    CollectionItem::create(['collection_id' => $collection->id, 'card_id' => $card->id, 'card_tcgdex_id' => 'me05-116', 'condition' => 'NM', 'quantity' => 1]);

    $html = $this->get($path)->assertOk()->getContent();

    // The page really links to the set, so an empty list is not vacuous.
    expect($html)->toContain('href="'.route('gallery.show', ['username' => 'carlos', 'setTcgdexId' => 'me05']).'"')
        ->and(navigatingSetPageLinks($html))->toBe([]);
})->with([
    'the collection, set tab selected' => ['/carlos?set=me05'],
    'sets' => ['/carlos/sets'],
    'a card page' => ['/carlos/me05/116'],
]);
