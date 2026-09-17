<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Community\VolunteerNotifier;
use App\Http\Requests\VolunteerApplicationRequest;
use App\Models\VolunteerApplication;
use App\Models\VolunteerOpportunity;
use App\Support\PageMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Volunteering: the open roles, and applying for one.
 *
 * ── A general application, when nothing specific is open ───────────────────
 *
 * Somebody who wants to help and finds no role listed should not be sent
 * away. `/volunteer/apply` takes an application against no role; it is
 * treated as involving vulnerable contact, because for this foundation that is
 * the safe assumption, and the full check set applies.
 *
 * ── The application is created and submitted in one step ───────────────────
 *
 * `VolunteerApplication::submit()` refuses without the declaration, records
 * when and from where it was agreed, and opens the safeguarding checks the
 * role requires. The confirmation page and the email both say what happens
 * next: checks before any placement, because that is the honest answer to
 * "when do I start?".
 */
class VolunteerController extends Controller
{
    public function index(): View
    {
        $roles = VolunteerOpportunity::query()
            ->open()
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->with(['project', 'division'])
            ->orderBy('closes_on')
            ->orderBy('title')
            ->get();

        return view('volunteer.index', [
            'roles' => $roles,
            'meta' => PageMeta::site(__('Volunteer'), (string) setting('volunteering.intro', __('Give your time.'))),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Volunteer'), 'url' => null],
            ],
        ]);
    }

    public function show(VolunteerOpportunity $opportunity): View
    {
        if (! $opportunity->is_published || ($opportunity->published_at !== null && $opportunity->published_at->isFuture())) {
            throw new NotFoundHttpException;
        }

        return view('volunteer.show', [
            'role' => $opportunity,
            'open' => $opportunity->isOpen(),
            'meta' => PageMeta::site($opportunity->title, $opportunity->summary),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Volunteer'), 'url' => route('volunteer.index')],
                ['label' => $opportunity->title, 'url' => null],
            ],
        ]);
    }

    /** The general application, against no particular role. */
    public function general(): View
    {
        return view('volunteer.show', [
            'role' => null,
            'open' => true,
            'meta' => PageMeta::site(__('Apply to volunteer'), (string) setting('volunteering.intro', __('Give your time.'))),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Volunteer'), 'url' => route('volunteer.index')],
                ['label' => __('Apply'), 'url' => null],
            ],
        ]);
    }

    public function apply(VolunteerApplicationRequest $request, ?VolunteerOpportunity $opportunity = null): RedirectResponse
    {
        if ($opportunity !== null && ! $opportunity->isOpen()) {
            return back()->withInput()->withErrors(['application' => __('Applications for this role have closed.')]);
        }

        $application = new VolunteerApplication([
            'volunteer_opportunity_id' => $opportunity?->getKey(),
            'user_id' => $request->user()?->getKey(),
            'full_name' => $request->string('full_name')->toString(),
            'email' => mb_strtolower(trim($request->string('email')->toString())),
            'phone' => $request->string('phone')->toString(),
            'region' => $request->string('region')->toString() ?: null,
            'occupation' => $request->string('occupation')->toString() ?: null,
            'date_of_birth' => $request->input('date_of_birth') ?: null,
            'motivation' => $request->string('motivation')->toString(),
            'experience' => $request->string('experience')->toString() ?: null,
            'availability' => $request->string('availability')->toString() ?: null,
            'skills' => $request->string('skills')->toString() ?: null,
            'referees' => collect((array) $request->input('referees', []))
                ->take(2)
                ->map(fn (array $r): array => [
                    'name' => trim((string) ($r['name'] ?? '')) ?: null,
                    'relationship' => trim((string) ($r['relationship'] ?? '')) ?: null,
                    'phone' => trim((string) ($r['phone'] ?? '')) ?: null,
                    'email' => mb_strtolower(trim((string) ($r['email'] ?? ''))) ?: null,
                ])
                ->filter(fn (array $r): bool => $r['name'] !== null)
                ->values()
                ->all() ?: null,
            'next_of_kin_name' => $request->string('next_of_kin_name')->toString() ?: null,
            'next_of_kin_phone' => $request->string('next_of_kin_phone')->toString() ?: null,
            'disclosed_convictions' => $request->string('disclosed_convictions')->toString() ?: null,
            'declaration_agreed' => true,
            'declaration_text' => $this->declarationText(),
            'declaration_ip' => $request->ip(),
        ]);

        $application->save();
        $application->submit();

        app(VolunteerNotifier::class)->received($application->load('opportunity'));

        return redirect()->route('volunteer.applied', $application)
            ->with('track', ['event' => 'volunteer_application', 'key' => 'volunteer:'.$application->reference]);
    }

    public function applied(VolunteerApplication $application): View
    {
        return view('volunteer.applied', [
            'application' => $application->load('opportunity'),
            'meta' => PageMeta::site(__('Application received'), noindex: true),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Volunteer'), 'url' => route('volunteer.index')],
                ['label' => __('Application received'), 'url' => null],
            ],
        ]);
    }

    private function declarationText(): string
    {
        return (string) setting(
            'compliance.volunteer_declaration_text',
            __('I have read the safeguarding policy, I have disclosed any conviction, caution or investigation that could be relevant to working with children or vulnerable adults, and the information I have given is true.'),
        );
    }
}
