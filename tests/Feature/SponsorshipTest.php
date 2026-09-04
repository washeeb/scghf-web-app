<?php

declare(strict_types=1);

use App\Models\Consent;
use App\Models\EventTicket;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Sponsorship;
use App\Models\SponsorshipUpdate;
use App\Models\User;
use App\Support\Features;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sponsorship, tickets and reviews
|--------------------------------------------------------------------------
|
| `features.sponsorship` has been true since Phase 2 with nothing behind it —
| the worst of the three states, because off is a decision and on-and-empty is
| a promise the application cannot keep.
|
| It is also the most safeguarding-sensitive thing this foundation could
| build: it links a named adult to a named vulnerable child and then sends
| that adult photographs and news about them, indefinitely. Done carelessly it
| is a system for introducing strangers to children and telling them where to
| find them.
|
*/

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->fieldWorker = User::factory()->staff()->create();
    $this->fieldWorker->assignRole('Programme Officer');

    $this->safeguardingLead = User::factory()->staff()->create();
    $this->safeguardingLead->assignRole('Admin');
});

function consentFor(Sponsorship $sponsorship, string $type, array $attributes = []): Consent
{
    return Consent::factory()->create(array_merge([
        'consentable_type' => $sponsorship->beneficiary->getMorphClass(),
        'consentable_id' => $sponsorship->beneficiary_id,
        'consent_type' => $type,
        'granted_at' => now()->subMonth(),
    ], $attributes));
}

// ── Nothing leaves without a live consent ───────────────────────────────────

it('tells a sponsor nothing until somebody has cleared it', function () {
    // All three permissions default to false. A sponsorship that has not been
    // through the consent check discloses nothing at all.
    $sponsorship = Sponsorship::factory()->create();

    expect($sponsorship->may_receive_updates)->toBeFalse()
        ->and($sponsorship->may_receive_photographs)->toBeFalse()
        ->and($sponsorship->may_know_given_name)->toBeFalse();
});

it('refuses an update with no consent behind it', function () {
    $sponsorship = Sponsorship::factory()->cleared()->create();

    expect($sponsorship->fresh()->canReceiveUpdate())->toBeFalse()
        ->and($sponsorship->fresh()->updateRejectionReason())->toContain('no current consent');
});

it('allows an update once a story consent exists', function () {
    $sponsorship = Sponsorship::factory()->cleared()->create();
    consentFor($sponsorship, Consent::TYPE_STORY);

    expect($sponsorship->fresh()->canReceiveUpdate())->toBeTrue();
});

it('re-reads the consent every time rather than remembering a yes', function () {
    /*
     * A sponsorship that cached "yes" from two years ago would keep sending
     * photographs of a child whose guardian has since said no. The whole point
     * of an expiry is that something checks it.
     */
    $sponsorship = Sponsorship::factory()->cleared()->create();
    $consent = consentFor($sponsorship, Consent::TYPE_STORY);

    expect($sponsorship->fresh()->canReceiveUpdate())->toBeTrue();

    $consent->forceFill(['revoked_at' => now()])->save();

    expect($sponsorship->fresh()->canReceiveUpdate())->toBeFalse();
});

it('treats a photograph as a separate disclosure from a written update', function () {
    // A note about school progress and a photograph of a child sent to a
    // stranger's inbox are not the same thing.
    $sponsorship = Sponsorship::factory()->cleared(photographs: false)->create();
    consentFor($sponsorship, Consent::TYPE_STORY);
    consentFor($sponsorship, Consent::TYPE_PHOTO);

    expect($sponsorship->fresh()->canReceiveUpdate(withPhotograph: false))->toBeTrue()
        ->and($sponsorship->fresh()->canReceiveUpdate(withPhotograph: true))->toBeFalse();
});

it('needs a photography consent as well as permission', function () {
    $sponsorship = Sponsorship::factory()->cleared(photographs: true)->create();
    consentFor($sponsorship, Consent::TYPE_STORY);

    expect($sponsorship->fresh()->updateRejectionReason(withPhotograph: true))
        ->toContain('no current photography consent');
});

// ── What the sponsor actually learns ────────────────────────────────────────

