<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoginOutcome;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One authentication attempt. Append-only — see the migration for why.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $email_attempted
 * @property LoginOutcome $outcome
 */
class LoginHistory extends Model
{
    /** Only `created_at` exists; a login attempt is never updated. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'email_attempted',
        'outcome',
        'ip_address',
        'user_agent',
        'device_type',
        'platform',
        'browser',
        'country_code',
        'was_two_factor_used',
        'is_new_device',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => LoginOutcome::class,
            'was_two_factor_used' => 'boolean',
            'is_new_device' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    #[Scope]
    protected function successful(Builder $query): void
    {
        $query->where('outcome', LoginOutcome::Success);
    }

    #[Scope]
    protected function failed(Builder $query): void
    {
        $query->where('outcome', '!=', LoginOutcome::Success);
    }

    /** Recent failures from one IP — what a brute-force check asks for. */
    #[Scope]
    protected function recentFailuresFrom(Builder $query, string $ip, int $minutes = 15): void
    {
        $query->where('ip_address', $ip)
            ->where('outcome', '!=', LoginOutcome::Success)
            ->where('created_at', '>=', now()->subMinutes($minutes));
    }
}
