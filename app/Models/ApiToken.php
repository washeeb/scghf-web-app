<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * A credential for a future mobile app or integration.
 *
 * Small table, strict rules, all of them the same rule in different clothes:
 * **a credential nobody can account for is a credential nobody can revoke.**
 *
 *   - the plaintext is shown ONCE and never stored, so a leaked database is not
 *     a leaked set of live credentials
 *   - abilities default to NONE, so a token created without thinking can do
 *     nothing
 *   - `expires_at` is NOT NULL, so nothing becomes a permanent credential in a
 *     config file on a laptop that left the organisation three years ago
 *   - anything touching beneficiaries, safeguarding or money is forbidden
 *     outright — those are decisions a person makes while logged in, not
 *     something an integration does unattended
 */
class ApiToken extends Model
{
    use HasFactory;
    use HasUlids;
    use RecordsAuthor;

    /** How many characters of the token are kept in clear, for identification. */
    private const PREFIX_LENGTH = 12;

    protected $fillable = [
        'name', 'user_id', 'abilities', 'allowed_ips',
        'expires_at', 'rate_limit_per_minute', 'created_by',
    ];

    /**
     * The plaintext, available only on the instance that just created it.
     *
     * Not a column, not persisted, gone the moment the request ends. This is
     * the only opportunity anybody ever has to read it.
     */
    public ?string $plainTextToken = null;

    /** @var array<string, mixed> */
    protected $attributes = [
        'use_count' => 0,
        'rate_limit_per_minute' => 60,
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'allowed_ips' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'use_count' => 'integer',
            'rate_limit_per_minute' => 'integer',
        ];
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Issuing ──────────────────────────────────────────────────────────────

    /**
     * Mint a token. The returned instance carries the plaintext; nothing else
     * ever will.
     *
     * @param  array<int, string>  $abilities
     * @param  array<int, string>|null  $allowedIps
     */
    public static function issue(
        string $name,
        User $user,
        array $abilities = [],
        ?int $lifetimeDays = null,
        ?array $allowedIps = null,
        ?User $creator = null,
    ): self {
        self::assertAbilitiesArePermitted($abilities);

        $lifetime = $lifetimeDays ?? (int) config('system.api.default_token_lifetime_days', 90);
        $max = (int) config('system.api.max_token_lifetime_days', 365);

        if ($lifetime < 1 || $lifetime > $max) {
            throw new InvalidArgumentException(
                "A token may live between 1 and {$max} days. Nothing here lives for ever — "
                .'a credential with no expiry is one nobody remembers issuing.'
            );
        }

        // 40 random bytes. Long enough that a dictionary attack is not a
        // concept that applies, which is why the hash below can be SHA-256
        // rather than something deliberately slow.
        $plain = Str::random(48);

        $token = new self;

        $token->forceFill([
            'name' => $name,
            'user_id' => $user->getKey(),
            'token_hash' => self::hash($plain),
            'prefix' => substr($plain, 0, self::PREFIX_LENGTH),
            'abilities' => array_values($abilities),
            'allowed_ips' => $allowedIps,
            'expires_at' => now()->addDays($lifetime),
            'created_by' => $creator?->getKey() ?? $user->getKey(),
        ])->save();

        $token->plainTextToken = $plain;

        return $token;
    }

    /**
     * Find the live token matching a presented secret.
     *
     * Looks up by hash rather than comparing rows, so this is one indexed
     * lookup regardless of how many tokens exist — and it never has the
     * plaintext of any other token to compare against, because none is stored.
     */
    public static function findByToken(string $plain): ?self
    {
        return static::query()->where('token_hash', self::hash($plain))->first();
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    // ── Using ────────────────────────────────────────────────────────────────

    /**
     * Why this token may not be used, or null if it may.
     *
     * A reason rather than a boolean, because the answer goes into an audit
     * entry — "rejected" is not something anybody can act on, and a run of
     * "expired 4 days ago" is a different problem from a run of "wrong IP".
     */
    public function rejectionReason(?string $fromIp = null): ?string
    {
        if ($this->revoked_at !== null) {
            return 'This token was revoked on '.$this->revoked_at->format('j M Y')
                .($this->revoke_reason !== null ? ': '.$this->revoke_reason : '.');
        }

        if ($this->expires_at->isPast()) {
            return 'This token expired on '.$this->expires_at->format('j M Y').'.';
        }

        $allowed = $this->allowed_ips ?? [];

        if ($allowed !== [] && $fromIp !== null && ! in_array($fromIp, $allowed, true)) {
            return 'This token is restricted to specific addresses and was presented from '
                .'another one.';
        }

        return null;
    }

    public function isUsable(?string $fromIp = null): bool
    {
        return $this->rejectionReason($fromIp) === null;
    }

    /**
     * Whether this token may do a particular thing.
     *
     * Deny by default, and no wildcard. A `*` ability would defeat the whole
     * arrangement the first time somebody was in a hurry.
     */
    public function can(string $ability): bool
    {
        return $this->isUsable() && in_array($ability, $this->abilities ?? [], true);
    }

    /**
     * Note that the token was used.
     *
     * Written with a direct update rather than a save so it cannot fire model
     * events or trip strict-mode checks on a hot path — every API request goes
     * through here.
     */
    public function markUsed(?string $ip = null): void
    {
        static::whereKey($this->getKey())->update([
            'last_used_at' => now(),
            'last_used_ip' => $ip,
            'use_count' => $this->use_count + 1,
            'updated_at' => now(),
        ]);
    }

    public function revoke(User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Revoking a token needs a reason.');
        }

        $this->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $actor->getKey(),
            'revoke_reason' => $reason,
        ])->save();
    }

    /**
     * Tokens expiring soon, for the dashboard.
     *
     * Because the failure mode of a mandatory expiry is an integration that
     * stops working on a Saturday with nobody knowing why. A warning three
     * weeks out turns that into a Tuesday afternoon task.
     */
    #[Scope]
    protected function expiringSoon(Builder $query, int $days = 21): void
    {
        $query->whereNull('revoked_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }

    #[Scope]
    protected function live(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function expiresIn(): ?Carbon
    {
        return $this->revoked_at !== null ? null : $this->expires_at;
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private static function assertAbilitiesArePermitted(array $abilities): void
    {
        /** @var array<string, string> $known */
        $known = config('system.api.abilities', []);
        /** @var array<int, string> $forbidden */
        $forbidden = config('system.api.forbidden_ability_prefixes', []);

        foreach ($abilities as $ability) {
            foreach ($forbidden as $prefix) {
                if (str_starts_with($ability, $prefix)) {
                    throw new RuntimeException(
                        "The ability [{$ability}] can never be granted to a token. Beneficiary "
                        .'data, safeguarding records, donor details and anything that moves money '
                        .'are decisions a person makes while logged in, not something an '
                        .'integration does unattended.'
                    );
                }
            }

            if (! array_key_exists($ability, $known)) {
                throw new InvalidArgumentException(
                    "Unknown ability [{$ability}]. Abilities are declared in config/system.php — "
                    .'a typo that silently granted nothing would look like it worked until the '
                    .'day it mattered.'
                );
            }
        }
    }
}
