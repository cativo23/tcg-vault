<?php

declare(strict_types=1);

namespace App\Modules\Collection;

use App\Modules\Collection\Contracts\PhotoMetadataStripper;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Models\CollectionItem;
use App\Modules\Collection\Observers\CollectionItemObserver;
use App\Modules\Collection\Observers\CollectionObserver;
use App\Modules\Collection\Services\ExiftoolPhotoMetadataStripper;
use Illuminate\Support\ServiceProvider;

final class CollectionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            PhotoMetadataStripper::class,
            fn () => new ExiftoolPhotoMetadataStripper((string) config('tcgvault.exiftool_path')),
        );
    }

    public function boot(): void
    {
        CollectionItem::observe(CollectionItemObserver::class);
        Collection::observe(CollectionObserver::class);
    }
}
