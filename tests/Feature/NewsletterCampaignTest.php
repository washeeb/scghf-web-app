<?php

declare(strict_types=1);

use App\Communications\CampaignSender;
use App\Mail\RenderedMessage;
use App\Models\CampaignRecipient;
use App\Models\Newsletter;
use App\Models\NewsletterCampaign;
use App\Models\Subscriber;
use App\Models\Suppression;
use App\Models\User;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Newsletters and campaigns
|--------------------------------------------------------------------------
|
| The hosting constraint is the design constraint here, and it is worth stating
| plainly: this host sends a couple of hundred messages an hour, so a campaign
| to two thousand subscribers takes most of a day.
|
| Everything below follows from that. Consent is re-read per message, because
| hours pass between building the list and sending the last of it. A campaign
| can be paused, because there are still 1,600 messages left to stop. And a
| campaign cannot be started without a test send and an approval, because none
| of it can be recalled.
|
*/

beforeEach(function () {
    Mail::fake();
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(MessageTemplateSeeder::class);

    $this->newsletter = Newsletter::factory()->create(['topic' => 'impact']);
    $this->campaign = NewsletterCampaign::factory()->create([
        'newsletter_id' => $this->newsletter->id,
    ]);
});

/** Somebody who may lawfully be sent a campaign. */
function confirmedSubscriber(array $attributes = []): Subscriber
{
    return Subscriber::factory()->confirmed()->create($attributes)->fresh();
}

/** Get a campaign through both gates, the way a person would. */
function makeSendable(NewsletterCampaign $campaign): NewsletterCampaign
{
    app(CampaignSender::class)->build($campaign);
    app(CampaignSender::class)->sendTest($campaign, 'editor@example.com');

    $approver = User::factory()->staff()->create();
    $approver->assignRole('Super Admin');

    $campaign->fresh()->approve($approver->fresh());

    return $campaign->fresh();
}

// ── The gates ───────────────────────────────────────────────────────────────

it('refuses to send a campaign nobody has tested', function () {
    // A broken merge tag or a dead link should be found by one person, not by
    // two thousand. A campaign cannot be recalled.
    expect($this->campaign->sendRejectionReason())->toContain('Send a test');
});

it('refuses to send a campaign nobody has approved', function () {
    app(CampaignSender::class)->sendTest($this->campaign, 'editor@example.com');

    expect($this->campaign->fresh()->sendRejectionReason())->toContain('not been approved');
});

it('refuses approval from somebody who may only draft', function () {
    // Drafting and sending are separate permissions. The check lives on the
    // model so a console command or a future API route cannot walk past it.
    $editor = User::factory()->staff()->create();
    $editor->assignRole('Content Editor');

    expect(fn () => $this->campaign->approve($editor->fresh()))
        ->toThrow(RuntimeException::class, 'newsletter.send');
});

it('withdraws approval when the content changes afterwards', function () {
    // What was approved is not what would go. Correcting a typo after approval
    // is normal; that correction going out under somebody else's sign-off is not.
    confirmedSubscriber();
    $campaign = makeSendable($this->campaign);

    expect($campaign->approved_at)->not->toBeNull();

    $campaign->update(['body_html' => '<p>Something quite different.</p>']);

    expect($campaign->fresh()->approved_at)->toBeNull()
        ->and($campaign->fresh()->test_sent_at)->toBeNull();
});

it('refuses to send to an empty list', function () {
    app(CampaignSender::class)->sendTest($this->campaign, 'editor@example.com');

    $approver = User::factory()->staff()->create();
    $approver->assignRole('Super Admin');
    $this->campaign->approve($approver->fresh());

    expect($this->campaign->fresh()->sendRejectionReason())->toContain('recipient list');
});

// ── Building the list ───────────────────────────────────────────────────────

it('builds from confirmed subscribers only', function () {
    confirmedSubscriber();
    Subscriber::factory()->create();               // pending — never confirmed
    Subscriber::factory()->unsubscribed()->create();

    $count = app(CampaignSender::class)->build($this->campaign);

    expect($count)->toBe(1);
});

it('honours the topic somebody actually asked for', function () {
    confirmedSubscriber(['topics' => ['impact']]);
    confirmedSubscriber(['topics' => ['appeals']]);

    expect(app(CampaignSender::class)->build($this->campaign))->toBe(1);
});

it('includes subscribers who were never asked about topics', function () {
    // They signed up through a footer form before the lists existed. Excluding
    // them would silently drop most of an existing list.
    confirmedSubscriber(['topics' => null]);

    expect(app(CampaignSender::class)->build($this->campaign))->toBe(1);
});

it('sends one copy to somebody subscribed twice', function () {
    confirmedSubscriber(['email' => 'ama@example.com']);

    app(CampaignSender::class)->build($this->campaign);
    app(CampaignSender::class)->build($this->campaign);

    expect($this->campaign->fresh()->recipient_count)->toBe(1);
});

it('picks up new subscribers on a rebuild without resending to the old ones', function () {
    // A send takes hours here, so rebuilding mid-flight is a real thing to want.
    confirmedSubscriber();
    $sender = app(CampaignSender::class);
    $sender->build($this->campaign);

    $campaign = makeSendable($this->campaign);
    $sender->sendBatch($campaign, 10);

    confirmedSubscriber();
    $sender->build($campaign->fresh());

    $campaign = $campaign->fresh();

    expect($campaign->recipient_count)->toBe(2)
        ->and($campaign->sent_count)->toBe(1);
});

