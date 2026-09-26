<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Collection\Services\PhotoMetadataStripFailed;
use App\Modules\Collection\Services\PhotoMetadataStripper;

/**
 * Stands in for exiftool in feature tests, which then don't need the binary
 * (the real stripper has its own tests). Records what it was given and can
 * be told to fail.
 */
final class FakePhotoMetadataStripper implements PhotoMetadataStripper
{
    /** @var list<string> */
    public array $stripped = [];

    public bool $fail = false;

    public function strip(string $absolutePath): void
    {
        if ($this->fail) {
            throw new PhotoMetadataStripFailed('Told to fail.');
        }

        $this->stripped[] = $absolutePath;
    }
}
