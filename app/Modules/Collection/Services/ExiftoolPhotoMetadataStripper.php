<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use App\Modules\Collection\Contracts\PhotoMetadataStripper;
use App\Modules\Collection\Exceptions\PhotoMetadataStripException;
use finfo;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Strips metadata with exiftool: everything is removed, then only the
 * Orientation tag (so phone photos don't display sideways) and the ICC
 * colour profile are copied back from the original. exiftool rewrites
 * metadata segments only, so the image data is untouched.
 *
 * exiftool never sees the uploaded file or its name. The type is detected
 * from the content here and limited to JPEG, PNG and WebP; the file is
 * copied to a server-named working file whose extension matches that type
 * (exiftool refuses to write when name and content disagree, and picks its
 * parser from both), stripped there, then swapped in with an atomic rename.
 *
 * The command is an argument array run without a shell, on an absolute
 * path, with `-config ''` first so no user config file is loaded, and a
 * short timeout so an oversized file can't hold a worker for long.
 */
final class ExiftoolPhotoMetadataStripper implements PhotoMetadataStripper
{
    private const TIMEOUT_SECONDS = 5;

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly string $binary) {}

    public function strip(string $absolutePath): void
    {
        if (! str_starts_with($absolutePath, '/') || ! is_file($absolutePath)) {
            throw new PhotoMetadataStripException('Not an existing absolute file path.');
        }

        $extension = self::EXTENSIONS[(string) (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath)] ?? null;
        if ($extension === null) {
            throw new PhotoMetadataStripException('Unsupported image type.');
        }

        // Hidden and in the same directory, so the final rename is atomic
        // and a leftover is skipped by photos:strip-metadata.
        $working = dirname($absolutePath).'/.strip-'.bin2hex(random_bytes(16)).'.'.$extension;

        try {
            if (! copy($absolutePath, $working)) {
                throw new PhotoMetadataStripException('Could not create the working copy.');
            }

            $this->runExiftool($working);

            $mode = fileperms($absolutePath);
            if ($mode !== false) {
                chmod($working, $mode & 0777);
            }

            if (! rename($working, $absolutePath)) {
                throw new PhotoMetadataStripException('Could not replace the original with the stripped copy.');
            }
        } finally {
            if (is_file($working)) {
                unlink($working);
            }
        }
    }

    private function runExiftool(string $path): void
    {
        $process = new Process([
            $this->binary,
            '-config', '',
            '-all=',
            '-tagsfromfile', '@',
            '-Orientation',
            '-ICC_Profile',
            '-overwrite_original',
            '-q', '-q',
            $path,
        ]);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ProcessException $e) {
            throw new PhotoMetadataStripException('exiftool did not complete: '.$e::class, previous: $e);
        }

        // stderr names the file, so it stays out of the message.
        if (! $process->isSuccessful()) {
            throw new PhotoMetadataStripException(sprintf('exiftool exited with %d.', (int) $process->getExitCode()));
        }
    }
}
