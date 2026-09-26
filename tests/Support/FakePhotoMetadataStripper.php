<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Collection\Contracts\PhotoMetadataStripper;
use App\Modules\Collection\Exceptions\PhotoMetadataStripException;

/**
 * Stands in for exiftool in feature tests, which then don't need the binary
 * (the real stripper has its own tests). Records what it was given, marks
 * the file so a test can check the stored copy is the stripped one, and can
 * be told to fail.
 */
final class FakePhotoMetadataStripper implements PhotoMetadataStripper
{
    public const MARKER = '[stripped-by-fake]';

    /** @var list<string> */
    public array $stripped = [];

    public bool $fail = false;

    public function strip(string $absolutePath): void
    {
        if ($this->fail) {
            throw new PhotoMetadataStripException('Told to fail.');
        }

        $this->stripped[] = $absolutePath;
        file_put_contents($absolutePath, self::MARKER, FILE_APPEND);
    }
}
