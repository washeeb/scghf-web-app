<?php

declare(strict_types=1);

use App\Enums\PageStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Media\Pages\EditMedia;
use App\Filament\Resources\Media\RelationManagers\ConsentsRelationManager;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Http\Middleware\RestrictAdminByIp;
use App\Mail\RenderedMessage;
use App\Models\AuditLog;
use App\Models\BackupLogEntry;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Media;
use App\Models\PaymentTransaction;
use App\Models\Post;
use App\Models\ScheduledMessage;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\Suppression;
use App\Models\User;
use App\Models\VolunteerApplication;
use App\Policies\BasePolicy;
use App\Support\HealthCheck;
use App\Support\Html;
use App\Support\Sessions;
use App\Support\Settings;
use App\Support\SiteHealth;
use App\Support\ThemeTokens;
use Database\Seeders\CmsReferenceSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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

// ── Data protection ─────────────────────────────────────────────────────────

it('will not publish a photograph of a person without a valid consent, and takes it down everywhere when withdrawn', function () {
    $staff = User::factory()->staff()->withTwoFactor()->create()->fresh();
    $staff->givePermissionTo(['media.view', 'media.upload', 'consents.view', 'consents.manage']);
    $this->actingAs($staff);

    $photo = Media::factory()->sanitised()->create(['alt_text' => 'A pupil at her desk', 'depicts_people' => true, 'depicts_children' => true]);
    expect($photo->isPublishable())->toBeFalse()
        ->and($photo->publicationRejectionReason())->toContain('child');

    // A child cannot consent for themselves; the model refuses.
    expect(fn () => $photo->consents()->create(['consent_type' => 'photo', 'scope' => 'website', 'granted_by_name' => 'The pupil', 'granted_by_relationship' => 'self', 'is_minor' => true, 'granted_at' => now()]))
        ->toThrow(RuntimeException::class);

    $consent = $photo->consents()->create(['consent_type' => 'photo', 'scope' => 'website', 'granted_by_name' => 'Mrs Owusu', 'granted_by_relationship' => 'parent', 'is_minor' => true, 'guardian_name' => 'Mrs Owusu', 'granted_at' => now()->subDay()]);
    expect($photo->fresh()->isPublishable())->toBeTrue();

    // Revoking the consent unpublishes; so does withdrawing the image outright.
    Livewire::test(ConsentsRelationManager::class, ['ownerRecord' => $photo, 'pageClass' => EditMedia::class])
        ->assertOk()
        ->callAction(TestAction::make('revoke')->table($consent), ['reason' => 'The mother rang and asked.'])
        ->assertNotified();
    expect($photo->fresh()->isPublishable())->toBeFalse()
        ->and(AuditLog::where('event', 'consent.revoked')->exists())->toBeTrue();

    $other = Media::factory()->sanitised()->create(['alt_text' => 'The new borehole']);
    expect($other->isPublishable())->toBeTrue();

    Livewire::test(EditMedia::class, ['record' => $other->getRouteKey()])
        ->callAction('withdraw', ['reason' => 'The landowner asked for it to come down.'])
        ->assertNotified();

    expect($other->fresh()->isPublishable())->toBeFalse()
        ->and($other->fresh()->publicationRejectionReason())->toContain('withdrawn')
        ->and(AuditLog::where('event', 'media.withdrawn')->exists())->toBeTrue();

    $this->assertStringNotContainsString('<img', view('components.media.image', ['media' => $other->fresh()])->render());
});

it('gives a donor a copy of their data, without other people in it, behind the password', function () {
    $user = User::factory()->donor()->create(['password' => 'correct-horse-battery-staple', 'email' => 'ama@example.test'])->fresh();
    $donor = Donor::factory()->create(['user_id' => $user->id, 'email' => 'ama@example.test', 'name' => 'Ama Mensah']);
    Donation::factory()->create(['donor_id' => $donor->id, 'tribute_name' => 'Auntie Grace']);

    $this->actingAs($user);
    $this->get(route('account.privacy'))->assertOk()->assertSee('Download my data')->assertSee('Delete my account');

    $this->post(route('account.privacy.export'), ['current_password' => 'nope'])->assertSessionHasErrors('current_password');

    $response = $this->post(route('account.privacy.export'), ['current_password' => 'correct-horse-battery-staple']);
    $response->assertOk()->assertHeader('Content-Type', 'application/json; charset=utf-8');

    $json = json_decode($response->streamedContent(), true);
    expect($json['account']['email'])->toBe('ama@example.test')
        ->and($json['donations'])->toHaveCount(1)
        ->and($json['donations'][0]['in_memory_of_someone'])->toBeTrue()
        ->and(json_encode($json))->not->toContain('Auntie Grace')
        ->and(AuditLog::where('event', 'privacy.exported')->exists())->toBeTrue();
});

