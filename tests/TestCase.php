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

    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach the real network: an unfaked request fails.
        Http::preventStrayRequests();

        $this->app->instance(CardImageFallback::class, new NullCardImageFallback);

        $this->photoStripper = new FakePhotoMetadataStripper;
        $this->app->instance(PhotoMetadataStripper::class, $this->photoStripper);
    }
}
