<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Models\EventRegistration;
use App\Models\Subscriber;
use App\Models\Suppression;
use App\Models\User;
use App\Models\VolunteerApplication;
use App\Support\Anonymiser;
use App\Support\AuditLogger;
use App\Support\Sessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Delete my account" — with the carve-out the law requires.
 *
 * ── What goes ───────────────────────────────────────────────────────────────
 *
 * The account (soft-deleted, then scrubbed so the row identifies nobody),
 * every open session and the remember token, the newsletter subscription,
 * event registrations and volunteer applications (de-identified through
 * the same machinery the retention sweep uses), and the donor profile's
 * name, address and phone.
 *
 * ── What stays, and why ─────────────────────────────────────────────────────
 *
 * Donations, orders, receipts and payment transactions are tax and
 * accounting records: six years is a statutory minimum
 * (`compliance.retention.classes.financial_record`) and Act 843 s.27 and
 * GDPR art.17(3)(b) both except records kept to meet a legal obligation.
 * They stay linked to the donor row, which now carries a redaction marker
 * where the name was. The money is still accounted for; the person is no
 * longer identifiable from it.
 *
 * ── The address is suppressed, not merely forgotten ─────────────────────────
 *
 * A form on the site could otherwise put the address straight back on a
 * list. The suppression records that an erasure happened without keeping
 * anything but the address it applies to.
 */
final class AccountEraser
{
    public function __construct(
        private readonly Anonymiser $anonymiser,
        private readonly AuditLogger $audit,
    ) {}

    public function erase(User $user, string $reason = 'Requested by the account holder.'): void
    {
        $email = mb_strtolower((string) $user->email);
        $marker = $this->anonymiser->overwriteValue();

        $this->audit->record('erasure.requested', 'Account erasure requested by the account holder.', subject: $user, causer: $user);

        DB::transaction(function () use ($user, $email, $marker): void {
            Sessions::revokeAll($user);

            // The newsletter: gone, and the address may not come back through a form.
            Subscriber::query()->where('email', $email)->get()->each(fn (Subscriber $s) => $s->forceDelete());
            Suppression::record(Suppression::CHANNEL_EMAIL, $email, Suppression::REASON_ERASURE, 'Account erased at the account holder’s request', 'account', $user);

            if (filled($user->phone)) {
                Suppression::record(Suppression::CHANNEL_SMS, (string) $user->phone, Suppression::REASON_ERASURE, 'Account erased at the account holder’s request', 'account', $user);
            }

            // Registrations and applications: the same de-identification the
            // retention sweep applies, applied now.
            EventRegistration::query()
                ->where(fn ($q) => $q->where('user_id', $user->getKey())->orWhere('email', $email))
                ->get()->each(fn (EventRegistration $r) => $r->deIdentify());
            VolunteerApplication::query()
                ->where(fn ($q) => $q->where('user_id', $user->getKey())->orWhere('email', $email))
                ->get()->each(fn (VolunteerApplication $a) => $a->deIdentify());

            // The donor profile keeps its row (the donations point at it) and
            // loses everything that says who it was.
            if ($donor = $user->donor) {
                $donor->forceFill([
                    'name' => $marker,
                    'email' => $this->tombstoneEmail($donor->getKey()),
                    'phone' => null,
                    'phone_raw' => null,
                    'address' => null,
                    'city' => null,
                    'organisation_name' => null,
                    'notes' => null,
                    'consent_email' => false,
                    'consent_sms' => false,
                ])->save();

                $donor->donations()->update(['donor_name' => $marker, 'donor_email' => null, 'donor_phone' => null]);
            }

            // The account itself: scrubbed, then soft-deleted. The row stays so
            // foreign keys (audit causer, recorded_by) keep pointing at a row.
            $user->forceFill([
                'name' => $marker,
                'email' => $this->tombstoneEmail($user->getKey()),
                'phone' => null,
                'phone_raw' => null,
                'bio' => null,
                'job_title' => null,
                'password' => Str::random(64),
                'remember_token' => null,
                'is_active' => false,
                'accepts_email_marketing' => false,
                'accepts_sms_marketing' => false,
            ])->save();
            $user->disableTwoFactor();
            $user->delete();
        });

        $this->audit->record('erasure.completed', 'Account erased: profile scrubbed, sessions ended, newsletter removed and address suppressed; financial records retained under the statutory minimum.', subject: $user, causer: $user, context: ['reason' => $reason]);
    }

    /** A unique, unroutable placeholder: the column is unique and not null. */
    private function tombstoneEmail(int|string $key): string
    {
        return 'erased-'.$key.'@erased.invalid';
    }
}
