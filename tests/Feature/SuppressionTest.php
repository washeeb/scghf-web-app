<?php

declare(strict_types=1);

use App\Communications\MessageDispatcher;
use App\Communications\SendThrottle;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\ScheduledMessage;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Suppression, logging and the outbox
|--------------------------------------------------------------------------
|
| Blueprint risk DEL-4. Bounces and complaints that are not suppressed degrade
| the sending domain until mail stops arriving at all — and the first casualty
| is not the newsletter, it is the donation receipt.
|
| The tests below are about the two ways of getting this wrong, which point in
| opposite directions:
|
|   - keep mailing somebody who complained, and the domain is blocklisted
|   - stop sending receipts to somebody who merely unsubscribed from appeals,
|     and they are left with no record of a gift they made
|
| Hence a suppression has a SCOPE, and hence a refusal is still logged.
|
*/

beforeEach(function () {
    Mail::fake();
});

// ── Scope ───────────────────────────────────────────────────────────────────

it('lets a receipt through to somebody who unsubscribed from appeals', function () {
    Suppression::record(
        Suppression::CHANNEL_EMAIL,
        'donor@example.com',
        Suppression::REASON_UNSUBSCRIBE,
    );

    expect(Suppression::blocks(Suppression::CHANNEL_EMAIL, 'donor@example.com', 'marketing'))->toBeTrue()
        ->and(Suppression::blocks(Suppression::CHANNEL_EMAIL, 'donor@example.com', 'transactional'))->toBeFalse();
});

it('stops everything, receipts included, after a hard bounce', function () {
    Suppression::record(
        Suppression::CHANNEL_EMAIL,
        'gone@example.com',
        Suppression::REASON_HARD_BOUNCE,
    );

    expect(Suppression::blocks(Suppression::CHANNEL_EMAIL, 'gone@example.com', 'transactional'))->toBeTrue();
});

it('normalises an address so the list cannot be walked past with different casing', function () {
    Suppression::record(Suppression::CHANNEL_EMAIL, 'Ama@Example.COM ', Suppression::REASON_COMPLAINT);

    expect(Suppression::blocks(Suppression::CHANNEL_EMAIL, 'ama@example.com', 'transactional'))->toBeTrue();
});

it('normalises a phone number the same way', function () {
    Suppression::record(Suppression::CHANNEL_SMS, '024 123 4567', Suppression::REASON_UNSUBSCRIBE);

    expect(Suppression::blocks(Suppression::CHANNEL_SMS, '+233241234567', 'marketing'))->toBeTrue();
});

// ── Strengthening ───────────────────────────────────────────────────────────

it('upgrades a marketing suppression to everything when the address bounces', function () {
    Suppression::record(Suppression::CHANNEL_EMAIL, 'x@example.com', Suppression::REASON_UNSUBSCRIBE);
    Suppression::record(Suppression::CHANNEL_EMAIL, 'x@example.com', Suppression::REASON_HARD_BOUNCE);

    $row = Suppression::firstWhere('address', 'x@example.com');

    expect(Suppression::count())->toBe(1)
        ->and($row->scope)->toBe(Suppression::SCOPE_ALL)
        ->and($row->occurrences)->toBe(2);
});

it('never weakens a suppression automatically', function () {
    // A newsletter unsubscribe arriving after a hard bounce must not re-enable
    // mail to a mailbox that does not exist.
    Suppression::record(Suppression::CHANNEL_EMAIL, 'y@example.com', Suppression::REASON_HARD_BOUNCE);
    Suppression::record(Suppression::CHANNEL_EMAIL, 'y@example.com', Suppression::REASON_UNSUBSCRIBE);

    expect(Suppression::firstWhere('address', 'y@example.com')->scope)->toBe(Suppression::SCOPE_ALL);
});

it('records a repeated bounce once, not once per delivery attempt', function () {
    // Webhooks retry. A list that grows a row per retry is a list nobody reads.
    foreach (range(1, 3) as $ignored) {
        Suppression::record(Suppression::CHANNEL_EMAIL, 'z@example.com', Suppression::REASON_HARD_BOUNCE);
    }

    expect(Suppression::count())->toBe(1)
        ->and(Suppression::firstWhere('address', 'z@example.com')->occurrences)->toBe(3);
});

// ── Release ─────────────────────────────────────────────────────────────────

it('requires a person and a reason to release a suppression', function () {
    $suppression = Suppression::factory()->create();

    expect(fn () => $suppression->release(User::factory()->staff()->create(), ' '))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to release an address suppressed under an erasure request', function () {
    // Honouring the objection is exactly what the row is for.
    $suppression = Suppression::factory()->create(['reason' => Suppression::REASON_ERASURE]);

    expect(fn () => $suppression->release(User::factory()->staff()->create(), 'They asked us to'))
        ->toThrow(RuntimeException::class, 'Act 843');
});

