<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Providers\TcgdexCardCatalogProvider;
use App\Modules\Catalog\Services\TcgdexImageFallback;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            TcgdexImageFallback::class,
            fn () => new TcgdexImageFallback((string) config('tcgdex.base_url')),
        );

        $this->app->singleton(
            CardCatalogProvider::class,
            fn ($app) => new TcgdexCardCatalogProvider(config('tcgdex.base_url'), $app->make(TcgdexImageFallback::class)),
        );
    }
}
