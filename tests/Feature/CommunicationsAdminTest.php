<?php

declare(strict_types=1);

use App\Communications\CampaignComposer;
use App\Communications\CampaignSender;
use App\Communications\EmailTracking;
use App\Filament\Resources\EmailTemplates\Pages\EditEmailTemplate;
use App\Filament\Resources\EmailTemplates\Pages\ListEmailTemplates;
use App\Filament\Resources\NewsletterCampaigns\Pages\CreateNewsletterCampaign;
use App\Filament\Resources\NewsletterCampaigns\Pages\EditNewsletterCampaign;
use App\Filament\Resources\SmsTemplates\Pages\EditSmsTemplate;
use App\Filament\Resources\SmsTemplates\Pages\ListSmsTemplates;
use App\Filament\Resources\Subscribers\Pages\ListSubscribers;
use App\Mail\RenderedMessage;
use App\Models\AuditLog;
use App\Models\Cause;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Newsletter;
use App\Models\NewsletterCampaign;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Models\Subscriber;
use App\Models\Suppression;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\CauseSeeder;
use Database\Seeders\DivisionSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\NewsletterSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 10 — communications in the admin
|--------------------------------------------------------------------------
|
| THE ENGINE EXISTED SINCE PHASE 3 AND NO SCREEN REACHED IT. Templates were
| seeded and editable by nobody; campaigns could be built, tested, approved
| and sent from a test and from nowhere else; a scheduled campaign was never
| picked up by anything. This is the screen, and the cron line.
|
| A PREVIEW IS THE REAL LAYOUT WITH SAMPLE VALUES. A test send is the real
| path. A campaign that has not been tested and approved does not go.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(DivisionSeeder::class);
    $this->seed(CauseSeeder::class);
    $this->seed(MessageTemplateSeeder::class);
    $this->seed(NewsletterSeeder::class);

    setting()->set('general.legal_name', "St. Cecilia's Greater Hope Foundations");
    config(['communications.newsletter.require_test_send' => true, 'communications.newsletter.require_approval' => true]);

    BasePolicy::forgetKnownPermissions();
    ThemeTokens::flush();
    app(Settings::class)->flush();
});

/** @param list<string> $extra */
function commsUser(array $extra = [], string $email = 'editor@example.test'): User
{
    $user = User::factory()->staff()->withTwoFactor()->create(['email' => $email, 'phone' => '+233241234567']);
    $user->givePermissionTo(array_merge(['templates.email.manage', 'templates.sms.manage', 'newsletter.view', 'newsletter.draft'], $extra));

    return $user;
}

// ── Templates ───────────────────────────────────────────────────────────────