it('generalises the child so a sponsor cannot find them', function () {
    /*
     * "The child you sponsor, aged 6-12, in Tamale district" is enough to feel
     * connected to and not enough to locate. A full name plus a birthday plus a
     * community, in a small population, is one child.
     */
    $sponsorship = Sponsorship::factory()->cleared()->create();
    $sponsorship->beneficiary->forceFill([
        'full_name' => 'Ama Serwaa Mensah',
        'date_of_birth' => now()->subYears(9)->toDateString(),
        'community' => 'Kpalsi',
        'district' => 'Tamale',
        'region' => 'Northern',
    ])->save();

    $profile = $sponsorship->fresh()->childProfileForSponsor();

    expect($profile['name'])->toBe('The child you sponsor')
        ->and($profile)->not->toHaveKey('community')
        // A band, never a birthday.
        ->and($profile['age'])->toContain('-')
        ->and($profile['district'])->toBe('Tamale');
});

it('gives a first name only, and only where that was agreed', function () {
    $sponsorship = Sponsorship::factory()->cleared(givenName: true)->create();
    $sponsorship->beneficiary->forceFill(['full_name' => 'Ama Serwaa Mensah'])->save();

    expect($sponsorship->fresh()->childProfileForSponsor()['name'])->toBe('Ama');
});

it('has nowhere to record a route from sponsor to child', function () {
    // Absent, not disabled. Correspondence goes through staff or it does not
    // happen, and the missing columns are what make that true.
    $columns = Schema::getColumnListing('sponsorships');

    expect($columns)->not->toContain('child_phone')
        ->and($columns)->not->toContain('child_address')
        ->and($columns)->not->toContain('sponsor_message');
});

// ── Updates ─────────────────────────────────────────────────────────────────

it('will not let the author of an update approve it', function () {
    // The person who wrote it is the person who will not see the school badge
    // in the photograph.
    $sponsorship = Sponsorship::factory()->cleared()->create();

    $update = SponsorshipUpdate::create([
        'sponsorship_id' => $sponsorship->id,
        'title' => 'Ama started Primary 4',
        'body' => 'She is doing well.',
        'created_by' => $this->fieldWorker->id,
    ]);

    expect(fn () => $update->approve($this->fieldWorker))
        ->toThrow(RuntimeException::class, 'cannot be approved by the person who wrote it');
});

it('refuses to send an update nobody has read', function () {
    $sponsorship = Sponsorship::factory()->cleared()->create();
    consentFor($sponsorship, Consent::TYPE_STORY);

    $update = SponsorshipUpdate::create([
        'sponsorship_id' => $sponsorship->id,
        'title' => 'An update', 'body' => 'Some news.',
        'created_by' => $this->fieldWorker->id,
    ]);

    expect($update->canBeSent())->toBeFalse()
        ->and($update->sendRejectionReason())->toContain('not been read and approved');
});

it('records which consent an update was sent under', function () {
    // So that in two years "we had permission to send that photograph" is a row
    // pointing at a signed form, not a recollection.
    $sponsorship = Sponsorship::factory()->cleared()->create();
    $consent = consentFor($sponsorship, Consent::TYPE_STORY);

    $update = SponsorshipUpdate::create([
        'sponsorship_id' => $sponsorship->id,
        'title' => 'An update', 'body' => 'Some news.',
        'created_by' => $this->fieldWorker->id,
    ]);

    $update->approve($this->safeguardingLead);
    $update->fresh()->markSent();

    expect($update->fresh()->consent_id)->toBe($consent->id)
        ->and($update->fresh()->sent_at)->not->toBeNull();
});

it('stops everything the moment a safeguarding concern is raised', function () {
    // Immediate and without a finding, like suspending a volunteer. Whatever
    // the conclusion, information about a child stops flowing to an adult now.
    $sponsorship = Sponsorship::factory()->cleared(photographs: true, givenName: true)->create();
    consentFor($sponsorship, Consent::TYPE_STORY);

    $sponsorship->fresh()->endForSafeguarding('Concern raised about the sponsor.');

    $fresh = $sponsorship->fresh();

    expect($fresh->status)->toBe(Sponsorship::STATUS_ENDED)
        ->and($fresh->may_receive_updates)->toBeFalse()
        ->and($fresh->may_know_given_name)->toBeFalse()
        ->and($fresh->canReceiveUpdate())->toBeFalse();
});