it('re-suppresses a released address that fails again', function () {
    $suppression = Suppression::factory()->create(['address' => 'flaky@example.com']);
    $suppression->release(User::factory()->staff()->create(), 'Donor says the mailbox works now.');

    expect(Suppression::blocks(Suppression::CHANNEL_EMAIL, 'flaky@example.com', 'transactional'))->toBeFalse();

    Suppression::record(Suppression::CHANNEL_EMAIL, 'flaky@example.com', Suppression::REASON_HARD_BOUNCE);

    expect(Suppression::blocks(Suppression::CHANNEL_EMAIL, 'flaky@example.com', 'transactional'))->toBeTrue();
});

// ── The dispatcher ──────────────────────────────────────────────────────────

it('records a refusal instead of leaving no trace', function () {
    // The failure this module exists to prevent: a donor with no receipt, and a
    // foundation that believes it sent one.
    $template = EmailTemplate::factory()->create(['required_variables' => []]);
    Suppression::record(Suppression::CHANNEL_EMAIL, 'blocked@example.com', Suppression::REASON_HARD_BOUNCE);

    $log = app(MessageDispatcher::class)
        ->sendEmailNow($template->key, 'blocked@example.com', ['name' => 'Ama']);

    expect($log->status)->toBe(EmailLog::STATUS_SUPPRESSED)
        ->and($log->blocked_reason)->toContain('Hard bounce')
        ->and($log->needsAttention())->toBeTrue();

    Mail::assertNothingSent();
});

it('sends and logs a transactional email', function () {
    $template = EmailTemplate::factory()->create(['required_variables' => ['name']]);

    $log = app(MessageDispatcher::class)
        ->sendEmailNow($template->key, 'Donor@Example.com', ['name' => 'Ama', 'thing' => 'your gift']);

    expect($log->status)->toBe(EmailLog::STATUS_SENT)
        ->and($log->to_address)->toBe('donor@example.com')
        ->and($log->body_stored)->toBeTrue()
        ->and($log->body_html)->toContain('Ama');

    Mail::assertSentCount(1);
});

it('refuses to send a marketing email with no way off the list', function () {
    // Bulk mail with no unsubscribe generates the complaints that stop receipts
    // being delivered.
    $template = EmailTemplate::factory()->marketing()->create(['required_variables' => []]);

    expect(fn () => app(MessageDispatcher::class)->sendEmailNow($template->key, 'a@example.com'))
        ->toThrow(RuntimeException::class, 'unsubscribe');
});

it('does not store the body of a bulk message on every recipient row', function () {
    // Two thousand copies of the same 40KB newsletter is 80MB of a shared disk
    // quota, to record nothing the campaign does not already hold.
    $template = EmailTemplate::factory()->marketing()->create(['required_variables' => []]);

    $log = app(MessageDispatcher::class)->sendEmailNow(
        $template->key,
        'a@example.com',
        [],
        ['unsubscribe_url' => 'https://example.test/u/abc'],
    );

    expect($log->status)->toBe(EmailLog::STATUS_SENT)
        ->and($log->body_stored)->toBeFalse()
        ->and($log->body_html)->toBeNull();
});

it('costs and logs an SMS without sending one', function () {
    // SMS_DRIVER=log is the configured default because no provider exists yet.
    // It has to be informative, not merely inert.
    $template = SmsTemplate::factory()->create([
        'body' => 'Thank you for your gift of GHS {{amount}}.',
        'required_variables' => ['amount'],
    ]);

    $log = app(MessageDispatcher::class)
        ->sendSmsNow($template->key, '0241234567', ['amount' => '50.00']);

    expect($log->status)->toBe(SmsLog::STATUS_SENT)
        ->and($log->driver)->toBe('log')
        ->and($log->to_number)->toBe('+233241234567')
        ->and($log->network)->toBe('mtn')
        ->and($log->segments)->toBe(1)
        ->and($log->estimated_cost_minor)->toBeGreaterThan(0);
});

it('never reads a logged SMS as delivered', function () {
    // An unregistered sender ID is accepted by the provider and dropped by the
    // network. Assuming delivery is how that goes unnoticed for a month.
    $template = SmsTemplate::factory()->create(['required_variables' => []]);

    $log = app(MessageDispatcher::class)->sendSmsNow($template->key, '0241234567');

    expect($log->status)->toBe(SmsLog::STATUS_SENT)
        ->and($log->delivered_at)->toBeNull();
});

// ── The outbox ──────────────────────────────────────────────────────────────

it('gives a transactional message a higher priority than a marketing one', function () {
    $receipt = EmailTemplate::factory()->create();
    $appeal = EmailTemplate::factory()->marketing()->create();

    $dispatcher = app(MessageDispatcher::class);

    $queuedReceipt = $dispatcher->queueEmail($receipt->key, 'a@example.com');
    $queuedAppeal = $dispatcher->queueEmail($appeal->key, 'b@example.com');

    expect($queuedReceipt->priority)->toBeLessThan($queuedAppeal->priority);
});

