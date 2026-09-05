<?php

declare(strict_types=1);

use App\Filament\Resources\Media\MediaResource;
use App\Filament\Resources\Media\Pages\EditMedia;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Media\MediaLibrary;
use App\Media\MediaUsage;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use App\Policies\BasePolicy;
use Database\Seeders\CmsReferenceSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The media library in the admin panel
|--------------------------------------------------------------------------
|
| The screen is thin; the things worth testing about it are the ones where a
| convenience would quietly undo a guarantee made underneath it.
|
| UPLOADS GO THROUGH THE SERVICE. Filament's FileUpload will happily store a
| file itself, which would put an unsniffed, unsanitised upload on a disk
| before anything looked at it. `storeFiles(false)` is what stops that, and the
| test is that an uploaded file comes out sanitised and filed.
|
| DELETING AN IN-USE FILE FAILS VISIBLY. The guard is in the model, so it holds
| whatever the screen does — but a screen that turned the refusal into a 500
| would teach people the library is broken rather than careful.
|
| THE PERMISSION ACTUALLY GRANTS SOMETHING. `media.upload` was seeded, held by
| three roles, and mapped to nothing by MediaPolicy — so the panel would have
| looked correctly permissioned and refused everybody at the point of use.
|
*/

beforeEach(function () {
    Storage::fake('public');
    $this->seed(SettingsSeeder::class);
    $this->seed(CmsReferenceSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);

    BasePolicy::forgetKnownPermissions();
    MediaUsage::flush();
});

/** A member of staff holding the media permissions. */
function mediaEditor(): User
{
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->givePermissionTo(['media.view', 'media.upload', 'media.delete']);

    return $user;
}

function jpeg(string $name = 'photo.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 800, 600);
}

// ── The permission that granted nothing ─────────────────────────────────────

it('lets somebody with media.upload actually upload and describe', function () {
    /*
     * The bug this file was written for. `BasePolicy::create()` tries
     * `media.create`, then `media.update`, then `media.manage` — none of which
     * exist. The seeded permission is `media.upload`, so every one of those
     * checks answered no, and the library was unusable by everybody while
     * looking correctly permissioned in the seeder.
     */
    $user = mediaEditor();

    expect($user->can('create', Media::class))->toBeTrue();

    $media = app(MediaLibrary::class)->add(jpeg());

    expect($user->can('update', $media))->toBeTrue();
});

it('still refuses somebody holding only media.view', function () {
    $user = User::factory()->staff()->create();
    $user->givePermissionTo('media.view');

    $media = app(MediaLibrary::class)->add(jpeg());

    expect($user->can('create', Media::class))->toBeFalse()
        ->and($user->can('update', $media))->toBeFalse()
        ->and($user->can('delete', $media))->toBeFalse()
        ->and($user->can('viewAny', Media::class))->toBeTrue();
});

// ── The screen ──────────────────────────────────────────────────────────────

it('opens the library', function () {
    $this->actingAs(mediaEditor());

    Livewire::test(ListMedia::class)->assertOk();
});

it('lists what is there', function () {
    $this->actingAs(mediaEditor());

    $media = app(MediaLibrary::class)->add(jpeg('reading-club.jpg'));

    Livewire::test(ListMedia::class)->assertCanSeeTableRecords([$media]);
});

it('has no create page, because you do not create a file', function () {
    /*
     * A create form would let somebody produce a `media` row pointing at
     * nothing on disk, bypassing the only door in.
     */
    expect(MediaResource::getPages())->not->toHaveKey('create');
});

// ── Uploading ───────────────────────────────────────────────────────────────

it('sanitises and files an upload made through the screen', function () {
    $this->actingAs(mediaEditor());

    $folder = MediaFolder::firstOrFail();

    Livewire::test(ListMedia::class)
        ->callAction('upload', data: [
            'files' => [jpeg('project-visit.jpg')],
            'folder_id' => $folder->getKey(),
        ])
        ->assertHasNoActionErrors();

    $media = Media::firstOrFail();

    expect($media->hasBeenSanitised())->toBeTrue()
        ->and($media->folder_id)->toBe($folder->getKey())
        ->and($media->file_name)->toBe('project-visit.jpg');
});

it('keeps the rest of a batch when one file is refused', function () {
    /*
     * Somebody adding thirty photographs from a project visit should not lose
     * twenty-nine of them because one was a screenshot 90 pixels wide.
     */
    $this->actingAs(mediaEditor());

    Livewire::test(ListMedia::class)
        ->callAction('upload', data: [
            'files' => [jpeg('good.jpg'), UploadedFile::fake()->image('tiny.jpg', 40, 40)],
            'folder_id' => MediaFolder::firstOrFail()->getKey(),
        ]);

    expect(Media::count())->toBe(1)
        ->and(Media::first()->file_name)->toBe('good.jpg');
});

