<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Tests\Support\RevampHtml;

/**
 * layouts.revamp rendered as the real route for $uri renders it, so
 * request()->routeIs() and the {username} segment behave as in production.
 *
 * @param  array<string, mixed>  $with
 */
function revampPage(string $uri, array $with = []): string
{
    $request = Request::create($uri);
    $route = Route::getRoutes()->match($request);
    $request->setRouteResolver(fn () => $route);
    app()->instance('request', $request);

    return view('layouts.revamp', ['slot' => new HtmlString('<p>page body</p>'), ...$with])->render();
}

test('the bar names the three collector pages and marks the one you are on', function (string $uri, string $current) {
    $html = revampPage($uri);

    expect(RevampHtml::currentNav($html))->toBe($current)
        ->and($html)->toContain('<header class="bar bar--nav">')
        ->and($html)->toContain('<nav class="bar-nav" aria-label="Gallery">')
        ->and($html)->toContain('href="'.route('gallery.index', ['username' => 'carlos']).'"')
        ->and($html)->toContain('href="'.route('gallery.sets', ['username' => 'carlos']).'"')
        ->and($html)->toContain('href="'.route('gallery.activity', ['username' => 'carlos']).'"');
})->with([
    'collection' => ['/carlos', 'Collection'],
    'a card' => ['/carlos/me05/116', 'Collection'],
    'sets' => ['/carlos/sets', 'Sets'],
    'a set' => ['/carlos/me05', 'Sets'],
    'activity' => ['/carlos/activity', 'Activity'],
]);

test('the brand links to the collector\'s collection when there is a username, to the landing otherwise', function () {
    $page = revampPage('/carlos');
    expect($page)->toContain('<a class="mark" href="'.route('gallery.index', ['username' => 'carlos']).'">');

    $landing = revampPage('/');
    expect($landing)->toContain('<a class="mark" href="'.url('/').'">');
});

test('the landing keeps the bare fixed bar: no nav, no flow modifier', function () {
    $html = revampPage('/');

    expect($html)->toContain('<header class="bar">')
        ->and($html)->not->toContain('bar-nav')
        ->and($html)->not->toContain('bar--nav');
});

test('the nav is plain HTML: no Alpine, no client-side navigation, no hamburger', function () {
    $html = revampPage('/carlos');

    expect($html)->not->toContain('x-data')
        ->and($html)->not->toContain('@click')
        ->and($html)->not->toContain('wire:navigate')
        ->and($html)->not->toContain('hamburger');
});

test('a guest gets Log in and no Manage collection', function () {
    $html = revampPage('/carlos');

    expect($html)->toContain('href="'.route('login').'"')
        ->and($html)->toContain('Log in')
        ->and($html)->not->toContain('Manage collection');
});

test('a logged-in visitor gets no Log in and no link into an account that is not theirs', function () {
    $this->actingAs(User::factory()->create(['username' => 'ash']));

    $html = revampPage('/carlos');

    expect($html)->not->toContain('Log in')
        ->and($html)->not->toContain('Manage collection')
        ->and($html)->not->toContain(route('admin.collection.index'));
});

test('the collector looking at their own page gets Manage collection, and no Log in', function () {
    $this->actingAs(User::factory()->create(['username' => 'carlos']));

    $html = revampPage('/carlos', ['isOwner' => true]);

    expect($html)->toContain('href="'.route('admin.collection.index').'"')
        ->and($html)->toContain('Manage collection')
        ->and($html)->not->toContain('Log in');
});

test('pages can add to the head through a stack', function () {
    $html = Blade::render('@push("head")<meta data-test-head>@endpush{!! view("layouts.revamp", ["slot" => ""])->render() !!}');

    expect($html)->toContain('<meta data-test-head>');
});