it('lists every seeded template and lets an editor reword one, refusing a variable nobody provides', function () {
    $this->actingAs(commsUser());
    $template = EmailTemplate::where('key', 'donation.receipt')->firstOrFail();

    Livewire::test(ListEmailTemplates::class)->assertOk()->searchTable('donation.receipt')->assertSee('donation.receipt');

    Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
        ->assertOk()
        ->fillForm(['subject' => 'Thank you, {{donor_name}} — {{nonsense}}'])
        ->call('save')
        ->assertHasNoFormErrors(['subject']);

    Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm(['body_html' => '<p>Dear {{donor_name}}, your gift of {{nonsense}}.</p>'])
        ->call('save')
        ->assertHasFormErrors(['body_html']);

    Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm(['body_html' => '<p>Dear {{donor_name}}, thank you for {{amount}}. {{site_name}}</p>'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($template->fresh()->body_html)->toContain('thank you for {{amount}}')
        ->and($template->fresh()->updated_by)->toBe(auth()->id());
});

it('previews a template inside the real layout with sample values, branded from the theme', function () {
    $this->actingAs(commsUser());
    $template = EmailTemplate::where('key', 'donation.receipt')->firstOrFail();

    $html = $template->preview();

    expect($html)->toContain('Ama Mensah')
        ->toContain('GH₵ 50.00')
        ->toContain('prefers-color-scheme: dark')
        ->toContain('Greater Hope Foundations')
        ->not->toContain('{{donor_name}}');

    Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
        ->mountAction('preview')
        ->assertActionMounted('preview');
});

it('sends a test of a template to the editor through the real path', function () {
    $this->actingAs(commsUser());
    $template = EmailTemplate::where('key', 'order.confirmation')->firstOrFail();

    Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
        ->callAction('sendTest')
        ->assertNotified();

    $log = EmailLog::where('template_key', 'order.confirmation')->first();

    expect($log)->not->toBeNull()
        ->and($log->to_address)->toBe('editor@example.test')
        ->and($log->status)->toBe(EmailLog::STATUS_SENT)
        ->and($log->subject)->toContain('SCGHF-O-');
});

it('meters an SMS as it is edited and refuses one over its budget', function () {
    $this->actingAs(commsUser());
    $template = SmsTemplate::where('key', 'order.shipped')->firstOrFail();

    Livewire::test(ListSmsTemplates::class)->assertOk()->searchTable('order.shipped')->assertSee('order.shipped');

    Livewire::test(EditSmsTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm(['body' => 'Order {{order_reference}} is with {{courier}}. GH₵ shows the cost.'])
        ->assertSee('forces the expensive encoding');

    Livewire::test(EditSmsTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm(['body' => str_repeat('Your order is on its way and we are delighted. ', 12).'{{order_reference}}'])
        ->call('save')
        ->assertNotified();

    expect($template->fresh()->body)->not->toContain('delighted');

    Livewire::test(EditSmsTemplate::class, ['record' => $template->getRouteKey()])
        ->callAction('sendTest', ['to' => '0241234567']);

    expect(SmsLog::where('template_key', 'order.shipped')->where('to_number', '+233241234567')->exists())->toBeTrue();
});

// ── Campaigns ───────────────────────────────────────────────────────────────

it('compiles blocks into email-safe HTML and plain text, with the appeal drawn live', function () {
    $cause = Cause::first();
    $cause->forceFill(['goal_minor' => 100_000])->save();

    $compiled = app(CampaignComposer::class)->compile([
        ['type' => 'heading', 'data' => ['text' => 'News from Bongo']],
        ['type' => 'paragraph', 'data' => ['text' => "The borehole is dug.\n\nThank you."]],
        ['type' => 'button', 'data' => ['label' => 'Read more', 'url' => 'https://example.test/news']],
        ['type' => 'divider'],
        ['type' => 'appeal', 'data' => ['cause_id' => $cause->id]],
    ]);

    expect($compiled['html'])->toContain('<h2')->toContain('News from Bongo')
        ->toContain('<p style="margin:0 0 16px;">The borehole is dug.</p>')
        ->toContain('href="https://example.test/news"')
        ->toContain($cause->title)
        ->toContain('raised of GH₵ 1,000.00')
        ->not->toContain('<script')
        ->and($compiled['text'])->toContain('NEWS FROM BONGO')->toContain('Read more: https://example.test/news')->toContain($cause->title);
});

it('takes a campaign from composed to scheduled through the screen, with the gates in the way', function () {
    $drafter = commsUser();
    $this->actingAs($drafter);
    Subscriber::factory()->confirmed()->count(3)->create(['topics' => ['impact']]);
    $newsletter = Newsletter::where('topic', 'impact')->firstOrFail();

    Livewire::test(CreateNewsletterCampaign::class)
        ->fillForm([
            'title' => 'September update',
            'newsletter_id' => $newsletter->id,
            'subject' => 'What your gifts did in September',
            'blocks' => [
                ['type' => 'heading', 'data' => ['text' => 'Hello']],
                ['type' => 'paragraph', 'data' => ['text' => 'Thank you for standing with us.']],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $campaign = NewsletterCampaign::firstOrFail();
    expect($campaign->body_html)->toContain('Thank you for standing with us.');

    $page = Livewire::test(EditNewsletterCampaign::class, ['record' => $campaign->getRouteKey()]);

    // A drafter cannot approve or schedule.
    $page->assertActionHidden('approve')->assertActionHidden('schedule');

    $page->callAction('build')->assertNotified('3 recipients');
    $page->callAction('sendTest', ['to' => 'editor@example.test'])->assertNotified();
    expect($campaign->fresh()->test_sent_at)->not->toBeNull();

    $sender = commsUser(['newsletter.send'], 'sender@example.test');
    $this->actingAs($sender);

    Livewire::test(EditNewsletterCampaign::class, ['record' => $campaign->getRouteKey()])
        ->callAction('approve')
        ->assertNotified('Approved.')
        ->callAction('schedule', ['scheduled_for' => now()->addHour()->toDateTimeString()])
        ->assertNotified();

    $campaign->refresh();

    expect($campaign->status)->toBe(NewsletterCampaign::STATUS_SCHEDULED)
        ->and($campaign->approved_by)->toBe($sender->id)
        ->and(AuditLog::where('event', 'newsletter.scheduled')->exists())->toBeTrue();

    // Editing the body withdraws the approval.
    Livewire::test(EditNewsletterCampaign::class, ['record' => $campaign->getRouteKey()])
        ->fillForm(['subject' => 'A different subject'])
        ->call('save');

    expect($campaign->fresh()->approved_at)->toBeNull();
});

it('sends a scheduled campaign from the cron, in batches, and marks it sent', function () {
    Mail::fake();
    Subscriber::factory()->confirmed()->count(5)->create(['topics' => ['impact']]);
    $newsletter = Newsletter::where('topic', 'impact')->firstOrFail();
    $campaign = NewsletterCampaign::factory()->create(['newsletter_id' => $newsletter->id, 'scheduled_for' => now()->subMinute()]);

    app(CampaignSender::class)->build($campaign);
    app(CampaignSender::class)->sendTest($campaign, 'editor@example.test');
    $campaign->fresh()->approve(commsUser(['newsletter.send'], 'sender@example.test'));
    $campaign->forceFill(['status' => NewsletterCampaign::STATUS_SCHEDULED])->save();

    // Not yet due: nothing.
    $campaign->forceFill(['scheduled_for' => now()->addHour()])->save();
    $this->artisan('scghf:send-campaigns')->assertSuccessful();
    expect($campaign->fresh()->sent_count)->toBe(0);

    $campaign->forceFill(['scheduled_for' => now()->subMinute()])->save();
    $this->artisan('scghf:send-campaigns --limit=2')->assertSuccessful();
    expect($campaign->fresh()->sent_count)->toBe(2)->and($campaign->fresh()->status)->toBe(NewsletterCampaign::STATUS_SENDING);

    $this->artisan('scghf:send-campaigns')->assertSuccessful();
    expect($campaign->fresh()->sent_count)->toBe(5)->and($campaign->fresh()->status)->toBe(NewsletterCampaign::STATUS_SENT);

    // Every copy carries the preference-centre link as well as the unsubscribe.
    Mail::assertSent(RenderedMessage::class, fn (RenderedMessage $m): bool => str_contains((string) $m->unsubscribeUrl, '/newsletter/unsubscribe/')
        && str_contains((string) $m->preferencesUrl, '/newsletter/preferences/'));
});

it('marks a scheduled campaign failed with the reason when its gate fails', function () {
    $newsletter = Newsletter::where('topic', 'impact')->firstOrFail();
    $campaign = NewsletterCampaign::factory()->create(['newsletter_id' => $newsletter->id, 'status' => NewsletterCampaign::STATUS_SCHEDULED, 'scheduled_for' => now()->subMinute()]);

    $this->artisan('scghf:send-campaigns')->assertSuccessful();

    expect($campaign->fresh()->status)->toBe(NewsletterCampaign::STATUS_FAILED)
        ->and($campaign->fresh()->failure_reason)->toContain('test');
});

// ── The preference centre ───────────────────────────────────────────────────

it('lets a subscriber choose topics, stop everything, or come back, with no account', function () {
    $subscriber = Subscriber::factory()->confirmed()->create(['topics' => null]);
    $token = $subscriber->unsubscribe_token;

    $this->get(route('newsletter.preferences', $token))->assertOk()->assertSee('Impact');

    $this->post(route('newsletter.preferences.update', $token), ['topics' => ['appeals']])->assertRedirect();
    expect($subscriber->fresh()->topics)->toBe(['appeals']);

    $this->post(route('newsletter.preferences.update', $token), ['stop' => '1'])->assertRedirect();
    expect($subscriber->fresh()->status)->toBe(Subscriber::STATUS_UNSUBSCRIBED);

    $this->post(route('newsletter.preferences.update', $token), ['topics' => ['impact']])->assertRedirect();
    expect($subscriber->fresh()->status)->toBe(Subscriber::STATUS_CONFIRMED)
        ->and($subscriber->fresh()->consent_text)->toContain('preference centre');

    $this->get(route('newsletter.preferences', 'not-a-token'))->assertOk()->assertSee('no longer valid');
});

// ── Tracking ────────────────────────────────────────────────────────────────

it('tracks opens and clicks only when switched on, and only on marketing mail', function () {
    config(['communications.tracking.opens' => true, 'communications.tracking.clicks' => true]);

    $marketing = EmailTemplate::where('key', 'newsletter.campaign')->firstOrFail();
    $transactional = EmailTemplate::where('key', 'donation.receipt')->firstOrFail();
    $log = EmailLog::factory()->create(['template_key' => 'newsletter.campaign']);

    $tracking = app(EmailTracking::class);
    $html = '<p><a href="https://example.test/appeal">Give</a> <a href="'.url('/newsletter/unsubscribe/abc').'">Stop</a></p>';

    $instrumented = $tracking->instrument($html, $log, $marketing);
    expect($instrumented)->toContain('/t/c/'.$log->ulid)->toContain('/t/o/'.$log->ulid)
        ->toContain('href="'.url('/newsletter/unsubscribe/abc').'"');

    expect($tracking->instrument($html, $log, $transactional))->toBe($html);

    config(['communications.tracking.opens' => false, 'communications.tracking.clicks' => false]);
    expect($tracking->instrument($html, $log, $marketing))->toBe($html);

    // The pixel counts; the click counts and redirects only when signed.
    $this->get(route('track.open', $log->ulid))->assertOk()->assertHeader('content-type', 'image/gif');
    expect($log->fresh()->open_count)->toBe(1)->and($log->fresh()->opened_at)->not->toBeNull();

    $this->get(route('track.click', ['log' => $log->ulid, 'to' => 'https://evil.test']))->assertNotFound();
    $this->get($tracking->clickUrl($log, 'https://example.test/appeal'))->assertRedirect('https://example.test/appeal');
    expect($log->fresh()->click_count)->toBe(1);
});

// ── Subscribers ─────────────────────────────────────────────────────────────

it('shows the list with its consent evidence and erases with a suppression', function () {
    $this->actingAs(commsUser());
    $subscriber = Subscriber::factory()->confirmed()->create(['email' => 'ama@example.test', 'consent_ip' => '41.66.1.1', 'consent_at' => now()]);

    Livewire::test(ListSubscribers::class)
        ->assertOk()
        ->searchTable('ama@example.test')
        ->assertSee('ama@example.test')
        ->assertSee('41.66.1.1')
        ->callAction(TestAction::make('delete')->table($subscriber));

    expect(Subscriber::withTrashed()->find($subscriber->id)->trashed())->toBeTrue()
        ->and(Suppression::where('address', 'ama@example.test')->where('reason', Suppression::REASON_ERASURE)->exists())->toBeTrue()
        ->and(AuditLog::where('event', 'erasure.completed')->exists())->toBeTrue();
});
