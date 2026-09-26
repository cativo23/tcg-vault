<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Modules\Collection\Contracts\PhotoMetadataStripper;
use App\Modules\Collection\Exceptions\PhotoMetadataStripException;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Strips an upload's metadata before a component stores it, and refuses it
 * (with an error on the given field) when that fails. Each attempt counts
 * against a per-user limit, so nobody can keep exiftool busy by submitting
 * large photos over and over.
 */
trait StripsUploadedPhotos
{
    public static function photoLimitPerMinute(): int
    {
        return 30;
    }

    protected function stripUploadedPhoto(TemporaryUploadedFile $photo, string $errorKey): bool
    {
        $key = 'photo-strip:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, self::photoLimitPerMinute())) {
            $this->addError($errorKey, 'That’s a lot of photos in a minute. Please wait a moment and try again.');

            return false;
        }

        RateLimiter::hit($key, 60);

        try {
            app(PhotoMetadataStripper::class)->strip((string) $photo->getRealPath());
        } catch (PhotoMetadataStripException $e) {
            report($e);
            $this->addError($errorKey, 'This photo couldn’t be processed. Try a different file.');

            return false;
        }

        return true;
    }
}
