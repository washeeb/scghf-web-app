<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Community\EventNotifier;
use App\Http\Requests\EventRegistrationRequest;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Support\PageMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Events: what is coming up, what happened, and registering to come.
 *
 * ── The past is an archive, not a deletion ──────────────────────────────────
 *
 * A foundation's events page is mostly read by people deciding whether to
 * trust it, and "what have you actually done" is answered by the events that
 * took place, with their photographs. Past events stay reachable; they simply
 * move below the line.
 *
 * ── Registration is a plain form, and over capacity is a waiting list ───────
 *
 * `EventRegistration::place()` does the work under a row lock, so two people
 * booking the last two places in the same second cannot both succeed — and
 * somebody beyond capacity is waitlisted rather than refused. A charity event
 * that turns people away outright loses them.
 *
 * ── A second registration from the same address updates, not duplicates ─────
 *
 * The table has a unique index on (event, email). Somebody who registers
 * twice — to add a guest, to correct a phone number — is told so and their
 * registration is updated, rather than appearing twice on the door list.
 */
class EventController extends Controller
{
    private const PER_PAGE = 9;

    public function index(Request $request): View
    {
        $upcoming = Event::query()
            ->live()
            ->where(fn (Builder $q) => $q->where('starts_at', '>=', now())->orWhere('ends_at', '>=', now()))
            ->with('featuredImage')
            ->orderBy('starts_at')
            ->get();

        $past = Event::query()
            ->live()
            ->where('starts_at', '<', now())
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '<', now()))
            ->with('featuredImage')
            ->orderByDesc('starts_at')
            ->paginate(self::PER_PAGE, ['*'], 'past')
            ->withQueryString();

        return view('events.index', [
            'upcoming' => $upcoming,
            'past' => $past,
            'meta' => PageMeta::site(__('Events'), (string) setting('events.intro', __('Come and be part of the work.'))),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Events'), 'url' => null],
            ],
        ]);
    }

    public function show(Event $event): View
    {
        if (! $event->isLive()) {
            throw new NotFoundHttpException;
        }

        $event->load(['featuredImage', 'project', 'cause', 'division']);

        return view('events.show', [
            'event' => $event,
            'rejection' => $event->registration_required ? $event->registrationRejectionReason() : null,
            'meta' => PageMeta::for($event, route('events.show', $event)),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Events'), 'url' => route('events.index')],
                ['label' => $event->title, 'url' => null],
            ],
        ]);
    }

    public function register(EventRegistrationRequest $request, Event $event): RedirectResponse
    {
        if (! $event->isLive()) {
            throw new NotFoundHttpException;
        }

        $email = mb_strtolower(trim($request->string('email')->toString()));

        $existing = $event->registrations()
            ->where('email', $email)
            ->whereIn('status', [EventRegistration::STATUS_REGISTERED, EventRegistration::STATUS_WAITLISTED])
            ->first();

        if ($existing !== null) {
            /*
             * Already registered. Rather than a duplicate — refused by the
             * unique index — or a silent overwrite, the details that can
             * sensibly change are updated and the person is told.
             */
            $existing->fill([
                'name' => $request->string('name')->toString(),
                'phone' => $request->string('phone')->toString() ?: null,
                'accessibility_needs' => $request->string('accessibility_needs')->toString() ?: null,
                'dietary_needs' => $request->string('dietary_needs')->toString() ?: null,
            ])->save();

            return redirect()
                ->route('events.registered', $existing)
                ->with('status', __('You were already registered — we have updated your details.'));
        }

        try {
            $registration = EventRegistration::place($event, [
                'user_id' => $request->user()?->getKey(),
                'name' => $request->string('name')->toString(),
                'email' => $email,
                'phone' => $request->string('phone')->toString() ?: null,
                'guests' => (int) $request->integer('guests', 0),
                'accessibility_needs' => $request->string('accessibility_needs')->toString() ?: null,
                'dietary_needs' => $request->string('dietary_needs')->toString() ?: null,
                'photography_consent' => $request->boolean('photography_consent'),
                'contact_consent' => $request->boolean('contact_consent'),
                'newsletter_consent' => $request->boolean('newsletter_consent'),
                'consent_text' => $this->consentText(),
                'consent_ip' => $request->ip(),
            ]);
        } catch (RuntimeException $e) {
            // Registration closed between the page loading and the form
            // arriving. The model says exactly why, in words for a visitor.
            return back()->withInput()->withErrors(['registration' => $e->getMessage()]);
        }

        app(EventNotifier::class)->confirm($registration->load('event'));

        return redirect()->route('events.registered', $registration);
    }

    /**
     * The confirmation page.
     *
     * By ULID — it is what §1.1 requires of anything in a URL — and it says
     * the reference and whether the place is confirmed or waitlisted, so
     * somebody who never receives the email still knows where they stand.
     */
    public function registered(EventRegistration $registration): View
    {
        $registration->load('event');

        return view('events.registered', [
            'registration' => $registration,
            'event' => $registration->event,
            'meta' => PageMeta::site(__('You are registered'), noindex: true),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Events'), 'url' => route('events.index')],
                ['label' => __('Registered'), 'url' => null],
            ],
        ]);
    }

    private function consentText(): string
    {
        return (string) setting(
            'compliance.event_consent_text',
            __('I agree that :name may hold my details in order to run this event.', [
                'name' => setting('general.legal_name', setting('general.short_name', config('app.name'))),
            ]),
        );
    }
}