// ── Describing ──────────────────────────────────────────────────────────────

it('saves alt text, which is what unblocks the file', function () {
    $this->actingAs(mediaEditor());

    $media = app(MediaLibrary::class)->add(jpeg());

    expect($media->isPublishable())->toBeFalse();

    Livewire::test(EditMedia::class, ['record' => $media->getKey()])
        ->fillForm([
            'alt_text' => 'Children reading under a tree.',
            'folder_id' => $media->folder_id,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($media->refresh()->isPublishable())->toBeTrue();
});

it('will not save an image with neither alt text nor a decorative flag', function () {
    // A form that allowed it would produce files nobody can use, and the person
    // would find out later, on a different screen, with no explanation.
    $this->actingAs(mediaEditor());

    $media = app(MediaLibrary::class)->add(jpeg());

    Livewire::test(EditMedia::class, ['record' => $media->getKey()])
        ->fillForm(['alt_text' => null, 'decorative' => false])
        ->call('save')
        ->assertHasFormErrors(['alt_text']);
});

it('accepts a decorative image with no alt text', function () {
    $this->actingAs(mediaEditor());

    $media = app(MediaLibrary::class)->add(jpeg());

    Livewire::test(EditMedia::class, ['record' => $media->getKey()])
        ->fillForm([
            'alt_text' => null,
            'decorative' => true,
            'folder_id' => $media->folder_id,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($media->refresh()->isDecorative())->toBeTrue()
        ->and($media->isPublishable())->toBeTrue();
});

// ── Deleting ────────────────────────────────────────────────────────────────

it('refuses to delete a file in use, without a 500', function () {
    /*
     * The guard is in the model so it holds whatever the screen does. What this
     * checks is that the screen turns the refusal into an explanation — a red
     * exception page would teach people the library is broken rather than
     * careful, and the way people respond to that is by working around it.
     */
    $this->actingAs(mediaEditor());

    $media = app(MediaLibrary::class)->add(jpeg());

    DB::table('partners')->insert([
        'name' => 'Ridge Hospital',
        'slug' => 'ridge-hospital',
        'logo_id' => $media->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    MediaUsage::flush();

    Livewire::test(ListMedia::class)
        ->callTableAction('delete', $media)
        ->assertHasNoTableActionErrors();

    expect(Media::whereKey($media->getKey())->exists())->toBeTrue();
});

it('deletes a file nothing is using', function () {
    $this->actingAs(mediaEditor());

    $media = app(MediaLibrary::class)->add(jpeg());

    Livewire::test(ListMedia::class)->callTableAction('delete', $media);

    expect(Media::whereKey($media->getKey())->exists())->toBeFalse();
});

// ── Replacing ───────────────────────────────────────────────────────────────

it('replaces the file behind a row from the screen', function () {
    $this->actingAs(mediaEditor());

    $media = app(MediaLibrary::class)->add(jpeg('old-logo.jpg'));
    $id = $media->getKey();

    Livewire::test(ListMedia::class)
        ->callTableAction('replace', $media, data: ['file' => jpeg('new-logo.jpg')])
        ->assertHasNoTableActionErrors();

    $media->refresh();

    expect($media->getKey())->toBe($id)
        ->and($media->file_name)->toBe('new-logo.jpg')
        ->and($media->hasBeenSanitised())->toBeTrue();
});

// ── The badge ───────────────────────────────────────────────────────────────

it('counts the files that cannot be published', function () {
    /*
     * The badge is the only part of this screen somebody sees without opening
     * it, so it holds the thing that needs a person rather than a total.
     */
    app(MediaLibrary::class)->add(jpeg('needs-alt.jpg'));
    app(MediaLibrary::class)->add(jpeg('ready.jpg'), attributes: ['alt_text' => 'Described.']);

    expect(MediaResource::getNavigationBadge())->toBe('1');
});

it('shows no badge when there is nothing to do', function () {
    // A permanent badge is one people stop reading.
    app(MediaLibrary::class)->add(jpeg('ready.jpg'), attributes: ['alt_text' => 'Described.']);

    expect(MediaResource::getNavigationBadge())->toBeNull();
});

it('does not count a decorative image as blocked', function () {
    $media = app(MediaLibrary::class)->add(jpeg('border.jpg'));
    $media->setCustomProperty('decorative', true);
    $media->save();

    expect(MediaResource::getNavigationBadge())->toBeNull();
});
