<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Communications\MessageDispatcher;
use App\Http\Requests\ContactRequest;
use App\Models\ContactDepartment;
use App\Models\ContactMessage;
use App\Support\PageMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

/**
 * The contact form.
 *
 * ── The missing half of a finished feature ──────────────────────────────────
 *
 * `contact_messages`, `contact_departments`, the confidential routing, the
 * inbox with its assignment and reply flow, and the `contact.acknowledgement`
 * email template were all built by Phase 5. Nothing could create a message. The
 * admin side of a contact system with no public form is a screen that will
 * always be empty.
 *
 * ── The acknowledgement must never lose the message ─────────────────────────
 *
 * The enquiry is stored first and the email is attempted afterwards, inside a
 * try. If the mail host is down — which on shared hosting it periodically is —
 * the sender does not get their auto-reply, and the foundation still has the
 * message. The other order loses the enquiry entirely and tells the sender it
 * failed, so they give up rather than trying again.
 *
 * ── Consent evidence is snapshotted, not referenced ─────────────────────────
 *
 * The exact wording shown on the day is stored on the record. Consent to a
 * privacy notice that has since been rewritten is not evidence of anything, and
 * Act 843 asks what they agreed to, not which page it was on.
 */
class ContactController extends Controller
{
    public function show(): View
    {
        return view('contact', [
            'departments' => $this->publicDepartments(),
            'meta' => PageMeta::site(
                __('Contact us').setting('seo.title_suffix', ''),
                __('How to reach :name.', ['name' => setting('general.short_name', config('app.name'))]),
            ),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Contact us'), 'url' => null],
            ],
        ]);
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        $department = $this->resolveDepartment($request->integer('contact_department_id'));

        $message = ContactMessage::create([
            'contact_department_id' => $department?->getKey(),
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'phone' => $request->string('phone')->toString() ?: null,
            'subject' => $request->string('subject')->toString() ?: null,
            'message' => $request->string('message')->toString(),
            'consent_given' => true,
            'consent_text' => $this->consentText(),
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'source_url' => $request->headers->get('referer'),
        ]);

        $this->acknowledge($message, $department);

        return back()->with('status', __(
            'Thank you. Your reference is :reference — quote it if you write to us again.',
            ['reference' => $message->reference],
        ));
    }

    /**
     * Which departments a visitor may choose.
     *
     * ⚠ Confidential ones are excluded. A safeguarding route offered in a
     * dropdown beside "Shop enquiries" is a safeguarding route that gets used
     * for shop enquiries — and, worse, one whose existence and address are
     * published to every scraper. Reports reach it the way the safeguarding
     * policy says they do, not through this form.
     *
     * @return Collection<int, ContactDepartment>
     */
    private function publicDepartments(): Collection
    {
        return ContactDepartment::query()
            ->where('is_active', true)
            ->where('is_confidential', false)
            ->orderBy('sort_order')
            ->get();
    }

    private function resolveDepartment(?int $id): ?ContactDepartment
    {
        if ($id === null || $id === 0) {
            return null;
        }

        // Re-checked here as well as in the request. The rule proves the row
        // exists and is active; this proves it is one this form offers, which
        // is the part that keeps a confidential department unreachable.
        return $this->publicDepartments()->firstWhere('id', $id);
    }

    /**
     * The wording they ticked, stored with the record.
     *
     * From the settings layer, so it can be corrected without a deploy and so
     * the form and the stored evidence cannot disagree.
     */
    private function consentText(): string
    {
        return (string) setting(
            'compliance.contact_consent_text',
            __('I agree that :name may store these details in order to reply to me.', [
                'name' => setting('general.legal_name', setting('general.short_name', config('app.name'))),
            ]),
        );
    }

    /**
     * Tell the sender we have it.
     *
     * Through `MessageDispatcher`, like every other email — so it is logged,
     * suppression-checked and rate-limited rather than bypassing all three. A
     * failure is swallowed on purpose: see the note at the top of this class.
     */
    private function acknowledge(ContactMessage $message, ?ContactDepartment $department): void
    {
        try {
            app(MessageDispatcher::class)->queueEmail('contact.acknowledgement', $message->email, [
                'name' => $message->name,
                'reference' => $message->reference,
                'department' => $department?->name ?? '',
                'sla_hours' => (string) ($department?->sla_hours ?? ''),
            ]);
        } catch (Throwable) {
            // The enquiry is already saved. An acknowledgement that could not
            // be queued is a missing courtesy, not a lost message.
        }
    }
}
