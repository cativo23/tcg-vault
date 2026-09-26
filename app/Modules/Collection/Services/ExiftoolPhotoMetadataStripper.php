<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Strips metadata with exiftool: everything is removed, then only the
 * Orientation tag (so phone photos don't display sideways) and the ICC
 * colour profile are copied back from the original. exiftool rewrites
 * metadata segments only, so the image data is untouched.
 *
 * The command is built as an argument array and run without a shell, so
 * nothing in the path is ever interpreted. The path must be absolute, which
 * also means it can never be read as an exiftool option, and `-config ''`
 * (which must come first) stops exiftool loading a user config file.
 */
final class ExiftoolPhotoMetadataStripper implements PhotoMetadataStripper
{
    private const TIMEOUT_SECONDS = 20;

    public function __construct(private readonly string $binary) {}

    public function strip(string $absolutePath): void
    {
        if (! str_starts_with($absolutePath, '/') || ! is_file($absolutePath)) {
            throw new PhotoMetadataStripFailed('Not an existing absolute file path.');
        }

        $process = new Process([
            $this->binary,
            '-config', '',
            '-all=',
            '-tagsfromfile', '@',
            '-Orientation',
            '-ICC_Profile',
            '-overwrite_original',
            '-q', '-q',
            $absolutePath,
        ]);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new PhotoMetadataStripFailed('exiftool timed out.', previous: $e);
        }

        if (! $process->isSuccessful()) {
            throw new PhotoMetadataStripFailed(sprintf(
                'exiftool exited with %d: %s',
                (int) $process->getExitCode(),
                trim($process->getErrorOutput()),
            ));
        }
    }
}
