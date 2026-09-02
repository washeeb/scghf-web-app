<?php

declare(strict_types=1);

namespace App\Enums;

enum LoginOutcome: string
{
    case Success = 'success';
    case Failed = 'failed';
    case LockedOut = 'locked_out';
    case TwoFactorFailed = 'two_factor_failed';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Successful sign-in',
            self::Failed => 'Failed sign-in',
            self::LockedOut => 'Locked out',
            self::TwoFactorFailed => 'Two-factor failed',
        };
    }

    public function isSuccessful(): bool
    {
        return $this === self::Success;
    }

    /** Outcomes worth alerting on when they cluster from one IP. */
    public function isSuspicious(): bool
    {
        return $this !== self::Success;
    }
}
