<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Modules\Catalog\Contracts\CardCatalogProvider;
use App\Modules\Catalog\Contracts\CardImageFallback;
use App\Modules\Catalog\Providers\TcgdexCardCatalogProvider;
use App\Modules\Catalog\Services\TcgdexImageFallback;
use App\Modules\Catalog\Tcgcsv\TcgcsvClient;
use App\Modules\Catalog\Tcgcsv\TcgcsvPriceParser;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            CardImageFallback::class,
            fn () => new TcgdexImageFallback((string) config('tcgdex.base_url')),
        );

        $this->app->singleton(
            CardCatalogProvider::class,
            fn () => new TcgdexCardCatalogProvider(config('tcgdex.base_url')),
        );

        $this->app->singleton(
            TcgcsvClient::class,
            fn () => new TcgcsvClient(
                (string) config('tcgcsv.base_url'),
                (string) config('tcgcsv.user_agent'),
                new TcgcsvPriceParser,
            ),
        );
    }
}
