<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactMessages\Pages;

use App\Filament\Resources\ContactMessages\ContactMessageResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditContactMessage extends EditRecord
{
    protected static string $resource = ContactMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * Save the handling fields, and only the handling fields.
     *
     * ── Why this is not a plain `update()` ─────────────────────────────────
     *
     * `status`, `assigned_to` and `internal_notes` are deliberately outside
     * `ContactMessage::$fillable`. The public contact form creates these
     * records straight from request input, and a guarded status is what stops
     * somebody submitting an enquiry that arrives already marked "resolved" —
     * or assigned to a named member of staff.
     *
     * So the admin screen writes them explicitly. The three fields are listed
     * here rather than taken from `$data` wholesale, because the rest of the
     * form is the sender's own message and must not be editable at all: what
     * somebody sent is a record of what they sent, and for a safeguarding
     * report it may be evidence.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->forceFill([
            'status' => $data['status'] ?? $record->status,
            'assigned_to' => $data['assigned_to'] ?? null,
            'internal_notes' => $data['internal_notes'] ?? null,
        ])->save();

        return $record;
    }
}
