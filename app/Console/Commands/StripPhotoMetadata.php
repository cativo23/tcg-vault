<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Collection\Contracts\PhotoMetadataStripper;
use App\Modules\Collection\Exceptions\PhotoMetadataStripException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Strips metadata from every photo on the collection-photos disk, for
 * files that were stored without going through the upload stripping.
 * Safe to run again: an already-stripped file just stays as it is.
 *
 * Also deletes stale working copies (`.strip-*`) that an interrupted strip
 * left behind; they hold the unstripped original, so skipping them like
 * other hidden files would leave that on the public disk.
 */
final class StripPhotoMetadata extends Command
{
    protected $signature = 'photos:strip-metadata';

    protected $description = 'Remove location and other metadata from every stored collection photo.';

    /** Older than any strip still running, given its 5-second timeout. */
    private const STALE_WORKING_COPY_SECONDS = 600;

    public function handle(PhotoMetadataStripper $stripper): int
    {
        $disk = Storage::disk('collection-photos');

        foreach ($disk->allFiles() as $file) {
            if (str_starts_with(basename($file), '.strip-')
                && $disk->lastModified($file) < now()->getTimestamp() - self::STALE_WORKING_COPY_SECONDS) {
                $disk->delete($file);
            }
        }

        $files = array_values(array_filter(
            $disk->allFiles(),
            fn (string $file) => ! str_starts_with(basename($file), '.'),
        ));

        $stripped = 0;
        foreach ($files as $file) {
            try {
                $stripper->strip($disk->path($file));
                $stripped++;
            } catch (PhotoMetadataStripException $e) {
                $this->error("$file: {$e->getMessage()}");
            }
        }

        $this->info(sprintf('Stripped %d of %d photos.', $stripped, count($files)));

        return $stripped === count($files) ? self::SUCCESS : self::FAILURE;
    }
}
