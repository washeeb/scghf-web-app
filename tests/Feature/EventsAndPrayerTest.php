<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\PrayerRequest;
use App\Support\RetentionRunner;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Events and prayer requests
|--------------------------------------------------------------------------
|
| Two consent rules, both of which are easy to get wrong in the direction that
| harms somebody:
|
|   1. photography consent must be ASKED. "We never asked" and "they said no"
|      are different answers, and only one of them can be inferred from silence
|      — the wrong one.
|   2. a prayer request is confidential unless the person who sent it said
|      otherwise. Publishing "pray for Ama, who has been diagnosed with cancer"
|      because a website needs content is a serious Act 843 breach and a
|      betrayal of the person who asked.
|
*/

// ═══════════════════════════════════════════════════════════════════════════
//  Photography consent
// ═══════════════════════════════════════════════════════════════════════════

it('distinguishes never having asked from being told no', function () {
    $event = Event::factory()->create();

    $notAsked = EventRegistration::place($event, ['name' => 'Ama', 'email' => 'ama@example.com']);
    $saidNo = EventRegistration::place($event->fresh(), [
        'name' => 'Kofi', 'email' => 'kofi@example.com', 'photography_consent' => false,
    ]);

    expect($notAsked->wasAskedAboutPhotography())->toBeFalse()
        ->and($saidNo->wasAskedAboutPhotography())->toBeTrue()
        // Both are a no for publication, but only one is a refusal.
        ->and($notAsked->mayBePhotographed())->toBeFalse()
        ->and($saidNo->mayBePhotographed())->toBeFalse();
});

it('treats an unanswered photography question as a no', function () {
    // An absent answer is not permission, and treating it as one is exactly how
    // a photograph of somebody who would have objected ends up on a website.
    $event = Event::factory()->create();

    $registration = EventRegistration::place($event, ['name' => 'Ama', 'email' => 'ama@example.com']);

    expect($registration->photography_consent)->toBeNull()
        ->and($registration->mayBePhotographed())->toBeFalse();
});

it('gives the photographer a door list of people who agreed', function () {
    $event = Event::factory()->create();

    $yes = EventRegistration::place($event, [
        'name' => 'Ama', 'email' => 'ama@example.com', 'photography_consent' => true,
    ]);
    EventRegistration::place($event->fresh(), [
        'name' => 'Kofi', 'email' => 'kofi@example.com', 'photography_consent' => false,
    ]);
    EventRegistration::place($event->fresh(), ['name' => 'Yaw', 'email' => 'yaw@example.com']);

    expect(EventRegistration::query()->photographable()->pluck('id')->all())->toBe([$yes->id]);
});

