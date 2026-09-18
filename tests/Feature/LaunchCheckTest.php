<?php

declare(strict_types=1);

use App\Enums\DonationStatus;
use App\Enums\PageStatus;
use App\Models\Cause;
use App\Models\Donation;
use App\Models\Media;
use App\Models\Page;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Project;
use App\Models\SmsLog;
use App\Models\TeamMember;
use App\Models\User;
use App\Support\Features;
use App\Support\HealthCheck;
use App\Support\LaunchChecks;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 17 — the launch checklist the application answers itself
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    app(Settings::class)->flush();

    // DNS and TLS are network; here they are answers.
    LaunchChecks::resolveDnsUsing(fn (string $name, int $type): array => match (true) {
        str_starts_with($name, '_dmarc.') => [['txt' => 'v=DMARC1; p=quarantine; rua=mailto:dmarc@example.org']],
        str_starts_with($name, 'resend._domainkey.') => [['txt' => 'p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC']],
        default => [['txt' => 'v=spf1 include:amazonses.com ~all']],
    });
    LaunchChecks::certificateUsing(fn (string $host): array => ['validTo_time_t' => time() + 60 * 86400, 'issuer' => ['O' => "Let's Encrypt"]]);

    config([
        'app.url' => 'https://www.example.org',
        'mail.from.address' => 'noreply@mail.example.org',
        'payments.driver' => 'paystack',
        'payments.paystack.secret_key' => 'sk_live_abcdefghijklmnopqrstuvwxyz',
        'communications.sms.driver' => 'mnotify',
        'communications.sms.mnotify.api_key' => 'k',
    ]);
});

afterEach(function () {
    LaunchChecks::resolveDnsUsing(null);
    LaunchChecks::certificateUsing(null);
});

function byKey(string $key): HealthCheck
{
    return app(LaunchChecks::class)->checks()->firstWhere('key', $key);
}

it('fails a fresh installation for the reasons a launch would fail', function () {
    $keys = app(LaunchChecks::class)->problems()->pluck('key')->all();

    expect($keys)->toContain('legal_pages', 'contact_details', 'programmes', 'live_gift', 'webhook_seen', 'sms_live')
        ->and(byKey('legal_pages')->status)->toBe(HealthCheck::CRITICAL)
        ->and(byKey('legal_pages')->value)->toContain('of 10 published')
        ->and(byKey('mail_dns')->status)->toBe(HealthCheck::OK)
        ->and(byKey('tls')->status)->toBe(HealthCheck::OK)
        ->and(byKey('tls')->value)->toContain('60 days');
});

it('passes a site that has done everything the runbook asks', function () {
    Page::query()->whereIn('slug', array_keys(LaunchChecks::LEGAL_PAGES))->update(['status' => PageStatus::Published->value, 'published_at' => now()->subDay()]);

    foreach (['contact.email_general' => 'hello@example.org', 'contact.phone_primary' => '+233240000000', 'contact.address' => '4 Hospital Road', 'general.legal_name' => 'Greater Hope', 'general.tin' => 'C0001'] as $k => $v) {
        setting()->set($k, $v);
    }

    $photo = Media::factory()->create();
    TeamMember::create(['name' => 'A Trustee', 'slug' => 'a-trustee', 'role_title' => 'Chair', 'is_trustee' => true, 'is_published' => true, 'photo_id' => $photo->id]);
    TeamMember::create(['name' => 'The Director', 'slug' => 'the-director', 'role_title' => 'Director', 'is_published' => true, 'photo_id' => $photo->id]);

    Project::factory()->count(3)->create(['is_published' => true]);
    Cause::factory()->count(3)->create(['is_published' => true]);

    $product = Product::factory()->create(['is_published' => true]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true])->restock(5);

    $tx = PaymentTransaction::factory()->settled()->create(['gateway' => PaymentTransaction::GATEWAY_PAYSTACK]);
    $gift = Donation::factory()->completed()->create();
    $tx->forceFill(['payable_type' => $gift->getMorphClass(), 'payable_id' => $gift->id])->save();
    $gift->forceFill(['status' => DonationStatus::Refunded])->save();

    PaymentWebhookEvent::create(['event_id' => 'evt_live', 'event_type' => 'charge.success', 'gateway_reference' => $tx->gateway_reference, 'raw_payload' => '{}', 'signature' => 's', 'signature_valid' => true, 'received_at' => now()]);
    SmsLog::factory()->create(['status' => SmsLog::STATUS_DELIVERED, 'driver' => 'mnotify']);
    setting()->set('analytics.provider', 'umami');

    User::query()->update(['two_factor_confirmed_at' => now()]);

    $problems = app(LaunchChecks::class)->problems();

    expect($problems->pluck('key')->all())->toBe([]);
});

it('refuses a database that still carries the demo seeder', function () {
    $this->seed(DemoDataSeeder::class);

    expect(byKey('demo_accounts')->status)->toBe(HealthCheck::CRITICAL)
        ->and(byKey('demo_accounts')->value)->toContain('found')
        ->and(byKey('demo_data')->status)->toBe(HealthCheck::CRITICAL)
        ->and(byKey('demo_data')->value)->toContain('36 donations');
});

it('refuses a flag that is on with nothing built behind it', function () {
    expect(byKey('flags')->status)->toBe(HealthCheck::OK);

    config(['features.pwa_offline' => true]);
    app(Features::class)->flush();

    expect(byKey('flags')->status)->toBe(HealthCheck::CRITICAL)
        ->and(byKey('flags')->value)->toBe('pwa_offline on');
});

it('names the staff who have never enrolled a second factor', function () {
    $never = User::factory()->staff()->create(['email' => 'new.starter@foundation.test'])->fresh();
    User::factory()->staff()->withTwoFactor()->create();

    expect(byKey('staff_2fa')->status)->toBe(HealthCheck::CRITICAL)
        ->and(byKey('staff_2fa')->advice)->toContain($never->email);
});

it('reports a missing DMARC record and a certificate about to expire as blockers', function () {
    LaunchChecks::resolveDnsUsing(fn (string $name, int $type): array => str_starts_with($name, '_dmarc.') ? [] : [['txt' => str_starts_with($name, 'resend.') ? 'p=abc' : 'v=spf1 ~all']]);
    LaunchChecks::certificateUsing(fn (string $host): array => ['validTo_time_t' => time() + 3 * 86400, 'issuer' => ['O' => 'X']]);

    expect(byKey('mail_dns')->status)->toBe(HealthCheck::CRITICAL)
        ->and(byKey('mail_dns')->value)->toBe('Missing: DMARC')
        ->and(byKey('tls')->status)->toBe(HealthCheck::CRITICAL)
        ->and(byKey('tls')->value)->toContain('3 days');
});

it('says "could not check" rather than failing when there is no network', function () {
    LaunchChecks::resolveDnsUsing(fn (): false => false);
    LaunchChecks::certificateUsing(fn (): ?array => null);

    expect(byKey('mail_dns')->status)->toBe(HealthCheck::WARNING)
        ->and(byKey('mail_dns')->value)->toContain('Could not look up')
        ->and(byKey('tls')->status)->toBe(HealthCheck::WARNING);
});

it('runs from the command line and exits non-zero while there are blockers', function () {
    $this->artisan('scghf:launch-check')
        ->expectsOutputToContain('Is it ready to be public?')
        ->assertExitCode(1);
});
