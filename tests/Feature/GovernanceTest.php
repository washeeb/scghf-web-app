<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\FeatureFlag;
use App\Models\Setting;
use App\Models\SettingHistoryEntry;
use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Feature flags and settings history
|--------------------------------------------------------------------------
|
| Two questions somebody will ask, that nothing else could answer:
|
|   "Why is the shop missing?"  — and the answer needs to be a sentence with a
|   person and a date in it, not a boolean.
|
|   "What did the receipts we issued in March actually say?" — and the settings
|   table holds only what they say now.
|
*/

beforeEach(function () {
    $this->features = app(Features::class);
    $this->features->flush();
    $this->admin = User::factory()->staff()->create(['name' => 'Kofi Owusu']);
});

// ── Feature flags ───────────────────────────────────────────────────────────

it('answers from config when nothing has been overridden', function () {
    config()->set('features.blog_comments', false);

    expect($this->features->enabled('blog_comments'))->toBeFalse();
});

it('lets the database override a configured default', function () {
    config()->set('features.blog_comments', false);

    $this->features->override('blog_comments', true, 'Trialling comments for a month.', $this->admin, 30);

    expect($this->features->enabled('blog_comments'))->toBeTrue();
});

it('refuses a flag the code has never heard of', function () {
    // A switch wired to nothing is worse than no switch, because somebody will
    // believe it did something.
    expect(fn () => FeatureFlag::create([
        'key' => 'invented_in_an_admin_screen',
        'is_enabled' => false,
        'reason' => 'Seemed useful.',
    ]))->toThrow(InvalidArgumentException::class, 'no feature flag called');
});

it('needs a reason, for switching on as much as off', function () {
    expect(fn () => FeatureFlag::create([
        'key' => 'blog_comments',
        'is_enabled' => true,
        'reason' => '  ',
    ]))->toThrow(InvalidArgumentException::class, 'needs a reason');
});

it('refuses to let donations be switched off from an admin screen', function () {
    // Turning donations off has financial and reputational consequences and
    // deserves a deployment by somebody who has thought about them — not a
    // toggle next to "dark mode".
    expect(fn () => $this->features->override('donations', false, 'Pausing.', $this->admin))
        ->toThrow(RuntimeException::class, 'cannot be changed from the admin panel');
});

it('lets an override lapse without anything having to run', function () {
    // An expiry that depends on a scheduled job having run is an expiry that
    // silently does not happen — and on this host the scheduler is a cron line
    // somebody may not have set up.
    config()->set('features.blog_comments', false);

    $this->features->override('blog_comments', true, 'Two-week trial.', $this->admin, 14);
    expect($this->features->enabled('blog_comments'))->toBeTrue();

    $this->travel(15)->days();
    $this->features->flush();

    expect($this->features->enabled('blog_comments'))->toBeFalse();
});

it('explains why part of the site is missing, in a sentence', function () {
    $flag = $this->features->override(
        'blog_comments', false, 'Spam from an unmoderated form.', $this->admin, 7,
    );

    expect($flag->explanation())
        ->toContain('Spam from an unmoderated form')
        ->toContain('Reverts on');
});

it('says so when an override has lapsed rather than looking active', function () {
    $flag = FeatureFlag::factory()->lapsed()->create();

    expect($flag->hasLapsed())->toBeTrue()
        ->and($flag->explanation())->toContain('lapsed');
});

it('records a flag change in the audit trail', function () {
    $this->features->override('blog_comments', false, 'Too much spam.', $this->admin, 7);

    $entry = AuditLog::where('event', 'feature_flag.changed')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->severity)->toBe(AuditLog::SEVERITY_WARNING)
        ->and($entry->description)->toContain('Too much spam')
        ->and($entry->causer_id)->toBe($this->admin->id);
});

it('lists every flag, including ones nobody has touched', function () {
    // Built from the config key list, so a flag that exists in code but has
    // never been overridden still appears.
    expect($this->features->all())->toHaveKey('donations')
        ->and($this->features->all())->toHaveKey('shop');
});

it('falls back to the configured default when the overrides cannot be read', function () {
    // During a database outage the honest answer to "is the shop enabled?" is
    // whatever was deployed. Answering "no" would take working parts of the
    // site down on top of the outage.
    config()->set('features.shop', true);
    Schema::drop('feature_flags');
    $this->features->flush();

    expect($this->features->enabled('shop'))->toBeTrue();
});

// ── Settings history ────────────────────────────────────────────────────────

