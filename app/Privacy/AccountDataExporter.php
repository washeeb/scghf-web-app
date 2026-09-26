<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Models\Donation;
use App\Models\Donor;
use App\Models\EventRegistration;
use App\Models\Order;
use App\Models\Subscriber;
use App\Models\User;
use App\Models\VolunteerApplication;

/**
 * Everything the foundation holds about one account holder, as data.
 *
 * Act 843 s.32 and GDPR art.15 both give a person the right to a copy of
 * their personal data. This is that copy: the account, the giving record,
 * the orders, the registrations, the applications, the newsletter status,
 * the consents with their timestamps, and where they have signed in from.
 *
 * What is NOT here, on purpose: other people. A donation in memory of
 * somebody names that somebody; a volunteer application names two
 * referees; an order names a recipient. Those are third parties' data
 * and a subject access request does not reach them, so the export carries
 * the fact of the relationship and not the other person's details.
 */
final class AccountDataExporter
{
    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        $email = mb_strtolower((string) $user->email);
        // An unsaved User (scghf:export-data for somebody with no account)
        // has no relations to load; the email finds the donor row instead.
        $donor = $user->exists ? $user->donor : Donor::query()->where('email', $email)->first();

        return [
            'generated_at' => now()->toIso8601String(),
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'created_at' => $user->created_at?->toIso8601String(),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'marketing_email' => (bool) $user->accepts_email_marketing,
                'marketing_sms' => (bool) $user->accepts_sms_marketing,
            ],
            'donor_profile' => $donor === null ? null : [
                'name' => $donor->name,
                'email' => $donor->email,
                'phone' => $donor->phone,
                'address' => $donor->address,
                'city' => $donor->city,
                'country' => $donor->country,
                'consent' => [
                    'email' => (bool) $donor->consent_email,
                    'sms' => (bool) $donor->consent_sms,
                    'text_agreed' => $donor->consent_text,
                    'given_at' => $donor->consent_at?->toIso8601String(),
                ],
            ],
            'donations' => ($donor?->donations() ?? Donation::query()->whereRaw('1 = 0'))
                ->with(['cause', 'receipt'])
                ->latest()
                ->get()
                ->map(fn (Donation $d): array => [
                    'reference' => $d->reference,
                    'date' => $d->created_at?->toIso8601String(),
                    'amount' => $d->amount->format(),
                    'status' => $d->status->value,
                    'towards' => $d->cause?->title,
                    'regular' => $d->subscription_id !== null,
                    'receipt_number' => $d->receipt?->receipt_number,
                    'in_memory_of_someone' => filled($d->tribute_name),
                ])->all(),
            'regular_gifts' => ($donor?->subscriptions() ?? Donation::query()->whereRaw('1 = 0'))
                ->get()
                ->map(fn ($s): array => [
                    'reference' => $s->reference ?? null,
                    'amount' => $s->amount?->format(),
                    'interval' => $s->interval ?? null,
                    'status' => $s->status ?? null,
                    'started' => $s->created_at?->toIso8601String(),
                ])->all(),
            'orders' => Order::query()
                ->where(fn ($q) => $q->where('user_id', $user->getKey())->orWhere('customer_email', $email))
                ->with('items')
                ->latest()
                ->get()
                ->map(fn (Order $o): array => [
                    'reference' => $o->reference,
                    'date' => $o->created_at?->toIso8601String(),
                    'status' => $o->status->value,
                    'total' => $o->total?->format(),
                    'items' => $o->items->map(fn ($i): string => $i->quantity.' × '.$i->name)->all(),
                ])->all(),
            'event_registrations' => EventRegistration::query()
                ->where(fn ($q) => $q->where('user_id', $user->getKey())->orWhere('email', $email))
                ->with('event')
                ->get()
                ->map(fn (EventRegistration $r): array => [
                    'reference' => $r->reference,
                    'event' => $r->event?->title,
                    'status' => $r->status,
                    'consents' => [
                        'photography' => $r->photography_consent,
                        'contact_about_event' => $r->contact_consent,
                        'newsletter' => $r->newsletter_consent,
                        'text_agreed' => $r->consent_text,
                    ],
                ])->all(),
            'volunteer_applications' => VolunteerApplication::query()
                ->where(fn ($q) => $q->where('user_id', $user->getKey())->orWhere('email', $email))
                ->get()
                ->map(fn (VolunteerApplication $a): array => [
                    'reference' => $a->reference,
                    'status' => $a->status,
                    'submitted_at' => $a->submitted_at?->toIso8601String(),
                    'motivation' => $a->motivation,
                    'experience' => $a->experience,
                    'skills' => $a->skills,
                    'availability' => $a->availability,
                    // Referees and next of kin are other people; not exported.
                    'declaration' => ['agreed' => (bool) $a->declaration_agreed, 'at' => $a->declaration_at?->toIso8601String()],
                ])->all(),
            'newsletter' => ($sub = Subscriber::query()->where('email', $email)->first()) === null ? null : [
                'status' => $sub->status,
                'topics' => $sub->topics,
                'consent_text' => $sub->consent_text,
                'consent_at' => $sub->consent_at?->toIso8601String(),
                'source' => $sub->source,
            ],
            'sign_ins' => ($user->exists ? $user->loginHistories()->latest()->limit(50)->get() : collect())
                ->map(fn ($h): array => [
                    'at' => $h->created_at?->toIso8601String(),
                    'outcome' => $h->outcome?->value ?? (string) $h->outcome,
                    'ip' => $h->ip_address,
                    'device' => $h->user_agent,
                ])->all(),
        ];
    }
}