it('keeps the three consents separate', function () {
    // Agreeing to be photographed is not agreeing to be emailed about the
    // event, and neither is agreeing to a newsletter.
    $event = Event::factory()->create();

    $registration = EventRegistration::place($event, [
        'name' => 'Ama',
        'email' => 'ama@example.com',
        'photography_consent' => true,
        'contact_consent' => true,
        'newsletter_consent' => false,
        'consent_text' => 'I agree to be contacted about this event.',
    ]);

    expect($registration->mayBePhotographed())->toBeTrue()
        ->and($registration->mayBeEmailedAboutEvent())->toBeTrue()
        // A registration is not a mailing list.
        ->and($registration->mayBeAddedToNewsletter())->toBeFalse()
        ->and($registration->consent_at)->not->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════════════
//  Capacity
// ═══════════════════════════════════════════════════════════════════════════

it('counts people rather than bookings against capacity', function () {
    // A registration bringing three guests takes four places. Counting bookings
    // is how a room built for eighty ends up with a hundred and forty in it.
    $event = Event::factory()->create(['capacity' => 10]);

    EventRegistration::place($event, ['name' => 'Ama', 'email' => 'ama@example.com', 'guests' => 3]);

    expect($event->fresh()->registered_count)->toBe(4)
        ->and($event->fresh()->placesRemaining())->toBe(6);
});

it('waitlists rather than turning somebody away', function () {
    // A charity event that refuses people outright loses them; one that
    // waitlists them can call when somebody drops out.
    $event = Event::factory()->create(['capacity' => 2]);

    EventRegistration::place($event, ['name' => 'Ama', 'email' => 'ama@example.com', 'guests' => 1]);
    $late = EventRegistration::place($event->fresh(), ['name' => 'Kofi', 'email' => 'kofi@example.com']);

    expect($late->status)->toBe(EventRegistration::STATUS_WAITLISTED)
        // A waitlisted booking does not consume a place.
        ->and($event->fresh()->registered_count)->toBe(2);
});

it('frees the place back up on cancellation', function () {
    $event = Event::factory()->create(['capacity' => 10]);
    $registration = EventRegistration::place($event, [
        'name' => 'Ama', 'email' => 'ama@example.com', 'guests' => 2,
    ]);

    $registration->cancel();

    expect($event->fresh()->registered_count)->toBe(0)
        ->and($event->fresh()->placesRemaining())->toBe(10);
});

it('does not double-free a place on a repeated cancellation', function () {
    $event = Event::factory()->create(['capacity' => 10]);
    $registration = EventRegistration::place($event, ['name' => 'Ama', 'email' => 'ama@example.com']);

    $registration->cancel();
    $registration->fresh()->cancel();

    expect($event->fresh()->registered_count)->toBe(0);
});

it('recomputes the headcount from the registrations', function () {
    $event = Event::factory()->create(['capacity' => 50]);
    EventRegistration::place($event, ['name' => 'Ama', 'email' => 'ama@example.com', 'guests' => 2]);
    EventRegistration::place($event->fresh(), ['name' => 'Kofi', 'email' => 'kofi@example.com']);

    Event::whereKey($event->id)->update(['registered_count' => 999]);

    expect($event->fresh()->refreshHeadcount())->toBe(4);
});

it('takes no registration for an event that has already happened', function () {
    $event = Event::factory()->past()->create();

    EventRegistration::place($event, ['name' => 'Ama', 'email' => 'ama@example.com']);
})->throws(RuntimeException::class, 'already taken place');

it('takes no registration for a cancelled event', function () {
    $event = Event::factory()->create();
    $event->cancel('The venue flooded.');

    EventRegistration::place($event->fresh(), ['name' => 'Ama', 'email' => 'ama@example.com']);
})->throws(RuntimeException::class, 'has been cancelled');

it('insists on a reason for cancelling', function () {
    // Everybody registered will be told it, and "cancelled" alone is not an
    // explanation.
    Event::factory()->create()->cancel('');
})->throws(RuntimeException::class, 'needs a reason');

it('refuses an event that ends before it starts', function () {
    Event::factory()->create(['starts_at' => now()->addWeek(), 'ends_at' => now()->addDay()]);
})->throws(RuntimeException::class, 'cannot end before it starts');

it('will not mark an event ticketed while ticketing is switched off', function () {
    // The price would show with no way to pay it.
    config(['features.event_ticketing' => false]);

    Event::factory()->create(['is_ticketed' => true, 'ticket_price' => 5_000]);
})->throws(RuntimeException::class, 'ticketing is switched off');

// ═══════════════════════════════════════════════════════════════════════════
//  Prayer requests — confidential by default
// ═══════════════════════════════════════════════════════════════════════════

it('is confidential unless the person who sent it said otherwise', function () {
    $request = PrayerRequest::factory()->create();

    expect($request->is_confidential)->toBeTrue()
        ->and($request->consent_to_publish)->toBeFalse()
        ->and($request->canBePublished())->toBeFalse();
});

it('refuses to publish a confidential request', function () {
    // Publishing "pray for Ama, who has been diagnosed with cancer" because a
    // website needs content is a serious breach and a betrayal.
    PrayerRequest::factory()->create()->publish();
})->throws(RuntimeException::class, 'submitted in confidence');

it('refuses to publish by a back door either', function () {
    /*
     * `is_published` is not fillable, so mass assignment stops the obvious
     * route on its own. This exercises the one that gets past that —
     * forceFill, which is what an import or a hand-written admin action uses —
     * and the saving hook catches it.
     */
    PrayerRequest::factory()->create()->forceFill(['is_published' => true])->save();
})->throws(RuntimeException::class, 'submitted in confidence');

it('will not let mass assignment set the published flag at all', function () {
    PrayerRequest::factory()->create()->update(['is_published' => true]);
})->throws(MassAssignmentException::class);

it('publishes once explicit consent is on file', function () {
    $request = PrayerRequest::factory()->publishable()->create();

    $request->publish();

    expect($request->fresh()->is_published)->toBeTrue()
        ->and($request->fresh()->published_at)->not->toBeNull();
});

it('still refuses while the confidential flag stands', function () {
    // Consent to publish AND the flag cleared. Either alone is not enough.
    $request = PrayerRequest::factory()->create([
        'consent_to_publish' => true,
        'is_confidential' => true,
    ]);

    $request->publish();
})->throws(RuntimeException::class, 'marked confidential');

it('publishes anonymously by default even with consent', function () {
    // Somebody happy for their situation to be prayed about publicly is not
    // necessarily happy to be named in it.
    $request = PrayerRequest::factory()->publishable()->create(['name' => 'Ama Boateng']);

    expect($request->publish_anonymously)->toBeTrue()
        ->and($request->publicName())->toBe('A member of our community');
});

it('names somebody only when they asked to be named', function () {
    $request = PrayerRequest::factory()->publishable()->create([
        'name' => 'Ama Boateng',
        'publish_anonymously' => false,
    ]);

    expect($request->publicName())->toBe('Ama Boateng');
});

it('strips contact details from an anonymous request', function () {
    // Somebody choosing not to be identified and then being emailed about it
    // has had their choice overridden by a form.
    $request = PrayerRequest::factory()->anonymous()->create([
        'name' => 'Ama', 'email' => 'ama@example.com', 'phone' => '+233241234567',
    ]);

    expect($request->name)->toBeNull()
        ->and($request->email)->toBeNull()
        ->and($request->phone)->toBeNull()
        ->and($request->canBeFollowedUp())->toBeFalse();
});

it('says why publication was refused rather than just refusing', function () {
    expect(PrayerRequest::factory()->create()->publicationRejectionReason())
        ->toContain('explicit consent');
});

it('keeps the public wall to consented, non-confidential requests', function () {
    $published = PrayerRequest::factory()->publishable()->create();
    $published->publish();
    PrayerRequest::factory()->create();

    expect(PrayerRequest::query()->publishable()->pluck('id')->all())->toBe([$published->id]);
});

it('counts prayers without touching anything else', function () {
    $request = PrayerRequest::factory()->create();

    $request->recordPrayer();
    $request->recordPrayer();

    expect($request->fresh()->prayed_count)->toBe(2)
        ->and($request->fresh()->status)->toBe(PrayerRequest::STATUS_PRAYING);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Retention
// ═══════════════════════════════════════════════════════════════════════════

it('classifies every column on both tables', function () {
    expect(EventRegistration::unclassifiedColumns())->toBe([])
        ->and(PrayerRequest::unclassifiedColumns())->toBe([]);
});

it('treats accessibility and dietary needs as health data', function () {
    // They routinely reveal a disability or a medical condition.
    $elements = EventRegistration::privacyElements();

    expect($elements['accessibility_needs'])->toBe('medical')
        ->and($elements['dietary_needs'])->toBe('medical');
});

it('treats the request text itself as the sensitive part', function () {
    expect(PrayerRequest::privacyElements()['request'])->toBe('medical');
});

/**
 * Register for an event, then let the event age into the past.
 *
 * The only honest way to build a historical registration: `place()` refuses a
 * past event, which is correct — people register beforehand, and the record
 * ages afterwards.
 */
function historicRegistration(array $details): EventRegistration
{
    $event = Event::factory()->create();
    $registration = EventRegistration::place($event, $details);

    $event->forceFill([
        'starts_at' => now()->subYears(3),
        'ends_at' => now()->subYears(3)->addHours(4),
    ])->save();

    return $registration->fresh();
}

it('starts the registration clock when the event finishes, not when it was made', function () {
    // A registration for an event that has not happened is live data.
    $upcoming = EventRegistration::place(
        Event::factory()->create(),
        ['name' => 'Ama', 'email' => 'ama@example.com'],
    );

    $past = historicRegistration(['name' => 'Kofi', 'email' => 'kofi@example.com']);

    expect($upcoming->retentionAnchorDate())->toBeNull()
        ->and($past->retentionAnchorDate())->not->toBeNull();
});

it('sweeps a prayer request after twelve months', function () {
    // Long enough to pray, to follow up and to report in aggregate. Not long
    // enough to become an archive of a congregation's private difficulties.
    $old = PrayerRequest::factory()->create();
    $old->forceFill(['created_at' => now()->subMonths(18)])->save();

    PrayerRequest::factory()->create();

    app(RetentionRunner::class)->run(execute: true);

    expect(PrayerRequest::withTrashed()->count())->toBe(1)
        ->and(PrayerRequest::withTrashed()->find($old->id))->toBeNull();
});

it('sweeps an old event registration', function () {
    $registration = historicRegistration(['name' => 'Kofi', 'email' => 'kofi@example.com']);

    app(RetentionRunner::class)->run(execute: true);

    expect(EventRegistration::find($registration->id))->toBeNull();
});

it('leaves a registration for an upcoming event alone', function () {
    EventRegistration::place(
        Event::factory()->create(),
        ['name' => 'Ama', 'email' => 'ama@example.com'],
    );

    app(RetentionRunner::class)->run(execute: true);

    expect(EventRegistration::count())->toBe(1);
});

it('gives a prayer request a far shorter life than a beneficiary case', function () {
    expect(config('compliance.retention.classes.prayer_request.months'))->toBe(12)
        ->and(config('compliance.retention.classes.prayer_request.sensitive'))->toBeTrue()
        ->and(config('compliance.retention.classes.beneficiary_case_record.months'))->toBe(72);
});
