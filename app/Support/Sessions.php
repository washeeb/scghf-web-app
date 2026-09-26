<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Other people's sessions, ended.
 *
 * The session driver is the database (shared hosting: no Redis), which
 * means every live session is a row with a `user_id`, and ending somebody's
 * sessions everywhere is deleting those rows. The remember-me cookie is
 * separate: rotating `remember_token` is what stops a "keep me signed in"
 * cookie re-creating a session on the next visit.
 *
 * Nothing here needs the password. `Auth::logoutOtherDevices()` does, and
 * an administrator ending a departed colleague's sessions does not have it.
 */
final class Sessions
{
    /** End every session for this user except the one given. Returns how many. */
    public static function revokeAll(User $user, ?string $exceptSessionId = null): int
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        if (! self::hasTable()) {
            return 0;
        }

        return DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($exceptSessionId !== null, fn ($q) => $q->where('id', '!=', $exceptSessionId))
            ->delete();
    }

    /** How many sessions this user has open right now. */
    public static function countFor(User $user): int
    {
        if (! self::hasTable()) {
            return 0;
        }

        return DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime', 120))->timestamp)
            ->count();
    }

    /**
     * The sessions table exists whatever driver is configured — it is in the
     * migrations — so it is the table, not the driver, that decides whether
     * there is anything to delete.
     */
    private static function hasTable(): bool
    {
        return Schema::hasTable((string) config('session.table', 'sessions'));
    }
}
