<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\CardImageFallback;
use App\Modules\Catalog\Services\TcgdexImageFallback;

// TestCase binds a null image fallback for every test; this drops it to
// check what the application itself resolves.
test('the app resolves the card image fallback to the tcgdex implementation', function () {
    $this->app->forgetInstance(CardImageFallback::class);

    expect(app(CardImageFallback::class))->toBeInstanceOf(TcgdexImageFallback::class);
});
