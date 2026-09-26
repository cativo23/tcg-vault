<?php

use Illuminate\Support\Facades\Storage;

test('every stored photo is stripped', function () {
    Storage::fake('collection-photos');
    Storage::disk('collection-photos')->put('a.jpg', 'a');
    Storage::disk('collection-photos')->put('b.webp', 'b');

    $this->artisan('photos:strip-metadata')
        ->expectsOutputToContain('Stripped 2 of 2 photos.')
        ->assertSuccessful();

    expect($this->photoStripper->stripped)->toEqualCanonicalizing([
        Storage::disk('collection-photos')->path('a.jpg'),
        Storage::disk('collection-photos')->path('b.webp'),
    ]);
});

test('hidden files on the disk are left alone', function () {
    Storage::fake('collection-photos');
    Storage::disk('collection-photos')->put('.gitignore', '*');
    Storage::disk('collection-photos')->put('a.jpg', 'a');

    $this->artisan('photos:strip-metadata')->assertSuccessful();

    expect($this->photoStripper->stripped)->toBe([Storage::disk('collection-photos')->path('a.jpg')]);
});

test('a photo that cannot be stripped is reported and the command fails', function () {
    Storage::fake('collection-photos');
    Storage::disk('collection-photos')->put('a.jpg', 'a');
    $this->photoStripper->fail = true;

    $this->artisan('photos:strip-metadata')
        ->expectsOutputToContain('a.jpg')
        ->expectsOutputToContain('Stripped 0 of 1 photos.')
        ->assertFailed();
});

test('working copies left by an interrupted strip are removed once stale', function () {
    // A killed process can't run its cleanup, and a working copy holds the
    // unstripped original, so old ones are deleted rather than skipped.
    Storage::fake('collection-photos');
    $disk = Storage::disk('collection-photos');
    $disk->put('.strip-old.jpg', 'unstripped');
    $disk->put('.strip-old.jpg_exiftool_tmp', 'partial');
    $disk->put('.strip-recent.jpg', 'in progress');
    touch($disk->path('.strip-old.jpg'), now()->subHour()->getTimestamp());
    touch($disk->path('.strip-old.jpg_exiftool_tmp'), now()->subHour()->getTimestamp());

    $this->artisan('photos:strip-metadata')->assertSuccessful();

    expect($disk->exists('.strip-old.jpg'))->toBeFalse()
        ->and($disk->exists('.strip-old.jpg_exiftool_tmp'))->toBeFalse()
        ->and($disk->exists('.strip-recent.jpg'))->toBeTrue()
        ->and($this->photoStripper->stripped)->toBe([]);
});
