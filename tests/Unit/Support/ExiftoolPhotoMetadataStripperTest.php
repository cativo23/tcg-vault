<?php

use App\Modules\Collection\Exceptions\PhotoMetadataStripException;
use App\Modules\Collection\Services\ExiftoolPhotoMetadataStripper;
use Symfony\Component\Process\Process;

/*
 * Runs the real exiftool binary. CI installs it and sets EXIFTOOL_REQUIRED,
 * so a missing binary fails there instead of silently skipping.
 */

function exiftoolBinary(): string
{
    return (string) config('tcgvault.exiftool_path');
}

beforeEach(function () {
    if (! is_executable(exiftoolBinary())) {
        if (getenv('EXIFTOOL_REQUIRED')) {
            $this->fail('exiftool is required for these tests but is not installed at '.exiftoolBinary());
        }
        $this->markTestSkipped('exiftool is not installed ('.exiftoolBinary().').');
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
function exiftoolPhotoWithLocation(string $dir, string $ext): string
{
    $path = "$dir/photo.$ext";
    $image = imagecreatetruecolor(40, 20);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
    match ($ext) {
        'jpg' => imagejpeg($image, $path),
        'png' => imagepng($image, $path),
        'webp' => imagewebp($image, $path),
    };

    (new Process([exiftoolBinary(), '-q', '-overwrite_original', '-GPSLatitude=13.69', '-GPSLatitudeRef=N',
        '-GPSLongitude=89.19', '-GPSLongitudeRef=W', '-Orientation#=6', '-Artist=Ash', '-XMP:Creator=Ash', $path]))->mustRun();

    return $path;
}

/** @return array<string, mixed> every tag exiftool can read from the file */
function exiftoolReadTags(string $path): array
{
    $process = (new Process([exiftoolBinary(), '-j', '-n', '-G1', '-a', $path]))->mustRun();

    return json_decode($process->getOutput(), true)[0];
}

test('location, author and XMP data are removed while the rotation is kept', function (string $ext) {
    $path = exiftoolPhotoWithLocation($this->dir, $ext);
    expect(exiftoolReadTags($path))->toHaveKey('IFD0:Artist');

    (new ExiftoolPhotoMetadataStripper(exiftoolBinary()))->strip($path);

    $tags = exiftoolReadTags($path);
    $leftover = array_filter(array_keys($tags), fn (string $k) => preg_match('/^(GPS|XMP|IFD0:Artist|Composite:GPS)/', $k));

    expect($leftover)->toBe([])
        ->and($tags['IFD0:Orientation'])->toBe(6);
})->with(['jpg', 'png', 'webp']);

test('the image itself is not re-encoded', function () {
    $path = exiftoolPhotoWithLocation($this->dir, 'png');
    $pixelBefore = imagecolorat(imagecreatefrompng($path), 5, 5);

    (new ExiftoolPhotoMetadataStripper(exiftoolBinary()))->strip($path);

    expect(imagecolorat(imagecreatefrompng($path), 5, 5))->toBe($pixelBefore);
});

test('a file that is not really an image is refused', function () {
    $path = $this->dir.'/fake.jpg';
    file_put_contents($path, 'not an image');

    (new ExiftoolPhotoMetadataStripper(exiftoolBinary()))->strip($path);
})->throws(PhotoMetadataStripException::class);

test('a missing file is refused', function () {
    (new ExiftoolPhotoMetadataStripper(exiftoolBinary()))->strip($this->dir.'/missing.jpg');
})->throws(PhotoMetadataStripException::class);

test('a path that looks like an option is refused before exiftool runs', function () {
    (new ExiftoolPhotoMetadataStripper(exiftoolBinary()))->strip('-all=');
})->throws(PhotoMetadataStripException::class);

test('no temporary files are left next to the photo', function () {
    $path = exiftoolPhotoWithLocation($this->dir, 'jpg');

    (new ExiftoolPhotoMetadataStripper(exiftoolBinary()))->strip($path);

    expect(glob($this->dir.'/*'))->toBe([$path]);
});

test('a photo whose extension does not match its content is still stripped', function () {
    // WebP saved as .jpg is common; exiftool refuses to write when the name
    // and the content disagree, so the stripper must not pass the name on.
    $path = exiftoolPhotoWithLocation($this->dir, 'webp');
    $misnamed = $this->dir.'/card.jpg';
    rename($path, $misnamed);

    (new ExiftoolPhotoMetadataStripper(exiftoolBinary()))->strip($misnamed);

    $tags = exiftoolReadTags($misnamed);
    expect(array_filter(array_keys($tags), fn (string $k) => str_starts_with($k, 'GPS')))->toBe([])
        ->and($tags['File:FileType'])->toContain('WEBP')
        ->and(glob($this->dir.'/*'))->toBe([$misnamed]);
});

test('a file of another type is refused without running exiftool on it', function () {
    $path = $this->dir.'/photo.gif';
    imagegif(imagecreatetruecolor(4, 4), $path);

    (new ExiftoolPhotoMetadataStripper(exiftoolBinary()))->strip($path);
})->throws(PhotoMetadataStripException::class, 'Unsupported image type');

test('a failure does not put exiftool output or the file name in the error', function () {
    $path = $this->dir.'/c2VjcmV0LW5hbWU=.jpg';
    file_put_contents($path, "\xFF\xD8\xFF\xE0garbage");

    try {
        (new ExiftoolPhotoMetadataStripper(exiftoolBinary()))->strip($path);
        $this->fail('Expected the strip to fail.');
    } catch (PhotoMetadataStripException $e) {
        expect($e->getMessage())->not->toContain('c2VjcmV0LW5hbWU')->not->toContain($this->dir);
    }
});

test('the colour profile is kept', function () {
    // sRGB-v2-micro.icc: Compact ICC Profiles by saucecontrol, CC0-1.0.
    $path = exiftoolPhotoWithLocation($this->dir, 'jpg');
    (new Process([exiftoolBinary(), '-q', '-overwrite_original', '-icc_profile<='.base_path('tests/Fixtures/sRGB-v2-micro.icc'), $path]))->mustRun();
    expect(exiftoolReadTags($path))->toHaveKey('ICC_Profile:ProfileDescription');

    (new ExiftoolPhotoMetadataStripper(exiftoolBinary()))->strip($path);

    expect(exiftoolReadTags($path))->toHaveKey('ICC_Profile:ProfileDescription');
});
