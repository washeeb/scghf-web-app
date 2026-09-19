<?php

declare(strict_types=1);

namespace App\Beneficiaries;

use App\Media\MediaLibrary;
use App\Models\Beneficiary;
use App\Models\BeneficiaryDocument;
use App\Models\BeneficiaryNote;
use App\Models\MediaFolder;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;

/**
 * The files on a case: a scanned Ghana Card, a medical report, a school
 * bill, a signed consent form.
 *
 * ── Where they live ─────────────────────────────────────────────────────────
 *
 * On the `downloads` disk, which nothing serves — the same private store
 * the shop's paid files use — as library rows in a locked "Case files"
 * folder, so the inode count and the usage report know they exist and the
 * media browser never offers them as a picture for a page. The sanitiser
 * still runs (a scan is an image; its GPS is stripped).
 *
 * ── How they leave ──────────────────────────────────────────────────────────
 *
 * Only through `BeneficiaryDocumentController`, on a URL signed for five
 * minutes, after the policy's `download` check and an audit row. A link
 * pasted into WhatsApp is dead before it is read.
 */
final class CaseDocuments
{
    public const FOLDER_SLUG = 'case-files';

    public const LINK_MINUTES = 5;

    public function __construct(private readonly MediaLibrary $library) {}

    public function attach(Beneficiary $case, UploadedFile $file, string $type, string $title, ?string $description, User $by): BeneficiaryDocument
    {
        $media = $this->library->add($file, $this->folder(), [], $by, private: true);

        $document = $case->documents()->create([
            'media_id' => $media->getKey(),
            'title' => $title,
            'document_type' => $type,
            'description' => $description,
            'uploaded_by' => $by->getKey(),
        ]);

        $case->note(__('Document added: :title (:type).', ['title' => $title, 'type' => $type]), BeneficiaryNote::KIND_DOCUMENT, $by);

        return $document;
    }

    /** A link that opens the file for five minutes and then does not. */
    public function link(BeneficiaryDocument $document): string
    {
        return URL::temporarySignedRoute('beneficiaries.documents.download', now()->addMinutes(self::LINK_MINUTES), ['document' => $document->ulid]);
    }

    public function recordDownload(BeneficiaryDocument $document, User $by): void
    {
        app(AuditLogger::class)->record(
            'beneficiary.document_downloaded',
            sprintf('Downloaded "%s" (%s) from case %s.', $document->title, $document->document_type, $document->beneficiary ? $document->beneficiary->case_reference : '?'),
            $document,
            $by,
            ['sensitive' => (bool) $document->is_sensitive],
        );
    }

    public function folder(): MediaFolder
    {
        $folder = MediaFolder::query()->where('slug', self::FOLDER_SLUG)->whereNull('parent_id')->first();

        if ($folder !== null) {
            return $folder;
        }

        $folder = new MediaFolder(['name' => 'Case files', 'slug' => self::FOLDER_SLUG, 'description' => 'Beneficiary case documents. Private; served only through a case.']);
        $folder->forceFill(['is_locked' => true])->save();

        return $folder;
    }
}
