<?php

use App\Modules\Collection\Services\ExiftoolPhotoMetadataStripper;
use App\Modules\Collection\Services\PhotoMetadataStripFailed;
use Symfony\Component\Process\Process;

/*
 * Runs the real exiftool binary. CI installs it and sets EXIFTOOL_REQUIRED,
 * so a missing binary fails there instead of silently skipping.
 */

const EXIFTOOL = '/usr/bin/exiftool';

beforeEach(function () {
    if (! is_executable(EXIFTOOL)) {
        if (getenv('EXIFTOOL_REQUIRED')) {
            $this->fail('exiftool is required for these tests but is not installed at '.EXIFTOOL);
        }
        $this->markTestSkipped('exiftool is not installed ('.EXIFTOOL.').');
    }

    $this->dir = sys_get_temp_dir().'/strip-test-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function () {
    if (isset($this->dir)) {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }
});

/** Writes a small image carrying GPS, author and XMP data plus a rotation tag. */
function photoWithLocation(string $dir, string $ext): string
{
    $path = "$dir/photo.$ext";
    $image = imagecreatetruecolor(40, 20);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
    match ($ext) {
        'jpg' => imagejpeg($image, $path),
        'png' => imagepng($image, $path),
        'webp' => imagewebp($image, $path),
    };

    (new Process([EXIFTOOL, '-q', '-overwrite_original', '-GPSLatitude=13.69', '-GPSLatitudeRef=N',
        '-GPSLongitude=89.19', '-GPSLongitudeRef=W', '-Orientation#=6', '-Artist=Ash', '-XMP:Creator=Ash', $path]))->mustRun();

    return $path;
}

/** @return array<string, mixed> every tag exiftool can read from the file */
function readTags(string $path): array
{
    $process = (new Process([EXIFTOOL, '-j', '-n', '-G1', '-a', $path]))->mustRun();

    return json_decode($process->getOutput(), true)[0];
}

test('location, author and XMP data are removed while the rotation is kept', function (string $ext) {
    $path = photoWithLocation($this->dir, $ext);
    expect(readTags($path))->toHaveKey('IFD0:Artist');

    (new ExiftoolPhotoMetadataStripper(EXIFTOOL))->strip($path);

    $tags = readTags($path);
    $leftover = array_filter(array_keys($tags), fn (string $k) => preg_match('/^(GPS|XMP|IFD0:Artist|Composite:GPS)/', $k));

    expect($leftover)->toBe([])
        ->and($tags['IFD0:Orientation'])->toBe(6);
})->with(['jpg', 'png', 'webp']);

test('the image itself is not re-encoded', function () {
    $path = photoWithLocation($this->dir, 'png');
    $pixelBefore = imagecolorat(imagecreatefrompng($path), 5, 5);

    (new ExiftoolPhotoMetadataStripper(EXIFTOOL))->strip($path);

    expect(imagecolorat(imagecreatefrompng($path), 5, 5))->toBe($pixelBefore);
});

test('a file that is not really an image is refused', function () {
    $path = $this->dir.'/fake.jpg';
    file_put_contents($path, 'not an image');

    (new ExiftoolPhotoMetadataStripper(EXIFTOOL))->strip($path);
})->throws(PhotoMetadataStripFailed::class);

test('a missing file is refused', function () {
    (new ExiftoolPhotoMetadataStripper(EXIFTOOL))->strip($this->dir.'/missing.jpg');
})->throws(PhotoMetadataStripFailed::class);

test('a path that looks like an option is refused before exiftool runs', function () {
    (new ExiftoolPhotoMetadataStripper(EXIFTOOL))->strip('-all=');
})->throws(PhotoMetadataStripFailed::class);

test('no temporary files are left next to the photo', function () {
    $path = photoWithLocation($this->dir, 'jpg');

    (new ExiftoolPhotoMetadataStripper(EXIFTOOL))->strip($path);

    expect(glob($this->dir.'/*'))->toBe([$path]);
});