it('deletes a donor account but keeps the financial records without the name, and blocks the address from coming back', function () {
    $this->seed(MessageTemplateSeeder::class);
    $user = User::factory()->donor()->create(['password' => 'correct-horse-battery-staple', 'email' => 'ama@example.test', 'name' => 'Ama Mensah'])->fresh();
    $donor = Donor::factory()->create(['user_id' => $user->id, 'email' => 'ama@example.test', 'name' => 'Ama Mensah', 'phone' => '+233241234567']);
    $donation = Donation::factory()->create(['donor_id' => $donor->id, 'donor_name' => 'Ama Mensah', 'donor_email' => 'ama@example.test']);
    Subscriber::create(['email' => 'ama@example.test', 'source' => 'footer']);

    $this->actingAs($user);
    $this->delete(route('account.privacy.destroy'), ['current_password' => 'correct-horse-battery-staple'])->assertSessionHasErrors('confirm');

    $this->delete(route('account.privacy.destroy'), ['current_password' => 'correct-horse-battery-staple', 'confirm' => '1'])
        ->assertRedirect('/')
        ->assertSessionHas('status');

    expect(auth()->check())->toBeFalse();

    $trashed = User::withTrashed()->find($user->id);
    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->name)->not->toBe('Ama Mensah')
        ->and($trashed->email)->toContain('erased')
        ->and(Subscriber::where('email', 'ama@example.test')->exists())->toBeFalse()
        ->and(Suppression::blocks('email', 'ama@example.test', 'marketing'))->toBeTrue();

    $donation->refresh();
    expect(Donation::count())->toBe(1)
        ->and($donation->donor_name)->not->toBe('Ama Mensah')
        ->and($donation->donor_email)->toBeNull()
        ->and($donor->fresh()->name)->not->toBe('Ama Mensah')
        ->and($donor->fresh()->phone)->toBeNull()
        ->and(AuditLog::where('event', 'erasure.completed')->exists())->toBeTrue();

    $this->post(route('newsletter.subscribe'), ['email' => 'ama@example.test'])->assertRedirect();
    expect(Subscriber::where('email', 'ama@example.test')->exists())->toBeFalse();
});

it('stores the safeguarding-sensitive columns encrypted, and the command re-encrypts legacy rows', function () {
    $application = VolunteerApplication::factory()->create([
        'next_of_kin_name' => 'Kofi Mensah', 'disclosed_convictions' => 'A caution in 2014.',
        'referees' => [['name' => 'Rev. Atia', 'relationship' => 'Pastor', 'phone' => '0201112222', 'email' => null]],
    ]);

    $raw = DB::table('volunteer_applications')->where('id', $application->id)->first();
    expect($raw->next_of_kin_name)->not->toContain('Kofi')
        ->and($raw->disclosed_convictions)->not->toContain('caution')
        ->and($raw->referees)->not->toContain('Atia')
        ->and($application->fresh()->next_of_kin_name)->toBe('Kofi Mensah')
        ->and($application->fresh()->referees()[0]['name'])->toBe('Rev. Atia');

    // A row written before the casts existed: plaintext in the column.
    DB::table('volunteer_applications')->where('id', $application->id)->update(['next_of_kin_name' => 'Plain Text Person', 'referees' => json_encode([['name' => 'Legacy Referee']])]);

    $this->artisan('scghf:encrypt-at-rest')->assertSuccessful()->expectsOutputToContain('DRY RUN');
    $this->artisan('scghf:encrypt-at-rest', ['--execute' => true])->assertSuccessful()->expectsOutputToContain('volunteer_applications: 1 row(s) encrypted');
    $this->artisan('scghf:encrypt-at-rest', ['--execute' => true])->assertSuccessful()->expectsOutputToContain('volunteer_applications: 0 row(s) encrypted');

    $raw = DB::table('volunteer_applications')->where('id', $application->id)->first();
    expect($raw->next_of_kin_name)->not->toContain('Plain Text')
        ->and($application->fresh()->next_of_kin_name)->toBe('Plain Text Person')
        ->and($application->fresh()->referees()[0]['name'])->toBe('Legacy Referee');
});

it('shows the cookie notice with a preferences dialog, from the CMS, and not in the admin', function () {
    $this->get('/')->assertOk()
        ->assertSee('data-cookie-consent', escape: false)
        ->assertSee('data-cookie-preferences', escape: false)
        ->assertSee('Essential only')
        ->assertSee('Cookie preferences')
        ->assertSee(setting('site.cookie_banner_text'));

    Setting::query()->where('group', 'site')->where('key', 'cookie_banner_enabled')->update(['value' => '0']);
    app(Settings::class)->flush();
    $this->get('/')->assertOk()->assertDontSee('data-cookie-consent', escape: false);
});

