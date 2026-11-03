<?php

use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Collection\CollectionServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\TelescopeServiceProvider;

return [
    CatalogServiceProvider::class,
    CollectionServiceProvider::class,
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    TelescopeServiceProvider::class,
];
