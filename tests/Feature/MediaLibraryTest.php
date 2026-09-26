<?php

declare(strict_types=1);

use App\Media\Exceptions\MediaInUse;
use App\Media\ImageToolchain;
use App\Media\MediaLibrary;
use App\Media\MediaUsage;
use App\Media\UploadPolicy;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use Database\Seeders\CmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\ConversionCollection;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The media library
|--------------------------------------------------------------------------
|
| Three things this file holds in place.
|
| THE BYTES ARE THE ONLY EVIDENCE. A filename, an extension and a Content-Type
| header are all supplied by whatever did the uploading, and whatever did the
| uploading is not always a browser. Everything here that decides what a file is
| decides it by sniffing.
|
| A FILE IN USE CANNOT BE DELETED. Thirty-two of the thirty-four foreign keys
| pointing at `media` are ON DELETE SET NULL, so deleting an in-use file does
| not fail and does not warn — a donation receipt loses its PDF, a beneficiary
| loses their ID document, a consent record loses the evidence it is evidence
| of, and every one of them still reads as intact.
|
| AN UNSANITISED IMAGE GETS NO DERIVATIVES. Conversions of a photograph that
| still carries GPS data would be three more copies of it, at guessable URLs on
| a public disk.
|
*/

beforeEach(function () {
    Storage::fake('public');
    $this->seed(CmsReferenceSeeder::class);
    MediaUsage::flush();
});

/** A real JPEG on disk, with real bytes — not a fake with a claimed type. */
function jpegUpload(string $name = 'photo.jpg', int $width = 800, int $height = 600): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

// ── What may be uploaded ────────────────────────────────────────────────────

it('accepts a genuine photograph', function () {
    $media = app(MediaLibrary::class)->add(jpegUpload());

    expect($media->exists)->toBeTrue()
        ->and($media->mime_type)->toBe('image/jpeg')
        ->and($media->folder_id)->not->toBeNull();
});

it('refuses a script wearing a photograph\'s name', function () {
    /*
     * THE attack. The name says jpg, the header would say image/jpeg, and the
     * bytes say PHP. Only one of those three is evidence.
     */
    $file = UploadedFile::fake()->createWithContent('photo.jpg', "<?php echo 'pwned';");

    expect(fn () => app(MediaLibrary::class)->add($file))
        ->toThrow(RuntimeException::class);

    expect(Media::count())->toBe(0);
});

it('refuses a real image wearing an executable extension', function () {
    // The other direction, and it matters just as much: the extension is what a
    // misconfigured server dispatches on, whatever the contents are.
    $source = jpegUpload();
    $bytes = (string) file_get_contents($source->getRealPath());

    $file = UploadedFile::fake()->createWithContent('shell.php', $bytes);

    expect(fn () => app(MediaLibrary::class)->add($file))
        ->toThrow(RuntimeException::class);
});

