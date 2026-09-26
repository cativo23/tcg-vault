<?php

namespace Tests;

use App\Modules\Collection\Services\PhotoMetadataStripper;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakePhotoMetadataStripper;

abstract class TestCase extends BaseTestCase
{
    protected FakePhotoMetadataStripper $photoStripper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->photoStripper = new FakePhotoMetadataStripper;
        $this->app->instance(PhotoMetadataStripper::class, $this->photoStripper);
    }
}
