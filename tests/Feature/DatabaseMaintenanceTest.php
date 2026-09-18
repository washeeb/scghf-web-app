<?php

declare(strict_types=1);

use App\Models\EmailLog;
use App\Models\PaymentWebhookEvent;
use App\Support\RetentionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 15 — the housekeeping a shared-hosting database needs
|--------------------------------------------------------------------------
*/

function webhookRow(array $overrides = []): PaymentWebhookEvent
{
    $event = PaymentWebhookEvent::create([
        'event_id' => 'evt_'.fake()->unique()->lexify('??????????'),
        'event_type' => 'charge.success',
        'gateway_reference' => 'SCGHF-D-TEST',
        'raw_payload' => json_encode(['event' => 'charge.success', 'data' => ['amount' => 5000, 'currency' => 'GHS']]),
        'signature' => 'sig',
        'signature_valid' => true,
        'received_at' => now()->subMonths(14),
    ]);

    // processed_at is not fillable on purpose (the handler sets it); written
    // directly, as an old row would have it.
    DB::table('payment_webhook_events')->where('id', $event->id)->update([
        'processed_at' => array_key_exists('processed_at', $overrides) ? $overrides['processed_at'] : now()->subMonths(14),
        'received_at' => $overrides['received_at'] ?? now()->subMonths(14),
    ]);

    return $event->fresh();
}

// ── Webhook payload archiving ───────────────────────────────────────────────

it('moves the bodies of old processed webhook events into a monthly file and keeps the rows', function () {
    Storage::fake('local');
    config(['system.audit.archive_disk' => 'local']);

    $old = webhookRow();
    $older = webhookRow(['received_at' => now()->subMonths(14)->subDays(3), 'processed_at' => now()->subMonths(14)]);
    $recent = webhookRow(['received_at' => now()->subMonths(2), 'processed_at' => now()->subMonths(2)]);
    $unprocessed = webhookRow(['processed_at' => null]);

    $this->artisan('scghf:archive-webhook-payloads')->assertSuccessful();
    expect($old->fresh()->raw_payload)->not->toBeNull();

    $this->artisan('scghf:archive-webhook-payloads', ['--execute' => true])->assertSuccessful();

    $old = $old->fresh();
    $body = json_encode(['event' => 'charge.success', 'data' => ['amount' => 5000, 'currency' => 'GHS']]);

    expect($old->raw_payload)->toBeNull()
        ->and($old->payload_hash)->toBe(hash('sha256', $body))
        ->and($old->payload_archive)->toStartWith('webhook-archives/payment_webhook_events-')
        ->and($old->payload_archived_at)->not->toBeNull()
        ->and($old->payload())->toBe([])
        ->and($older->fresh()->raw_payload)->toBeNull()
        ->and($recent->fresh()->raw_payload)->not->toBeNull()
        ->and($unprocessed->fresh()->raw_payload)->not->toBeNull()
        ->and(PaymentWebhookEvent::count())->toBe(4);

    Storage::disk('local')->assertExists($old->payload_archive);

    $lines = array_filter(explode("\n", (string) gzdecode((string) Storage::disk('local')->get($old->payload_archive))));
    $decoded = array_map(fn (string $l): array => json_decode($l, true), $lines);

    expect($decoded)->toHaveCount(2)
        ->and(collect($decoded)->pluck('id')->all())->toContain($old->id, $older->id)
        ->and($decoded[0]['sha256'])->toBe(hash('sha256', $decoded[0]['raw_payload']));
});

it('appends a late event to the month it belongs to rather than overwriting the file', function () {
    Storage::fake('local');
    config(['system.audit.archive_disk' => 'local']);

    $first = webhookRow();
    $this->artisan('scghf:archive-webhook-payloads', ['--execute' => true])->assertSuccessful();

    $late = webhookRow(['received_at' => $first->received_at->copy()->addHour(), 'processed_at' => now()->subMonths(14)]);
    $this->artisan('scghf:archive-webhook-payloads', ['--execute' => true])->assertSuccessful();

    $file = $late->fresh()->payload_archive;
    expect($file)->toBe($first->fresh()->payload_archive);

    $lines = array_filter(explode("\n", (string) gzdecode((string) Storage::disk('local')->get($file))));
    expect($lines)->toHaveCount(2);
});

// ── Monthly maintenance ─────────────────────────────────────────────────────

it('prunes expired sessions and old visitor rows, and only with --execute', function () {
    DB::table('sessions')->insert([
        ['id' => 'fresh', 'payload' => 'x', 'last_activity' => now()->getTimestamp()],
        ['id' => 'stale', 'payload' => 'x', 'last_activity' => now()->subDays(3)->getTimestamp()],
    ]);
    DB::table('visitor_stats')->insert([
        ['date' => now()->toDateString(), 'dimension' => 'total', 'value' => '', 'views' => 1, 'sessions' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['date' => now()->subMonths(30)->toDateString(), 'dimension' => 'total', 'value' => '', 'views' => 1, 'sessions' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->artisan('scghf:db-maintain')->assertSuccessful();
    expect(DB::table('sessions')->count())->toBe(2)->and(DB::table('visitor_stats')->count())->toBe(2);

    $this->artisan('scghf:db-maintain', ['--execute' => true])->assertSuccessful();
    expect(DB::table('sessions')->pluck('id')->all())->toBe(['fresh'])
        ->and(DB::table('visitor_stats')->count())->toBe(1);
});

// ── The retention walk ──────────────────────────────────────────────────────

it('walks retention candidates in slices and stops one past the ceiling', function () {
    EmailLog::factory()->count(6)->create(['created_at' => now()->subMonths(30), 'sent_at' => now()->subMonths(30)]);
    EmailLog::factory()->count(2)->create(['created_at' => now()->subDay()]);

    $runner = app(RetentionRunner::class);
    $policy = config('compliance.retention.classes.communication_log');

    expect($runner->dueFor(EmailLog::class, 'communication_log', $policy, 3))->toHaveCount(4)
        ->and($runner->dueFor(EmailLog::class, 'communication_log', $policy, 100))->toHaveCount(6);
});
