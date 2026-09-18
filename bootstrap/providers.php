<?php

use App\Modules\Catalog\CatalogServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\TelescopeServiceProvider;
use App\Providers\VoltServiceProvider;

return [
    CatalogServiceProvider::class,
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    TelescopeServiceProvider::class,
    VoltServiceProvider::class,
];
