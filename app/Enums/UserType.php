<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Staff and donors share the `users` table — one auth path, one 2FA
 * implementation, one password policy. This distinguishes them.
 *
 * It is NOT a permission. Capability comes from roles (spatie/laravel-permission)
 * and is always checked as a permission, never as a role or a type. This only
 * answers "which side of the application does this account belong to".
 */
enum UserType: string
{
    case Staff = 'staff';
    case Donor = 'donor';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff',
            self::Donor => 'Donor',
        };
    }

    /** Staff may reach /admin at all. Whether they can do anything there is a permission question. */
    public function canAccessAdminPanel(): bool
    {
        return $this === self::Staff;
    }

    /** Two-factor is mandatory for staff, optional (encouraged) for donors. Blueprint §7.1. */
    public function requiresTwoFactor(): bool
    {
        return $this === self::Staff;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
