<?php

declare(strict_types=1);

namespace App\Grants;

use App\Media\MediaLibrary;
use App\Models\Grant;
use App\Models\GrantDocument;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;

/**
 * The papers on a grant: proposal, agreement, budget, reports, letters.
 * Private-disk library rows in a locked "Grant files" folder, opened only
 * through a signed link that lasts a day (long enough to read an
 * agreement; short enough that a forwarded link goes stale).
 */
final class GrantDocuments
{
    public const FOLDER_SLUG = 'grant-files';

    public const LINK_HOURS = 24;

    public function __construct(private readonly MediaLibrary $library) {}

    public function attach(Grant $grant, UploadedFile $file, string $kind, string $title, User $by): GrantDocument
    {
        $media = $this->library->add($file, $this->folder(), [], $by, private: true);

        return $grant->documents()->create([
            'media_id' => $media->getKey(),
            'title' => $title,
            'kind' => $kind,
            'uploaded_by' => $by->getKey(),
        ]);
    }

    public function link(GrantDocument $document): string
    {
        return URL::temporarySignedRoute('grants.documents.download', now()->addHours(self::LINK_HOURS), ['document' => $document->ulid]);
    }

    public function folder(): MediaFolder
    {
        $folder = MediaFolder::query()->where('slug', self::FOLDER_SLUG)->whereNull('parent_id')->first();

        if ($folder !== null) {
            return $folder;
        }

        $folder = new MediaFolder(['name' => 'Grant files', 'slug' => self::FOLDER_SLUG, 'description' => 'Grant proposals, agreements and reports. Private; served only through a grant.']);
        $folder->forceFill(['is_locked' => true])->save();

        return $folder;
    }
}
