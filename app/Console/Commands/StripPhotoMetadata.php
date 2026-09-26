<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Collection\Contracts\PhotoMetadataStripper;
use App\Modules\Collection\Exceptions\PhotoMetadataStripException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Strips metadata from every photo already on the collection-photos disk.
 * New uploads are stripped on the way in; this cleans the ones stored
 * before that. Safe to run again: an already-stripped file just stays as
 * it is.
 */
final class StripPhotoMetadata extends Command
{
    protected $signature = 'photos:strip-metadata';

    protected $description = 'Remove location and other metadata from every stored collection photo.';

    public function handle(PhotoMetadataStripper $stripper): int
    {
        $disk = Storage::disk('collection-photos');
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