it('cannot be matched while sponsorship is switched off', function () {
    config()->set('features.sponsorship', false);
    app(Features::class)->flush();

    $sponsorship = Sponsorship::factory()->create();

    expect(fn () => $sponsorship->match($this->safeguardingLead))
        ->toThrow(RuntimeException::class, 'switched off');
});

// ── Event tickets ───────────────────────────────────────────────────────────

it('does not sell a ticket while ticketing is switched off', function () {
    // A flag that only gates creation gates nothing.
    $ticket = EventTicket::factory()->create();

    expect($ticket->canBePurchased())->toBeFalse()
        ->and($ticket->purchaseRejectionReason())->toContain('not switched on');
});

it('caps how many one person can take', function () {
    // Without it, one supporter books forty places in a hall that seats a
    // hundred and the event looks full while the room is empty.
    config()->set('features.event_ticketing', true);
    app(Features::class)->flush();

    $ticket = EventTicket::factory()->create(['max_per_order' => 4]);

    expect($ticket->canBePurchased(4))->toBeTrue()
        ->and($ticket->purchaseRejectionReason(5))->toContain('maximum of 4');
});

it('treats a free ticket as a real ticket', function () {
    config()->set('features.event_ticketing', true);
    app(Features::class)->flush();

    $ticket = EventTicket::factory()->free()->create();

    expect($ticket->isFree())->toBeTrue()
        ->and($ticket->canBePurchased())->toBeTrue();
});

it('stops selling what is not there', function () {
    config()->set('features.event_ticketing', true);
    app(Features::class)->flush();

    $ticket = EventTicket::factory()->create(['quantity' => 10, 'sold' => 10]);

    expect($ticket->isSoldOut())->toBeTrue()
        ->and($ticket->purchaseRejectionReason())->toContain('sold out');
});

// ── Product reviews ─────────────────────────────────────────────────────────

it('holds a review back until somebody has read it', function () {
    // Post-moderation means the offensive review is on the foundation's website
    // until somebody notices.
    $review = ProductReview::factory()->create();

    expect($review->status)->toBe(ProductReview::STATUS_PENDING)
        ->and($review->isPublished())->toBeFalse();
});

it('keeps an unmoderated review out of the average', function () {
    /*
     * Averaging the queue would let anybody move a product's rating by
     * submitting, whether or not their words were ever published — the whole
     * attack, minus the offensive text.
     */
    $product = Product::factory()->create();

    ProductReview::factory()->create(['product_id' => $product->id, 'rating' => 5]);
    $approved = ProductReview::factory()->create(['product_id' => $product->id, 'rating' => 3]);
    $approved->approve($this->safeguardingLead);

    expect(ProductReview::averageFor($product))->toBe(3.0);
});

it('derives verified from an order rather than storing a badge', function () {
    // A stored flag is a flag somebody can set.
    $review = ProductReview::factory()->create();

    expect($review->isVerifiedPurchase())->toBeFalse()
        ->and(Schema::getColumnListing('product_reviews'))->not->toContain('is_verified');
});

it('refuses a rating outside one to five', function () {
    expect(fn () => ProductReview::factory()->create(['rating' => 9]))
        ->toThrow(InvalidArgumentException::class);
});

it('needs a reason to reject a review', function () {
    // A customer who asks why theirs was not published deserves an answer.
    $review = ProductReview::factory()->create();

    expect(fn () => $review->reject($this->safeguardingLead, ' '))
        ->toThrow(InvalidArgumentException::class);
});

it('needs the moderate permission to publish one', function () {
    // The permission that has existed since Module 1 over a table that did not.
    $review = ProductReview::factory()->create();
    $nobody = User::factory()->staff()->create();

    expect(fn () => $review->approve($nobody))
        ->toThrow(RuntimeException::class, 'reviews.moderate');
});
