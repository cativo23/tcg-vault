<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class and all PHPUnit assertions are available within the scope of a single test.
|
*/

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature');

// RefreshDatabase resets the database between tests but not the cache
// store — spatie/laravel-settings caches settings classes (see
// config/settings.php), and the 'array' cache driver used in testing
// lives for the whole process, not per-test, so a value cached by one
// test can otherwise leak into the next.
afterEach(function () {
    \Illuminate\Support\Facades\Cache::flush();
});

// Unit tests under Modules/Catalog need the Laravel container for Http::fake()/config(),
// but this binding does not apply to other Unit tests.
uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Unit/Modules/Catalog');

// Unit tests under Modules/Collection need the Laravel container and a real database
// (CollectionService writes CollectionItem rows via Eloquent), same rationale as Catalog above.
uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Unit/Modules/Collection');

// Unit/Jobs tests let a real CatalogSyncService run against a mocked
// CardCatalogProvider (CatalogSyncService is final, can't be mocked directly),
// so they need the container and a real database too.
uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Unit/Jobs');

// Unit/Providers tests boot a real service provider instance against the
// container (config(), URL facade), no database needed.
uses(Tests\TestCase::class)->in('Unit/Providers');

// Unit/Modules/Invites tests exercise a real Invite model against the
// database (factory + Eloquent), same rationale as Catalog/Collection above.
uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Unit/Modules/Invites');

// Unit/Settings tests exercise real spatie/laravel-settings classes
// against the database and its cache layer, same rationale as the
// others above.
uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Unit/Settings');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can call
| to assert various things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeWithinRange', function (int $min, int $max) {
    return $this->toBeGreaterThanOrEqual($min)->toBeLessThanOrEqual($max);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce duplication, improve readability, and keep focus.
|
*/
