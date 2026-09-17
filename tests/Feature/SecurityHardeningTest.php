<?php

declare(strict_types=1);

use App\Enums\PageStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Http\Middleware\RestrictAdminByIp;
use App\Mail\RenderedMessage;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\PaymentTransaction;
use App\Models\Post;
use App\Models\ScheduledMessage;
use App\Models\Setting;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Html;
use App\Support\Sessions;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\CmsReferenceSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 12 Module 1 — headers, sessions, the admin door, staff accounts
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(CmsReferenceSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(MessageTemplateSeeder::class);

    BasePolicy::forgetKnownPermissions();
    ThemeTokens::flush();
    app(Settings::class)->flush();
});

function sessionRow(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
        'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp,
    ]);
}

// ── Headers ─────────────────────────────────────────────────────────────────

it('sends the security headers and a nonce-carrying, enforced CSP on the public site', function () {
    $response = $this->get('/')->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeaderMissing('X-Powered-By')
        ->assertHeaderMissing('Content-Security-Policy-Report-Only');

    $csp = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $m);

    expect($csp)->toContain("script-src 'self' 'nonce-")
        ->and($csp)->not->toContain("'unsafe-inline' 'unsafe-eval'")
        ->and($csp)->toContain('https://js.paystack.co')
        ->and($csp)->toContain("style-src-attr 'unsafe-inline'")
        ->and($csp)->toContain('report-uri')
        ->and($m[1] ?? '')->not->toBe('');

    // The inline theme script and the Vite tags carry this request's nonce.
    $response->assertSee('nonce="'.$m[1].'"', escape: false);
});

it('sends the admin panel a report-only policy, and HSTS only when asked and secure', function () {
    $this->actingAs(User::factory()->staff()->withTwoFactor()->create()->fresh());

    $this->get('/'.config('admin.path'))
        ->assertHeader('Content-Security-Policy-Report-Only')
        ->assertHeaderMissing('Content-Security-Policy')
        ->assertHeaderMissing('Strict-Transport-Security');

    config()->set('security.headers.hsts_max_age', 31536000);
    $this->get('/')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('accepts a CSP violation report without a token and logs it', function () {
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'CSP') && $context['directive'] === 'script-src');

    $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'],
        json_encode(['csp-report' => ['document-uri' => 'http://localhost/', 'violated-directive' => 'script-src', 'blocked-uri' => 'inline']]))
        ->assertNoContent();
});

// ── Sessions ────────────────────────────────────────────────────────────────

it('ends a staff session after the absolute timeout however active it is', function () {
    config()->set('admin.session_timeout', 60);
    config()->set('admin.absolute_timeout', 120);
    $staff = User::factory()->staff()->withTwoFactor()->create()->fresh();
    $panel = '/'.config('admin.path');

    $this->actingAs($staff)->get($panel)->assertOk();

    // Never idle for long — but the absolute clock keeps running.
    $this->travel(50)->minutes();
    $this->get($panel)->assertOk();
    $this->travel(50)->minutes();
    $this->get($panel)->assertOk();

    $this->travel(30)->minutes();
    $this->get($panel)->assertRedirect();
    expect(auth()->check())->toBeFalse();
});

it('lets a donor sign out everywhere else with their password, and keeps this device', function () {
    $user = User::factory()->donor()->create(['password' => 'correct-horse-battery-staple'])->fresh();
    sessionRow($user, 'other-laptop');
    sessionRow($user, 'lost-phone');
    $before = $user->remember_token;

    $this->actingAs($user);
    $this->get(route('account.security'))->assertOk()->assertSee('Sign out everywhere else');

    $this->post(route('account.sessions.revoke'), ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(2);

    $this->post(route('account.sessions.revoke'), ['current_password' => 'correct-horse-battery-staple'])
        ->assertRedirect()->assertSessionHas('status');

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->remember_token)->not->toBe($before)
        ->and(AuditLog::where('event', 'auth.sessions_revoked')->exists())->toBeTrue();
});