it('refuses SVG outright', function () {
    /*
     * An SVG is an XML document that can contain <script>, served from the same
     * origin as the admin panel — so an uploaded one is stored XSS holding the
     * session of whoever opens it. Sanitising SVG reliably is a game played
     * forever against new parser quirks.
     */
    $svg = UploadedFile::fake()->createWithContent(
        'logo.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    expect(fn () => app(MediaLibrary::class)->add($svg))
        ->toThrow(RuntimeException::class);
});

it('refuses an image too small to put on a page', function () {
    expect(fn () => app(MediaLibrary::class)->add(jpegUpload('tiny.jpg', 40, 40)))
        ->toThrow(RuntimeException::class);
});

it('refuses a file over the size limit', function () {
    config()->set('media.max_bytes.image', 1024);

    expect(fn () => app(MediaLibrary::class)->add(jpegUpload()))
        ->toThrow(RuntimeException::class);
});

// ── Filenames ───────────────────────────────────────────────────────────────

it('collapses a double extension so nothing on disk ends in .php.jpg', function () {
    /*
     * `invoice.php.jpg` is a file some Apache configurations execute, because
     * mod_mime can dispatch on any extension in the chain rather than the last.
     * This project deploys to shared hosting whose configuration is not ours to
     * audit, so the stored name carries exactly one dot.
     */
    $stored = app(UploadPolicy::class)->safeFilename('invoice.php.jpg', 'image/jpeg');

    expect($stored)->toBe('invoice-php.jpg')
        ->and(substr_count($stored, '.'))->toBe(1);
});

it('strips a path out of a filename', function () {
    $stored = app(UploadPolicy::class)->safeFilename('../../../etc/passwd.png', 'image/png');

    expect($stored)->not->toContain('/')
        ->and($stored)->not->toContain('..')
        ->and($stored)->toEndWith('.png');
});

it('takes the extension from the bytes, not from the name', function () {
    // A PNG named .jpg is stored as .png, so what is on disk cannot disagree
    // with what is in it.
    expect(app(UploadPolicy::class)->safeFilename('mislabelled.jpg', 'image/png'))
        ->toBe('mislabelled.png');
});

it('still produces a name when everything in the original strips away', function () {
    $stored = app(UploadPolicy::class)->safeFilename('***.jpg', 'image/jpeg');

    expect($stored)->toEndWith('.jpg')
        ->and(strlen($stored))->toBeGreaterThan(4);
});

it('keeps the readable name for the person who uploaded it', function () {
    // Only the name ON DISK is sanitised. "Ama's graduation" is what somebody
    // will search for, and there is no reason to mangle it.
    $media = app(MediaLibrary::class)->add(jpegUpload("Ama's graduation.jpg"));

    expect($media->name)->toBe("Ama's graduation")
        ->and($media->file_name)->toBe('ama-s-graduation.jpg');
});

// ── Metadata, and what depends on it ────────────────────────────────────────

it('sanitises on the way in, before anything can read the file', function () {
    $media = app(MediaLibrary::class)->add(jpegUpload());

    expect($media->hasBeenSanitised())->toBeTrue();
});

it('records the dimensions so a small image is never upscaled', function () {
    $media = app(MediaLibrary::class)->add(jpegUpload('small.jpg', 400, 300));

    expect($media->width())->toBe(400)
        ->and($media->height())->toBe(300);
});

it('gives an unsanitised image no conversions at all', function () {
    /*
     * The safeguarding rule. `isPublishable()` already refuses the original,
     * but conversions live at derivable paths on a public disk — generating
     * them would put three more copies of a photograph still carrying a child's
     * home coordinates where a URL can reach them.
     */
    $folder = MediaFolder::firstOrFail();
    $media = Media::factory()->create([
        'model_type' => $folder->getMorphClass(),
        'model_id' => $folder->getKey(),
        'metadata_stripped_at' => null,
    ]);

    $conversions = ConversionCollection::createForMedia($media);

    expect($conversions)->toHaveCount(0);
});

it('gives a sanitised image its conversions', function () {
    $folder = MediaFolder::firstOrFail();
    $media = Media::factory()->sanitised()->create([
        'model_type' => $folder->getMorphClass(),
        'model_id' => $folder->getKey(),
    ]);

    $conversions = ConversionCollection::createForMedia($media);

    expect($conversions->count())->toBeGreaterThan(0);
});

it('does not convert an animated GIF into a picture of its first frame', function () {
    $folder = MediaFolder::firstOrFail();
    $media = Media::factory()->sanitised()->create([
        'model_type' => $folder->getMorphClass(),
        'model_id' => $folder->getKey(),
        'mime_type' => 'image/gif',
    ]);

    expect(ConversionCollection::createForMedia($media))
        ->toHaveCount(0);
});

it('does not upscale a small original into a larger, blurrier hero', function () {
    $folder = MediaFolder::firstOrFail();
    $media = Media::factory()->sanitised()->create([
        'model_type' => $folder->getMorphClass(),
        'model_id' => $folder->getKey(),
        'custom_properties' => ['width' => 500, 'height' => 400],
    ]);

    $names = ConversionCollection::createForMedia($media)
        ->map(fn ($conversion) => $conversion->getName())
        ->all();

    expect($names)->toContain('thumb')
        ->and($names)->not->toContain('hero');
});

// ── Use, and the refusal ────────────────────────────────────────────────────

it('finds every column that points at media, without being told', function () {
    /*
     * Thirty-four foreign keys under a dozen different column names —
     * `media_id`, `image_id`, `photo_id`, `logo_id`, `cover_id`,
     * `og_image_id`, `signature_id` and more. A hand-written list would be out
     * of date within a phase, and the column nobody remembered is exactly the
     * one the delete guard has to catch.
     */
    $columns = app(MediaUsage::class)->columns();

    expect(count($columns))->toBeGreaterThan(25);

    $tables = array_column($columns, 'table');

    expect($tables)->toContain('donation_receipts')
        ->and($tables)->toContain('consents')
        ->and($tables)->toContain('beneficiaries')
        ->and($tables)->toContain('seo_meta');
});

it('refuses to delete a file that something is using', function () {
    $media = app(MediaLibrary::class)->add(jpegUpload());

    DB::table('partners')->insert([
        'name' => 'A partner',
        'slug' => 'a-partner',
        'logo_id' => $media->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    MediaUsage::flush();

    expect(fn () => $media->delete())->toThrow(MediaInUse::class);
    expect(Media::whereKey($media->getKey())->exists())->toBeTrue();
});

it('allows deleting a file nothing is using', function () {
    $media = app(MediaLibrary::class)->add(jpegUpload());

    $media->delete();

    expect(Media::whereKey($media->getKey())->exists())->toBeFalse();
});

it('treats a database it cannot question as a refusal, not as permission', function () {
    /*
     * The direction of the guess matters. A wrong "not in use" silently
     * detaches a receipt from its PDF; a wrong "in use" tells somebody to try
     * again. Only one of those is recoverable.
     */
    $media = app(MediaLibrary::class)->add(jpegUpload());

    config()->set('media.usage_labels', []);
    MediaUsage::flush();

    $usage = Mockery::mock(MediaUsage::class)->makePartial();
    $usage->shouldReceive('columns')->andReturn([]);

    expect($usage->isInUse($media))->toBeTrue();
});

it('says where a file is used, in a sentence', function () {
    $media = app(MediaLibrary::class)->add(jpegUpload());

    DB::table('partners')->insert([
        'name' => 'Ridge Hospital',
        'slug' => 'ridge-hospital',
        'logo_id' => $media->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    MediaUsage::flush();

    expect(app(MediaUsage::class)->explain($media))
        ->toContain('1 place')
        ->toContain('Ridge Hospital');
});

it('withholds who a confidential use belongs to', function () {
    /*
     * "This file is used by a consent record" is safe to show anybody who can
     * open the media library. "This file is the evidence for Ama Mensah's
     * consent" names a beneficiary to whoever happens to be browsing photos.
     */
    $media = app(MediaLibrary::class)->add(jpegUpload());

    DB::table('consents')->insert([
        'ulid' => (string) Str::ulid(),
        'consentable_type' => 'beneficiary',
        'consentable_id' => 1,
        'consent_type' => 'photo',
        'scope' => 'website',
        'granted_by_name' => 'Ama Mensah',
        'granted_by_relationship' => 'self',
        'granted_at' => now(),
        'evidence_media_id' => $media->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    MediaUsage::flush();

    $withheld = app(MediaUsage::class)->explain($media, maySeeConfidential: false);
    $shown = app(MediaUsage::class)->explain($media, maySeeConfidential: true);

    expect($withheld)->not->toContain('Ama Mensah')
        ->and($withheld)->toContain('confidential')
        ->and($shown)->toContain('Ama Mensah');
});

// ── Replacing ───────────────────────────────────────────────────────────────

it('keeps the same id when a file is replaced, so every use follows', function () {
    /*
     * Delete-and-re-upload leaves thirty references pointing at the old row —
     * and because most of those keys are SET NULL, some of them pointing at
     * nothing. Replacing is what somebody asking to replace a file means.
     */
    $media = app(MediaLibrary::class)->add(jpegUpload('old-logo.jpg'));
    $id = $media->getKey();

    $replaced = app(MediaLibrary::class)->replace($media, jpegUpload('new-logo.jpg'));

    expect($replaced->getKey())->toBe($id)
        ->and($replaced->file_name)->toBe('new-logo.jpg');
});

it('re-sanitises the replacement rather than inheriting a clean bill of health', function () {
    // Leaving the old timestamp would mean a fresh, unsanitised photograph
    // passing isPublishable() on the strength of a check performed on a
    // different image.
    $media = app(MediaLibrary::class)->add(jpegUpload('old.jpg'));

    $replaced = app(MediaLibrary::class)->replace($media, jpegUpload('new.jpg'));

    expect($replaced->hasBeenSanitised())->toBeTrue()
        ->and($replaced->metadata_stripped_at)->not->toBeNull();
});

it('refuses to replace a photograph with a document', function () {
    // Thirty places expect an image. Swapping a PDF in would leave all of them
    // rendering an <img> at a document.
    $media = app(MediaLibrary::class)->add(jpegUpload());
    $pdf = UploadedFile::fake()->createWithContent('policy.pdf', "%PDF-1.4\n%test\n");

    expect(fn () => app(MediaLibrary::class)->replace($media, $pdf))
        ->toThrow(RuntimeException::class);
});

it('records a replacement in the audit trail', function () {
    /*
     * Nothing else does. The row keeps its id, so every reference to it is
     * unchanged and none of those records is touched — yet what a consent's
     * evidence actually shows is now a different image.
     */
    $media = app(MediaLibrary::class)->add(jpegUpload('old.jpg'));
    $actor = User::factory()->staff()->create();

    app(MediaLibrary::class)->replace($media, jpegUpload('new.jpg'), $actor);

    expect(DB::table('audit_logs')->where('event', 'media.replaced')->exists())->toBeTrue();
});

// ── Publication gate ────────────────────────────────────────────────────────

it('will not publish an image with no alt text', function () {
    $media = app(MediaLibrary::class)->add(jpegUpload());

    expect($media->isPublishable())->toBeFalse()
        ->and($media->publicationRejectionReason())->toContain('alt text');
});

it('publishes once it has alt text and has been sanitised', function () {
    $media = app(MediaLibrary::class)->add(jpegUpload(), attributes: [
        'alt_text' => 'Children reading under a tree.',
    ]);

    expect($media->isPublishable())->toBeTrue()
        ->and($media->publicationRejectionReason())->toBeNull();
});

// ── The toolchain ───────────────────────────────────────────────────────────

it('knows what this server can actually do', function () {
    $toolchain = app(ImageToolchain::class);

    expect($toolchain->canResize())->toBeTrue()
        ->and($toolchain->preferredDriver())->toBeIn(['gd', 'imagick']);
});

it('never asks for a format the server cannot encode', function () {
    /*
     * "Detect and degrade gracefully". A config saying avif => true on a host
     * with no AVIF encoder must not produce a queue full of failing jobs, so
     * what runs is the intersection of what is wanted and what is possible.
     */
    config()->set('media.formats.avif', true);

    $toolchain = app(ImageToolchain::class);
    $formats = $toolchain->outputFormats();

    if (! $toolchain->canEncodeAvif()) {
        expect($formats)->not->toContain('avif');
    }

    expect(true)->toBeTrue();
});

it('reports the PHP limits that are the real ceiling', function () {
    // config/media.php's limit is meaningless if upload_max_filesize is 2M,
    // which on shared hosting it frequently is.
    $report = app(ImageToolchain::class)->report();

    expect($report['php'])->toHaveKeys([
        'upload_max_filesize', 'post_max_size', 'memory_limit', 'fileinfo',
    ]);
});

it('runs the doctor without a database full of media', function () {
    $this->artisan('scghf:media-doctor')->assertExitCode(
        app(ImageToolchain::class)->warnings() === [] ? 0 : 1
    );
});

// ── Inode accounting ────────────────────────────────────────────────────────

it('counts what a file costs in inodes, not in bytes', function () {
    // Shared hosting counts files. A library is what exhausts that count, and
    // it does it one upload at a time.
    $folder = MediaFolder::firstOrFail();
    $media = Media::factory()->sanitised()->create([
        'model_type' => $folder->getMorphClass(),
        'model_id' => $folder->getKey(),
        'generated_conversions' => ['thumb' => true, 'card' => true, 'hero' => true],
    ]);

    expect($media->inodeCost())->toBe(4);
});
