<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

/**
 * Removes the metadata a phone embeds in a photo (location, device, author)
 * from a file in place, without re-encoding the image.
 */
interface PhotoMetadataStripper
{
    /**
     * @param  string  $absolutePath  a local file that already passed image validation
     *
     * @throws PhotoMetadataStripFailed when the file can't be processed; it must then not be stored
     */
    public function strip(string $absolutePath): void;
}