it('gives a marketing message a shelf life and a receipt none', function () {
    // A backlog on this host is measured in days. "The event is tomorrow",
    // delivered three days late, is worse than nothing.
    $receipt = EmailTemplate::factory()->create();
    $appeal = EmailTemplate::factory()->marketing()->create();

    $dispatcher = app(MessageDispatcher::class);

    expect($dispatcher->queueEmail($receipt->key, 'a@example.com')->expires_at)->toBeNull()
        ->and($dispatcher->queueEmail($appeal->key, 'b@example.com')->expires_at)->not->toBeNull();
});

it('expires a message that passed its shelf life rather than sending it late', function () {
    $template = EmailTemplate::factory()->marketing()->create();

    $message = app(MessageDispatcher::class)->queueEmail($template->key, 'a@example.com', [], [
        'send_after' => now()->subDays(5),
        'expires_at' => now()->subDays(4),
    ]);

    $claimed = ScheduledMessage::claimBatch('worker-1');

    expect($claimed)->toHaveCount(0)
        ->and($message->fresh()->status)->toBe(ScheduledMessage::STATUS_EXPIRED)
        ->and($message->fresh()->last_error)->toContain('shelf life');
});

it('claims a message once, so two overlapping cron workers cannot both send it', function () {
    $template = EmailTemplate::factory()->create();
    app(MessageDispatcher::class)->queueEmail($template->key, 'a@example.com');

    $first = ScheduledMessage::claimBatch('worker-1');
    $second = ScheduledMessage::claimBatch('worker-2');

    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(0);
});

it('reclaims a message whose worker died', function () {
    $template = EmailTemplate::factory()->create();
    app(MessageDispatcher::class)->queueEmail($template->key, 'a@example.com');

    ScheduledMessage::claimBatch('worker-that-died');

    $this->travel(10)->minutes();

    expect(ScheduledMessage::claimBatch('worker-2'))->toHaveCount(1);
});

it('refuses to schedule the same message twice', function () {
    // A webhook replayed, or a retry after a timeout that had actually
    // succeeded, collides here instead of producing a second receipt.
    $template = EmailTemplate::factory()->create();
    $dispatcher = app(MessageDispatcher::class);

    $dispatcher->queueEmail($template->key, 'a@example.com', [], ['idempotency_key' => 'donation:123:receipt']);

    expect(fn () => $dispatcher->queueEmail($template->key, 'a@example.com', [], [
        'idempotency_key' => 'donation:123:receipt',
    ]))->toThrow(QueryException::class);
});

it('marks a queued message sent once it goes', function () {
    $template = EmailTemplate::factory()->create(['required_variables' => []]);
    $dispatcher = app(MessageDispatcher::class);

    $message = $dispatcher->queueEmail($template->key, 'a@example.com', ['name' => 'Ama']);
    $log = $dispatcher->deliver($message);

    expect($log->status)->toBe(EmailLog::STATUS_SENT)
        ->and($message->fresh()->status)->toBe(ScheduledMessage::STATUS_SENT);
});

it('does not retry a message the suppression list refused', function () {
    // The list will say the same thing in ten minutes. Retrying only writes the
    // refusal into the log three times.
    $template = EmailTemplate::factory()->create(['required_variables' => []]);
    Suppression::record(Suppression::CHANNEL_EMAIL, 'blocked@example.com', Suppression::REASON_COMPLAINT);

    $dispatcher = app(MessageDispatcher::class);
    $message = $dispatcher->queueEmail($template->key, 'blocked@example.com');
    $dispatcher->deliver($message);

    expect($message->fresh()->status)->toBe(ScheduledMessage::STATUS_SUPPRESSED);
});

// ── The throttle ────────────────────────────────────────────────────────────

it('counts what was actually sent rather than keeping a counter', function () {
    config()->set('communications.throttle.mail', ['per_minute' => 100, 'per_hour' => 10]);

    EmailLog::factory()->count(4)->create(['sent_at' => now()->subMinutes(5)]);

    // Outside the window, so it must not count against the allowance.
    EmailLog::factory()->create(['sent_at' => now()->subHours(2)]);

    expect(app(SendThrottle::class)->remaining('mail'))->toBe(6);
});

it('claims only as many as it may actually send', function () {
    // Claiming more than the allowance locks rows out of the next worker's
    // batch for the whole claim TTL, for nothing.
    config()->set('communications.throttle.mail', ['per_minute' => 100, 'per_hour' => 3]);
    config()->set('communications.scheduling.batch_size', 25);

    expect(app(SendThrottle::class)->batchSize('mail'))->toBe(3);
});

it('explains why nothing is going out rather than reporting zero', function () {
    config()->set('communications.throttle.mail', ['per_minute' => 0, 'per_hour' => 0]);

    expect(app(SendThrottle::class)->explain('mail'))->toContain("host's cap");
});