it('records what a setting used to be', function () {
    $setting = Setting::create([
        'group' => 'organisation',
        'key' => 'legal_name',
        'value' => "St Cecilia's Greater Hope Foundations",
        'type' => 'string', 'label' => 'Test setting',
    ]);

    $setting->update(['value' => "St Cecilia's Greater Hope Foundation"]);

    $history = SettingHistoryEntry::forKey('organisation.legal_name');

    expect($history)->toHaveCount(1)
        ->and($history->first()->old_value)->toBe("St Cecilia's Greater Hope Foundations")
        ->and($history->first()->new_value)->toBe("St Cecilia's Greater Hope Foundation");
});

it('records a change made any way at all, not just through an admin screen', function () {
    // A history that only Filament wrote to would have a hole in it exactly
    // where somebody bypassed Filament.
    $setting = Setting::create([
        'group' => 'general', 'key' => 'receipt_signatory',
        'value' => 'The Treasurer', 'type' => 'string', 'label' => 'Test setting',
    ]);

    $setting->forceFill(['value' => 'The Executive Director'])->save();

    expect(SettingHistoryEntry::forKey('general.receipt_signatory'))->toHaveCount(1);
});

it('ignores a change that is not to the value', function () {
    // Relabelling or reordering a setting is not a change to what the site
    // says, and recording those would bury the ones that matter.
    $setting = Setting::create([
        'group' => 'general', 'key' => 'site_name',
        'value' => 'Greater Hope', 'type' => 'string', 'label' => 'Test setting',
    ]);

    $setting->update(['label' => 'The name shown in the header', 'sort_order' => 3]);

    expect(SettingHistoryEntry::count())->toBe(0);
});

it('records that a secret changed without recording the secret', function () {
    // A history table is the last place a plaintext copy of a secret should
    // accumulate — it would outlive every rotation.
    $setting = Setting::create([
        'group' => 'integrations', 'key' => 'webhook_secret',
        'value' => 'old-secret', 'type' => 'string', 'label' => 'Test setting', 'is_encrypted' => true,
    ]);

    $setting->update(['value' => 'new-secret']);

    $entry = SettingHistoryEntry::forKey('integrations.webhook_secret')->first();

    expect($entry->is_redacted)->toBeTrue()
        ->and($entry->old_value)->toBeNull()
        ->and($entry->new_value)->toBeNull()
        ->and($entry->summary())->toContain('value not recorded');
});

it('answers what a setting held at a moment in the past', function () {
    // The question this table exists for: "what did the receipts we issued in
    // March actually say?"
    $setting = Setting::create([
        'group' => 'general', 'key' => 'receipt_signatory',
        'value' => 'The Treasurer', 'type' => 'string', 'label' => 'Test setting',
    ]);

    $march = now();
    $this->travel(30)->days();
    $setting->update(['value' => 'The Executive Director']);

    expect(SettingHistoryEntry::valueAt('general.receipt_signatory', $march))->toBe('The Treasurer')
        ->and(SettingHistoryEntry::valueAt('general.receipt_signatory', now()))
        ->toBe('The Executive Director');
});

it('keeps the history when the setting itself is removed', function () {
    // "What did that used to be, before we deleted it?" is exactly the question
    // this table answers, which is why it holds no foreign key to `settings`.
    $setting = Setting::create([
        'group' => 'general', 'key' => 'temporary_notice',
        'value' => 'Closed for Christmas.', 'type' => 'string', 'label' => 'Test setting',
    ]);

    $setting->update(['value' => 'Open as usual.']);
    $setting->delete();

    expect(SettingHistoryEntry::forKey('general.temporary_notice'))->toHaveCount(1);
});

it('refuses to let the history be edited or deleted', function () {
    $setting = Setting::create([
        'group' => 'general', 'key' => 'site_name',
        'value' => 'Old name', 'type' => 'string', 'label' => 'Test setting',
    ]);
    $setting->update(['value' => 'New name']);

    $entry = SettingHistoryEntry::first();

    expect(fn () => $entry->forceFill(['new_value' => 'Something else'])->save())
        ->toThrow(RuntimeException::class, 'append-only')
        ->and(fn () => $entry->delete())->toThrow(RuntimeException::class);
});

it('records who made the change', function () {
    $this->actingAs($this->admin);

    $setting = Setting::create([
        'group' => 'general', 'key' => 'site_name',
        'value' => 'Old name', 'type' => 'string', 'label' => 'Test setting',
    ]);
    $setting->update(['value' => 'New name']);

    $entry = SettingHistoryEntry::first();

    expect($entry->changed_by)->toBe($this->admin->id)
        ->and($entry->changed_by_label)->toContain('Kofi Owusu');
});