// ── Sending ─────────────────────────────────────────────────────────────────

it('sends a campaign to its list', function () {
    confirmedSubscriber();
    confirmedSubscriber();

    $campaign = makeSendable($this->campaign);
    $summary = app(CampaignSender::class)->sendBatch($campaign, 10);

    expect($summary['sent'])->toBe(2)
        ->and($summary['remaining'])->toBe(0)
        ->and($campaign->fresh()->status)->toBe(NewsletterCampaign::STATUS_SENT);
});

it('re-reads consent at the moment each message goes, not when the list was built', function () {
    /*
     * The whole reason this check is not at build time. On a host sending two
     * hundred an hour, somebody who unsubscribes in hour three of a nine-hour
     * send has unsubscribed — and a list that was accurate when it was made is
     * not accurate any more.
     */
    $staying = confirmedSubscriber();
    $leaving = confirmedSubscriber();

    $campaign = makeSendable($this->campaign);

    $leaving->unsubscribe('Changed my mind.');

    $summary = app(CampaignSender::class)->sendBatch($campaign, 10);

    expect($summary['sent'])->toBe(1)
        ->and($summary['skipped'])->toBe(1);

    $skipped = CampaignRecipient::firstWhere('subscriber_id', $leaving->id);

    expect($skipped->skip_reason)->toBe(CampaignRecipient::SKIP_UNSUBSCRIBED);

    unset($staying);
});

it('skips somebody suppressed between the build and the send', function () {
    $subscriber = confirmedSubscriber();
    $campaign = makeSendable($this->campaign);

    Suppression::record(
        Suppression::CHANNEL_EMAIL,
        $subscriber->email,
        Suppression::REASON_COMPLAINT,
        source: 'webhook',
    );

    $summary = app(CampaignSender::class)->sendBatch($campaign, 10);

    expect($summary['skipped'])->toBe(1)
        ->and(CampaignRecipient::first()->skip_reason)->toBe(CampaignRecipient::SKIP_SUPPRESSED);
});

it('records why somebody did not receive it rather than deleting the row', function () {
    // "Why did I not get the newsletter?" should have an answer.
    $subscriber = confirmedSubscriber();
    $campaign = makeSendable($this->campaign);
    $subscriber->unsubscribe();

    app(CampaignSender::class)->sendBatch($campaign, 10);

    expect(CampaignRecipient::count())->toBe(1)
        ->and(CampaignRecipient::first()->status)->toBe(CampaignRecipient::STATUS_SKIPPED);
});

it('sends in batches, so the host limit is respected across cron runs', function () {
    foreach (range(1, 5) as $ignored) {
        confirmedSubscriber();
    }

    $campaign = makeSendable($this->campaign);
    $sender = app(CampaignSender::class);

    $first = $sender->sendBatch($campaign, 2);

    expect($first['sent'])->toBe(2)
        ->and($first['remaining'])->toBe(3)
        ->and($campaign->fresh()->status)->toBe(NewsletterCampaign::STATUS_SENDING);

    $sender->sendBatch($campaign->fresh(), 10);

    expect($campaign->fresh()->status)->toBe(NewsletterCampaign::STATUS_SENT);
});

it('stops a send in flight when it is paused', function () {
    // Only meaningful because a send takes hours. Noticing a mistake forty
    // minutes in should stop the rest.
    foreach (range(1, 4) as $ignored) {
        confirmedSubscriber();
    }

    $campaign = makeSendable($this->campaign);
    $sender = app(CampaignSender::class);

    $sender->sendBatch($campaign, 1);
    $campaign->fresh()->pause('Wrong link in the second paragraph.');

    $summary = $sender->sendBatch($campaign->fresh(), 10);

    expect($summary['sent'])->toBe(0)
        ->and($campaign->fresh()->sent_count)->toBe(1);
});

it('skips the rest when a campaign is cancelled', function () {
    foreach (range(1, 3) as $ignored) {
        confirmedSubscriber();
    }

    $campaign = makeSendable($this->campaign);
    $campaign->cancel('Sent to the wrong list.');

    expect($campaign->fresh()->recipients()->pending()->count())->toBe(0);
});

it('reports how far through a long send is', function () {
    foreach (range(1, 4) as $ignored) {
        confirmedSubscriber();
    }

    $campaign = makeSendable($this->campaign);
    app(CampaignSender::class)->sendBatch($campaign, 1);

    expect($campaign->fresh()->progressPercent())->toBe(25);
});

it('every campaign email carries a one-click unsubscribe link', function () {
    $subscriber = confirmedSubscriber();
    $campaign = makeSendable($this->campaign);

    app(CampaignSender::class)->sendBatch($campaign, 10);

    $log = CampaignRecipient::first()->emailLog;

    expect($log->status)->toBe('sent');

    Mail::assertSent(RenderedMessage::class, function ($mail) use ($subscriber) {
        return str_contains((string) $mail->unsubscribeUrl, (string) $subscriber->unsubscribe_token);
    });
});
