<?php

declare(strict_types=1);

use App\Models\Media;
use App\Support\ImageSanitiser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Camera metadata on uploaded images
|--------------------------------------------------------------------------
|
| The failure being prevented, concretely:
|
| A volunteer photographs a beneficiary outside their home, on a phone with
| location services on. The JPEG carries the coordinates of that home to
| within a few metres. It is uploaded, published, and anybody who downloads it
| can read a vulnerable child's address out of the file properties.
|
| Nothing about that is visible in an admin panel. The image looks like an
| image. It is the most serious privacy failure this application could have,
| and it happens by default unless something removes the metadata.
|
| CLAUDE.md lists it among the things commonly forgotten. It was.
|
*/

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/scghf-media-'.bin2hex(random_bytes(4));
    mkdir($this->workspace, 0777, true);
});

afterEach(function () {
    foreach (glob($this->workspace.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->workspace);
});

/**
 * A real JPEG carrying real EXIF, including GPS.
 *
 * Built by hand rather than mocked. The whole question is whether the bytes on
 * disk still contain coordinates afterwards, and a mock cannot answer that.
 */
function jpegWithGps(string $path): string
{
    $image = imagecreatetruecolor(48, 48);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
    imagejpeg($image, $path, 92);
    imagedestroy($image);

    $jpeg = (string) file_get_contents($path);

    /*
     * A minimal but genuine APP1/Exif segment: little-endian TIFF header, one
     * IFD0 entry pointing at a GPS IFD, and a GPS IFD carrying a latitude
     * reference. Enough that exif_read_data() reports a GPS section, which is
     * exactly the condition being guarded against.
     */
    $tiff = "II\x2a\x00\x08\x00\x00\x00"                 // little-endian, IFD0 at offset 8
        ."\x01\x00"                                       // one IFD0 entry
        ."\x25\x88\x04\x00\x01\x00\x00\x00\x1a\x00\x00\x00" // GPSInfoIFDPointer -> 0x1a
        ."\x00\x00\x00\x00"                               // no next IFD
        ."\x01\x00"                                       // one GPS entry
        ."\x01\x00\x02\x00\x02\x00\x00\x00N\x00\x00\x00"  // GPSLatitudeRef = "N"
        ."\x00\x00\x00\x00";                              // no next IFD

    $exif = "Exif\x00\x00".$tiff;
    $app1 = "\xff\xe1".pack('n', strlen($exif) + 2).$exif;

    // Straight after SOI, which is where a reader expects APP1.
    file_put_contents($path, "\xff\xd8".$app1.substr($jpeg, 2));

    return $path;
}

// ── The sanitiser ───────────────────────────────────────────────────────────

it('sees the coordinates before removing them', function () {
    $path = jpegWithGps($this->workspace.'/beneficiary.jpg');

    expect(app(ImageSanitiser::class)->hasMetadata($path))->toBeTrue();
});

it('removes location data from a photograph', function () {
    // The test the whole feature exists for.
    $path = jpegWithGps($this->workspace.'/beneficiary.jpg');

    $result = app(ImageSanitiser::class)->sanitise($path);

    expect($result['stripped'])->toBeTrue()
        ->and($result['had_gps'])->toBeTrue()
        ->and(app(ImageSanitiser::class)->hasMetadata($path))->toBeFalse();
});

it('leaves the image usable afterwards', function () {
    // Stripping that produced a corrupt file would be worse than not stripping,
    // because somebody would turn it off.
    $path = jpegWithGps($this->workspace.'/beneficiary.jpg');

    app(ImageSanitiser::class)->sanitise($path);

    $size = getimagesize($path);

    expect($size)->not->toBeFalse()
        ->and($size[0])->toBe(48)
        ->and($size[1])->toBe(48);
});

it('reports which keys it removed, and never their values', function () {
    // An audit trail that recorded the coordinates it removed, next to the
    // photograph, would defeat the entire exercise.
    $path = jpegWithGps($this->workspace.'/beneficiary.jpg');

    $result = app(ImageSanitiser::class)->sanitise($path);

    expect($result['keys'])->not->toBeEmpty()
        ->and(implode(' ', $result['keys']))->toContain('GPS');

    foreach ($result['keys'] as $key) {
        // Key names only. A value would look nothing like `SECTION.TagName`.
        expect($key)->toMatch('/^[A-Za-z0-9_]+\.[A-Za-z0-9_]+$/');
    }
});

it('leaves a clean image byte-for-byte alone', function () {
    // Re-encoding an image with nothing to remove costs quality for no benefit,
    // and most already-processed uploads land here.
    $path = $this->workspace.'/clean.jpg';
    $image = imagecreatetruecolor(32, 32);
    imagejpeg($image, $path, 90);
    imagedestroy($image);

    $before = md5_file($path);
    $result = app(ImageSanitiser::class)->sanitise($path);

    expect($result['stripped'])->toBeTrue()
        ->and($result['keys'])->toBeEmpty()
        ->and(md5_file($path))->toBe($before);
});

it('preserves transparency through the round trip', function () {
    // Without imagesavealpha a transparent PNG comes back with a black
    // background, which an editor would notice and blame on the CMS.
    $path = $this->workspace.'/logo.png';
    $image = imagecreatetruecolor(16, 16);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagepng($image, $path);
    imagedestroy($image);

    app(ImageSanitiser::class)->sanitise($path);

    $reloaded = imagecreatefrompng($path);
    $colour = imagecolorsforindex($reloaded, imagecolorat($reloaded, 8, 8));
    imagedestroy($reloaded);

    expect($colour['alpha'])->toBe(127);
});

it('does not claim to have sanitised something it cannot rewrite', function () {
    // A PDF's metadata is a separate problem. Saying so beats pretending.
    $path = $this->workspace.'/policy.pdf';
    file_put_contents($path, "%PDF-1.4\n%stub\n");

    $result = app(ImageSanitiser::class)->sanitise($path);

    expect($result['stripped'])->toBeFalse()
        ->and($result['error'])->toBeNull();
});

it('records a failure rather than reporting a pass', function () {
    // The dangerous outcome is not "stripping failed" — it is "stripping failed
    // and the image was published anyway because nothing checked".
    $result = app(ImageSanitiser::class)->sanitise($this->workspace.'/does-not-exist.jpg');

    expect($result['stripped'])->toBeFalse()
        ->and($result['error'])->not->toBeNull();
});

it('is safe to run twice', function () {
    $path = jpegWithGps($this->workspace.'/beneficiary.jpg');

    app(ImageSanitiser::class)->sanitise($path);
    $afterFirst = md5_file($path);

    $second = app(ImageSanitiser::class)->sanitise($path);

    expect($second['stripped'])->toBeTrue()
        ->and($second['keys'])->toBeEmpty()
        // Nothing left to remove, so the second run does not re-encode.
        ->and(md5_file($path))->toBe($afterFirst);
});

// ── The publication gate ────────────────────────────────────────────────────

it('refuses to publish an image nobody has checked', function () {
    $media = new Media([
        'mime_type' => 'image/jpeg',
        'alt_text' => 'Children at the Life Spring reading club.',
    ]);

    expect($media->hasBeenSanitised())->toBeFalse()
        ->and($media->isPublishable())->toBeFalse()
        ->and($media->publicationRejectionReason())->toContain('somebody\'s home');
});

it('refuses to publish an image the sanitiser could not clean', function () {
    $media = new Media([
        'mime_type' => 'image/jpeg',
        'alt_text' => 'A photograph.',
    ]);
    $media->metadata_stripped_at = now();
    $media->sanitisation_error = 'The file could not be decoded as an image.';

    expect($media->isPublishable())->toBeFalse()
        ->and($media->publicationRejectionReason())->toContain('could not have its metadata removed');
});

it('still insists on alt text once the image is clean', function () {
    // Both gates, not either. WCAG 2.2 AA is a stated requirement.
    $media = new Media(['mime_type' => 'image/jpeg']);
    $media->metadata_stripped_at = now();

    expect($media->isPublishable())->toBeFalse()
        ->and($media->publicationRejectionReason())->toContain('no alt text');
});

it('publishes an image that is clean and described', function () {
    $media = new Media([
        'mime_type' => 'image/jpeg',
        'alt_text' => 'Children at the Life Spring reading club.',
    ]);
    $media->metadata_stripped_at = now();

    expect($media->isPublishable())->toBeTrue()
        ->and($media->publicationRejectionReason())->toBeNull();
});

it('treats a decorative image as described', function () {
    $media = new Media(['mime_type' => 'image/jpeg']);
    $media->metadata_stripped_at = now();
    $media->setCustomProperty('decorative', true);

    expect($media->isPublishable())->toBeTrue()
        ->and($media->altText())->toBe('');
});
