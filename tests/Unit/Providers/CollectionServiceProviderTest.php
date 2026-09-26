<?php

declare(strict_types=1);

use App\Modules\Collection\Contracts\PhotoMetadataStripper;
use App\Modules\Collection\Services\ExiftoolPhotoMetadataStripper;

// TestCase binds a fake stripper for every test; this drops it to check
// what the application itself resolves.
test('the app resolves the photo stripper to the exiftool implementation', function () {
    $this->app->forgetInstance(PhotoMetadataStripper::class);

    expect(app(PhotoMetadataStripper::class))->toBeInstanceOf(ExiftoolPhotoMetadataStripper::class);
});
