<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Records that somebody opened a record that holds personal data.
 *
 * ── The events that leave no other trace anywhere ───────────────────────────
 *
 * Editing writes an activity-log row and exporting writes an audit row, but
 * reading writes nothing — and under Act 843 "who has looked at this person's
 * details" is a question the foundation has to be able to answer. So a view
 * page that shows personal data records the fact, once per page load, with
 * who and which record. Not the data itself: the audit trail is not a second
 * copy of the thing it protects.
 *
 * Only recorded when the personal details were actually shown. Somebody who
 * may see a gift but not the donor's contact details has not accessed them.
 */
trait AuditsRecordAccess
{
    protected function auditAccess(string $event, string $description, Model $record, bool $shown = true): void
    {
        if (! $shown) {
            return;
        }

        app(AuditLogger::class)->record($event, $description, $record, auth()->user());
    }
}
