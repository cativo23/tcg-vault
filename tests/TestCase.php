<?php

namespace Tests;

use App\Modules\Catalog\Contracts\CardImageFallback;
use App\Modules\Collection\Contracts\PhotoMetadataStripper;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakePhotoMetadataStripper;
use Tests\Support\NullCardImageFallback;

abstract class TestCase extends BaseTestCase
{
    protected FakePhotoMetadataStripper $photoStripper;

    protected NullCardImageFallback $imageFallback;

    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach the real network: an unfaked request fails.
        Http::preventStrayRequests();

        $this->imageFallback = new NullCardImageFallback;
        $this->app->instance(CardImageFallback::class, $this->imageFallback);

        $this->photoStripper = new FakePhotoMetadataStripper;
        $this->app->instance(PhotoMetadataStripper::class, $this->photoStripper);
    }
}
