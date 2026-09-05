<?php

declare(strict_types=1);

namespace App\Models;

use App\Communications\AccountNotifier;
use App\Enums\UserType;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use SensitiveParameter;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Traits\HasRoles;

/**
 * Staff and donors both. See docs/PHASE-3-DATA-ARCHITECTURE.md §2.1.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property UserType $type
 * @property bool $is_active
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /*
     * `hasPermissionTo` is aliased rather than overridden with `parent::` —
     * it comes from spatie's HasPermissions trait (pulled in by HasRoles),
     * not from a parent class, so `parent::` would resolve to Authenticatable
     * and fail. See the override below.
     */
    use HasRoles {
        HasRoles::hasPermissionTo as protected spatieHasPermissionTo;
    }
    use HasUlids;
    use LogsActivity;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'phone_raw',
        'type',
        'job_title',
        'bio',
        'locale',
        'timezone',
        'is_active',
        'accepts_email_marketing',
        'accepts_sms_marketing',
    ];

    /**
     * Never serialised. `two_factor_*` are hidden as well as encrypted — an
     * encrypted secret leaking into a JSON response is still a leak of the
     * fact it exists, and of its length.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'suspended_at' => 'datetime',
            'last_login_at' => 'datetime',
            'marketing_consent_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'type' => UserType::class,
            'is_active' => 'boolean',
            'accepts_email_marketing' => 'boolean',
            'accepts_sms_marketing' => 'boolean',
        ];
    }

    /** The public identifier. `id` never appears in a URL — §1.1. */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** @return HasMany<LoginHistory, $this> */
    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class)->latest();
    }

    /**
     * The giving record this account is attached to, if any.
     *
     * `hasOne`, not `belongsTo` — the foreign key is on `donors`. Most donors
     * have no user account at all (they gave once, from a phone, and left), so
     * this is null far more often than not, and every caller must handle that.
     *
     * @return HasOne<Donor, $this>
     */
    public function donor(): HasOne
    {
        return $this->hasOne(Donor::class);
    }

    /**
     * Attach this account to the giving record for its email address, creating
     * one if there is none.
     *
     * ── Why this waits for verification ─────────────────────────────────────
     *
     * A donor record holds somebody's entire giving history, and it is matched
     * on email. Attaching at registration would mean anybody who typed a known
     * donor's address into the sign-up form could read what that person has
     * given, and to what — before proving they own the address.
     *
     * So the caller is the email verification controller, not the registration
     * one. Proving the address is what earns the history.
     *
     * Returns null when the record is already claimed by a different account,
     * which is not an error: two accounts on one address is a merge decision
     * for staff, not something to resolve silently in a request.
     */
    public function claimDonorRecord(): ?Donor
    {
        if (! $this->hasVerifiedEmail()) {
            return null;
        }

        $existing = Donor::where('user_id', $this->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        $donor = Donor::whereNull('user_id')
            ->where('email', mb_strtolower(trim((string) $this->email)))
            ->first();

        if ($donor === null) {
            $donor = Donor::create([
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone_raw ?? $this->phone,
            ]);
        }

        $donor->forceFill(['user_id' => $this->getKey()])->save();

        return $donor;
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    /**
     * Ghanaian numbers normalised to E.164 on write, with the raw input kept.
     *
     * `024 123 4567`, `+233241234567` and `233241234567` are the same number
     * and must not produce three donor records. Blueprint risk DEL-9.
     */
    protected function phone(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): array {
                if ($value === null || trim($value) === '') {
                    return ['phone' => null, 'phone_raw' => null];
                }

                $raw = trim($value);
                $digits = preg_replace('/\D/', '', $raw) ?? '';

                $normalised = match (true) {
                    // 0244123456 → +233244123456
                    str_starts_with($digits, '0') && strlen($digits) === 10 => '+233'.substr($digits, 1),
                    // 233244123456 → +233244123456
                    str_starts_with($digits, '233') && strlen($digits) === 12 => '+'.$digits,
                    // 244123456 → +233244123456
                    strlen($digits) === 9 => '+233'.$digits,
                    // Anything else is kept as typed rather than mangled into a
                    // wrong number — support can look at phone_raw.
                    default => $digits !== '' ? '+'.$digits : null,
                };

                return ['phone' => $normalised, 'phone_raw' => $raw];
            },
        );
    }

    protected function initials(): Attribute
    {
        return Attribute::get(fn (): string => Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->implode(''));
    }

    // ── State ────────────────────────────────────────────────────────────────

    public function isStaff(): bool
    {
        return $this->type === UserType::Staff;
    }

    public function isDonor(): bool
    {
        return $this->type === UserType::Donor;
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Suspend this account.
     *
     * `suspended_at` is deliberately NOT in `$fillable`. Cutting off someone's
     * access is a decision, not a form field, and it must not be reachable by
     * mass assignment from a request payload. The same goes for reinstatement.
     */
    public function suspend(string $reason): void
    {
        $this->forceFill([
            'suspended_at' => now(),
            'suspended_reason' => $reason,
            'is_active' => false,
        ])->save();
    }

    public function reinstate(): void
    {
        $this->forceFill([
            'suspended_at' => null,
            'suspended_reason' => null,
            'is_active' => true,
        ])->save();
    }

    /**
     * A deactivated or suspended account holds no permissions, whatever roles
     * remain attached to it.
     *
     * This lives on the model rather than only in a `Gate::before` because
     * spatie/laravel-permission registers its OWN `Gate::before`, and package
     * providers boot before application providers — so spatie's callback returns
     * true for a held permission and short-circuits before any gate of ours can
     * deny it. Overriding here closes that path and every other one: Gate,
     * `@can`, Filament, and direct `hasPermissionTo()` calls all route through it.
     *
     * Gate::before in AuthServiceProvider still exists for the Super Admin
     * wildcard, which is a different concern.
     *
     * @param  string|int|\BackedEnum|Permission  $permission
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        if (! $this->isInGoodStanding()) {
            return false;
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    /**
     * Whether this account may hold permissions at all.
     *
     * The single expression of "active and not suspended", used by
     * `hasPermissionTo()` here and by the `Gate::before` in AuthServiceProvider
     * — which needs the same answer and was reading the two columns raw, with
     * the same fragility this method exists to remove.
     */
    public function isInGoodStanding(): bool
    {
        $this->ensureAccountStandingIsLoaded();

        return (bool) $this->is_active && ! $this->isSuspended();
    }

    /**
     * Make sure `is_active` and `suspended_at` are actually in memory before
     * anything decides an authorisation question on them.
     *
     * ── Why this is not a guess in either direction ─────────────────────────
     *
     * Strict mode turns reading an unloaded attribute into an exception, so a
     * `User::select('id', 'name')` anywhere followed by any permission check —
     * a `@can` in a Blade template, a Filament resource asking `canViewAny` —
     * is a 500 rather than an answer. In production, where strict mode is off,
     * the same code silently reads null instead, and `! null` is true: the
     * account reads as INACTIVE and quietly holds no permissions. One
     * environment throws and the other silently denies, which is the worst
     * pair of behaviours to have to debug.
     *
     * `hasTwoFactorEnabled()` met the same problem and answered it with
     * `loaded()`, guessing in the safe direction. That works there because
     * there IS a safe direction — "no evidence of 2FA" means ask them to
     * enrol, which costs nothing.
     *
     * Here both guesses are wrong. Assuming the account is in good standing
     * lets a suspended administrator keep their permissions, which is the exact
     * thing this override exists to prevent. Assuming it is not silently denies
     * a legitimate person with no explanation anybody could find.
     *
     * So it stops guessing and reads the two columns. One query, only when they
     * are genuinely absent, and never in production — the session guard loads
     * the whole row, so this does not fire on a real request. It fires for a
     * partially selected model, which is precisely the case that was broken.
     */
    private function ensureAccountStandingIsLoaded(): void
    {
        if (array_key_exists('is_active', $this->attributes)
            && array_key_exists('suspended_at', $this->attributes)) {
            return;
        }

        if (! $this->exists) {
            /*
             * An unsaved model has nothing to read. Treated as not in good
             * standing, because an account that does not exist yet cannot have
             * been granted anything — and the `$attributes` defaults below keep
             * the subsequent checks from throwing.
             */
            $this->attributes['is_active'] ??= false;
            $this->attributes['suspended_at'] ??= null;

            return;
        }

        $standing = static::query()
            ->withoutGlobalScopes()
            ->whereKey($this->getKey())
            ->first(['is_active', 'suspended_at']);

        // A row that has since been deleted is not in good standing either.
        $this->attributes['is_active'] = $standing?->getRawOriginal('is_active') ?? false;
        $this->attributes['suspended_at'] = $standing?->getRawOriginal('suspended_at');
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->getAppAuthenticationSecret() !== null
            && $this->loaded('two_factor_confirmed_at') !== null;
    }

    /**
     * An attribute's value, or null when the column was not loaded.
     *
     * Strict mode turns reading an unloaded attribute into an exception, which
     * is usually exactly what you want — it catches the `select('id')` that
     * silently returns null everywhere else. It is wrong here.
     *
     * A `User` that has just been created, or one loaded by a partial select,
     * genuinely has no `two_factor_secret` in memory. The honest answer to
     * "does this account have 2FA?" in that state is "no evidence that it
     * does", and the consequence of that answer is that `mustEnrolInTwoFactor()`
     * returns true — the account is asked to enrol.
     *
     * That is the safe direction. Throwing instead would mean an exception on
     * the login path for anybody whose model was loaded slightly differently,
     * and returning true would let an unloaded column skip the second factor.
     */
    private function loaded(string $key): mixed
    {
        return array_key_exists($key, $this->attributes) ? $this->getAttribute($key) : null;
    }

    // ── Two-factor authentication (Filament's TOTP contracts) ────────────────
    //
    // Wired to the columns Module 1 created rather than to a second set of
    // Filament's own. Both are already `encrypted` casts and both are in
    // `$hidden`, so the secret is never serialised into a response, a log line
    // or a queued job payload.
    //
    // No new dependency: Filament v5 ships TOTP, which matters on a host where
    // adding a package means a deploy.

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->loaded('two_factor_secret');
    }

    /**
     * Store a new TOTP secret.
     *
     * `two_factor_confirmed_at` moves with it, because the two must never
     * disagree: a secret with no confirmation reads as "enrolment started and
     * abandoned", and `hasTwoFactorEnabled()` — which the enrolment gate relies
     * on — would then be false for somebody who actually has 2FA working.
     *
     * Filament passes null to disable, which clears both.
     */
    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => $secret === null ? null : now(),
        ])->save();
    }

    /**
     * What the authenticator app shows beside the code.
     *
     * The email address, not the name. Staff frequently hold more than one
     * account here — their own and a shared finance login — and two entries
     * reading "Ama Mensah" in an authenticator app is a code typed from the
     * wrong one at the worst moment.
     */
    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return array<string>|null */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->loaded('two_factor_recovery_codes');
    }

    /**
     * @param  array<string>|null  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['two_factor_recovery_codes' => $codes])->save();
    }

    /**
     * Whether this account still needs to set up 2FA before it may be used.
     *
     * Staff MUST have it (Blueprint §7.1). Returning true here is what the
     * middleware acts on to force enrolment rather than merely suggesting it.
     */
    public function mustEnrolInTwoFactor(): bool
    {
        return $this->type->requiresTwoFactor() && ! $this->hasTwoFactorEnabled();
    }

    /**
     * Filament calls this to decide who may open /admin.
     *
     * Three conditions, all required: the account is staff, it is active, and
     * it is not suspended. Capability *inside* the panel is a separate
     * permission question.
     */
    public function canAccessPanel(mixed $panel = null): bool
    {
        return $this->isStaff() && $this->is_active && ! $this->isSuspended();
    }

    // ── The messages this account sends about itself ─────────────────────────
    //
    // Both of these are overrides. Laravel's defaults push a Notification
    // through the mail channel directly, which would be a second way out of
    // this application — one with no suppression check, no email_logs row and
    // no share of the host's hourly cap. README calls that out as the rule the
    // whole communications module rests on, so the front door obeys it too.
    //
    // The practical consequence: a donor who marked us as spam and then asks to
    // reset their password gets a suppressed row explaining why nothing
    // arrived, rather than silence.

    public function sendEmailVerificationNotification(): void
    {
        app(AccountNotifier::class)->sendEmailVerification($this);
    }

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[SensitiveParameter] $token): void
    {
        app(AccountNotifier::class)->sendPasswordReset($this, $token);
    }

    /**
     * Change the password and tell the account holder it happened.
     *
     * One method rather than two calls at three call sites, because the
     * notification is the security control here — a password changed without
     * the owner being told is an account takeover nobody finds out about — and
     * a control that has to be remembered at every call site is one that will
     * eventually be forgotten at one of them.
     */
    public function changePassword(#[SensitiveParameter] string $plain): void
    {
        $this->forceFill([
            'password' => $plain,
            // Every other session holding the old remember cookie stops working.
            // If the reason for this change is a stolen password, whoever stole
            // it is still signed in until this line runs.
            'remember_token' => Str::random(60),
        ])->save();

        app(AccountNotifier::class)->sendPasswordChanged($this);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    #[Scope]
    protected function staff(Builder $query): void
    {
        $query->where('type', UserType::Staff);
    }

    #[Scope]
    protected function donors(Builder $query): void
    {
        $query->where('type', UserType::Donor);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true)->whereNull('suspended_at');
    }

    // ── Activity log ─────────────────────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // Deliberately narrow. Logging every column would put password
            // hashes and 2FA ciphertext into the activity log, which is a
            // second copy of the most sensitive data in the system.
            ->logOnly([
                'name',
                'email',
                'phone',
                'type',
                'is_active',
                'suspended_at',
                'suspended_reason',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('user');
    }
}
