<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Providers\TcgdexCardCatalogProvider;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            CardCatalogProvider::class,
            fn () => new TcgdexCardCatalogProvider(config('tcgdex.base_url')),
        );
    }
}