it('ends every other staff session on sign-in when single-session mode is on', function () {
    config()->set('admin.single_session', true);
    $staff = User::factory()->staff()->withTwoFactor()->create()->fresh();
    sessionRow($staff, 'yesterday-at-the-cafe');

    event(new Login('web', $staff, false));

    expect(DB::table('sessions')->where('user_id', $staff->id)->where('id', 'yesterday-at-the-cafe')->exists())->toBeFalse()
        ->and(AuditLog::where('event', 'auth.sessions_revoked')->exists())->toBeTrue();
});

it('lets the panel in from anywhere with no allowlist, and only from the list with one', function () {
    $middleware = new RestrictAdminByIp;
    $next = fn () => response('in');

    config()->set('admin.ip_allowlist', []);
    expect($middleware->handle(Request::create('/x', 'GET', [], [], [], ['REMOTE_ADDR' => '8.8.8.8']), $next)->getContent())->toBe('in');

    config()->set('admin.ip_allowlist', ['41.66.0.0/16', '10.0.0.5']);
    expect($middleware->handle(Request::create('/x', 'GET', [], [], [], ['REMOTE_ADDR' => '41.66.12.9']), $next)->getContent())->toBe('in')
        ->and($middleware->handle(Request::create('/x', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.5']), $next)->getContent())->toBe('in');

    expect(fn () => $middleware->handle(Request::create('/x', 'GET', [], [], [], ['REMOTE_ADDR' => '8.8.8.8']), $next))
        ->toThrow(NotFoundHttpException::class);
});

// ── Staff accounts ──────────────────────────────────────────────────────────

it('creates a staff account with no known password and emails a reset link, audited', function () {
    Mail::fake();
    $admin = User::factory()->staff()->withTwoFactor()->create()->fresh();
    $admin->givePermissionTo(['users.view', 'users.create', 'users.update']);
    $this->actingAs($admin);

    expect(UserResource::canViewAny())->toBeTrue();

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Grace Mensah', 'email' => 'grace@example.test', 'job_title' => 'Programme officer', 'roles' => [Role::where('name', 'Programme Officer')->value('id')]])
        ->call('create')
        ->assertHasNoFormErrors();

    $grace = User::where('email', 'grace@example.test')->firstOrFail();
    expect($grace->isStaff())->toBeTrue()
        ->and($grace->hasRole('Programme Officer'))->toBeTrue()
        ->and(AuditLog::where('event', 'user.created')->exists())->toBeTrue();

    // The reset goes through the CMS template, sent at once rather than queued.
    Mail::assertSent(RenderedMessage::class, fn (RenderedMessage $mail): bool => $mail->hasTo('grace@example.test'));
    Livewire::test(ListUsers::class)->assertOk()->assertSee('Grace Mensah');
});

it('lets an administrator sign a colleague out everywhere, reset their two-factor with a reason, and suspend them — never themselves', function () {
    $admin = User::factory()->staff()->withTwoFactor()->create()->fresh();
    $admin->givePermissionTo(['users.view', 'users.update']);
    $this->actingAs($admin);
    $colleague = User::factory()->staff()->withTwoFactor()->create()->fresh();
    sessionRow($colleague, 'colleague-phone');

    Livewire::test(EditUser::class, ['record' => $colleague->getRouteKey()])
        ->assertOk()
        ->callAction('revokeSessions')
        ->assertNotified();
    expect(DB::table('sessions')->where('user_id', $colleague->id)->count())->toBe(0);

    Livewire::test(EditUser::class, ['record' => $colleague->getRouteKey()])
        ->callAction('resetTwoFactor', ['reason' => 'Rang her on the office number; she lost the phone on Saturday.'])
        ->assertNotified();
    expect($colleague->fresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and(AuditLog::where('event', 'auth.two_factor_reset')->value('description'))->toContain('office number');

    Livewire::test(EditUser::class, ['record' => $colleague->getRouteKey()])
        ->callAction('suspend', ['reason' => 'Left the foundation.'])
        ->assertNotified();
    expect($colleague->fresh()->isSuspended())->toBeTrue()
        ->and($colleague->fresh()->canAccessPanel())->toBeFalse();

    Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
        ->assertActionHidden('suspend')
        ->assertActionHidden('revokeSessions')
        ->assertActionHidden('resetTwoFactor');
});

it('keeps donor accounts off the staff list and the list from anybody without users.view', function () {
    $donor = User::factory()->donor()->create(['name' => 'A Donor Person']);
    $viewer = User::factory()->staff()->withTwoFactor()->create()->fresh();
    $viewer->givePermissionTo('users.view');

    $this->actingAs($viewer);
    Livewire::test(ListUsers::class)->assertOk()->assertDontSee('A Donor Person');

    $this->actingAs(User::factory()->staff()->withTwoFactor()->create()->fresh());
    expect(UserResource::canViewAny())->toBeFalse();
});

// ── Stored HTML ─────────────────────────────────────────────────────────────

it('prints CMS rich text through the sanitiser, so a compromised editor account is not stored XSS', function () {
    $dirty = '<p>Hello <b>there</b></p><script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)">x</a><a href="https://example.org" target="_blank">ok</a><iframe src="https://evil.example"></iframe>';

    $clean = Html::clean($dirty);

    expect($clean)->toContain('<b>there</b>')
        ->and($clean)->toContain('href="https://example.org"')
        ->and($clean)->not->toContain('<script')
        ->and($clean)->not->toContain('onerror')
        ->and($clean)->not->toContain('javascript:')
        ->and($clean)->not->toContain('<iframe')
        ->and(Html::clean(null))->toBe('');

    $post = Post::create(['title' => 'Dirty', 'slug' => 'dirty', 'status' => PageStatus::Published, 'published_at' => now()->subDay(), 'body' => $dirty]);

    $this->get(route('news.show', $post))->assertOk()->assertSee('<b>there</b>', escape: false)->assertDontSee('alert(1)', escape: false)->assertDontSee('onerror', escape: false)->assertDontSee('javascript:', escape: false);
});

// ── Payments: anomalies reach a person ─────────────────────────────────────

it('emails the alerts address when a payment settles for the wrong amount, once', function () {
    Setting::query()->where('group', 'communications')->where('key', 'alert_email')->update(['value' => 'finance@example.test']);
    app(Settings::class)->flush();
    config(['payments.paystack.webhook_secret' => 'sk_test_webhook_secret_for_tests']);

    $donation = Donation::factory()->create();
    $transaction = PaymentTransaction::factory()->create();
    $transaction->forceFill(['status' => 'mismatch', 'mismatch_reason' => 'Amount mismatch: expected 25000, got 24999'])->save();

    $donation->onPaymentMismatch($transaction->fresh());
    $donation->onPaymentMismatch($transaction->fresh());

    $alerts = ScheduledMessage::where('template_key', 'admin.payment_anomaly')->get();

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->to_address)->toBe('finance@example.test')
        ->and(json_encode($alerts->first()->payload))->toContain('different amount');
});

it('emails the alerts address about a run of failed payments or refunds, once an hour, and stays quiet otherwise', function () {
    Setting::query()->where('group', 'communications')->where('key', 'alert_email')->update(['value' => 'finance@example.test']);
    app(Settings::class)->flush();
    config()->set('payments.paystack.anomalies.failed_per_hour', 3);

    PaymentTransaction::factory()->count(2)->create(['status' => PaymentStatus::Failed]);
    $this->artisan('scghf:payment-anomalies')->assertSuccessful();
    expect(ScheduledMessage::where('template_key', 'admin.payment_anomaly')->count())->toBe(0);

    PaymentTransaction::factory()->count(2)->create(['status' => PaymentStatus::Failed]);
    $this->artisan('scghf:payment-anomalies')->assertSuccessful();
    $this->artisan('scghf:payment-anomalies')->assertSuccessful();

    $alerts = ScheduledMessage::where('template_key', 'admin.payment_anomaly')->get();
    expect($alerts)->toHaveCount(1)
        ->and(json_encode($alerts->first()->payload))->toContain('4 failed payments');
});