it('exports the data held about an address with no account, from the command line', function () {
    $donor = Donor::factory()->create(['email' => 'noaccount@example.test', 'name' => 'Yaw Boateng']);
    Donation::factory()->create(['donor_id' => $donor->id]);
    $dir = storage_path('app/private/exports-test');

    $this->artisan('scghf:export-data', ['email' => 'NoAccount@example.test', '--to' => $dir])->assertSuccessful();

    $files = glob($dir.'/export-noaccount-example-test-*.json');
    expect($files)->toHaveCount(1);
    $json = json_decode((string) file_get_contents($files[0]), true);
    expect($json['donor_profile']['name'])->toBe('Yaw Boateng')
        ->and($json['donations'])->toHaveCount(1)
        ->and($json['sign_ins'])->toBe([])
        ->and(AuditLog::where('event', 'privacy.exported')->exists())->toBeTrue();

    array_map('unlink', $files);
    rmdir($dir);
});

// ── Infrastructure ──────────────────────────────────────────────────────────

it('restores the newest backup into the scratch database, counts what came back, and records the test', function () {
    $scratch = 'scghf_restore_test';
    DB::statement("CREATE DATABASE IF NOT EXISTS `{$scratch}`");
    config()->set('database.restore_test_database', $scratch);
    config()->set('backup.backup.password', 'archive-secret');

    $verifier = User::factory()->staff()->withTwoFactor()->create(['email' => 'grace@example.test'])->fresh();
    User::factory()->count(2)->donor()->create();

    // A dump the way mysqldump writes one, inside a password-protected zip
    // where spatie puts it.
    $sql = "-- MySQL dump\n/*!40101 SET NAMES utf8mb4 */;\nDROP TABLE IF EXISTS `users`;\nCREATE TABLE `users` (`id` int NOT NULL, `email` varchar(191) NOT NULL, PRIMARY KEY (`id`));\nINSERT INTO `users` VALUES (1,'a@example.test'),(2,'b@example.test'),(3,'c@example.test');\nCREATE TABLE `donations` (`id` int NOT NULL);\nINSERT INTO `donations` VALUES (1),(2);\n";
    $zipPath = tempnam(sys_get_temp_dir(), 'bk').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('db-dumps/mysql-scghf.sql', $sql);
    $zip->setEncryptionName('db-dumps/mysql-scghf.sql', ZipArchive::EM_AES_256, 'archive-secret');
    $zip->close();

    Storage::fake('backups');
    Storage::disk('backups')->put('scghf/2026-09-17.zip', (string) file_get_contents($zipPath));
    unlink($zipPath);

    $backup = BackupLogEntry::create(['type' => 'backup', 'status' => 'completed', 'destination' => 'backups', 'filename' => 'scghf/2026-09-17.zip', 'size_bytes' => 1234, 'started_at' => now(), 'finished_at' => now()]);

    $this->artisan('scghf:restore-test')->assertFailed();
    $this->artisan('scghf:restore-test', ['--verified-by' => 'grace@example.test'])
        ->expectsOutputToContain('Restore test recorded')
        ->assertSuccessful();

    $test = BackupLogEntry::lastRestoreTest();
    expect($test)->not->toBeNull()
        ->and($test->restored_row_count)->toBe(5)
        ->and($test->verified_by)->toBe($verifier->id)
        ->and($test->source_backup_id)->toBe($backup->id)
        ->and(BackupLogEntry::restoreTestIsOverdue())->toBeFalse()
        ->and(AuditLog::where('event', 'backup.restore_tested')->exists())->toBeTrue()
        ->and(app(SiteHealth::class)->checks()->firstWhere('key', 'restore_test')->status)->toBe(HealthCheck::OK);

    // The scratch database was wiped afterwards, and the live one untouched.
    expect((int) DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = '{$scratch}'")->n)->toBe(0)
        ->and(User::count())->toBe(3);

    DB::statement("DROP DATABASE IF EXISTS `{$scratch}`");
});

it('refuses to run the restore test against the live database or with nobody signing it', function () {
    config()->set('database.restore_test_database', (string) config('database.connections.mysql.database'));
    $this->artisan('scghf:restore-test', ['--verified-by' => 'x@example.test'])->assertFailed();

    config()->set('database.restore_test_database', 'scghf_restore_test');
    $this->artisan('scghf:restore-test')->assertFailed();
});

it('reports error monitoring and the restore test on the health page', function () {
    $health = fn (string $key) => app(SiteHealth::class)->checks()->firstWhere('key', $key);

    expect($health('restore_test')->status)->toBe(HealthCheck::WARNING)
        ->and($health('restore_test')->advice)->toContain('scghf:restore-test')
        ->and($health('error_monitoring')->status)->toBe(HealthCheck::OK);

    app()->detectEnvironment(fn () => 'production');
    config()->set('sentry.dsn', '');
    expect($health('error_monitoring')->status)->toBe(HealthCheck::WARNING);
    config()->set('sentry.dsn', 'https://key@o0.ingest.sentry.io/1');
    expect($health('error_monitoring')->value)->toBe('Sentry');
    app()->detectEnvironment(fn () => 'testing');
});
