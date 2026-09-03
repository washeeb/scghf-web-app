<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The life of a project.
 *
 * A backed string enum over a `VARCHAR(32)` column, never a MySQL ENUM —
 * altering a MySQL ENUM rewrites the whole table, and adding a status should
 * not be a maintenance window.
 */
enum ProjectStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether the project is currently doing anything.
     *
     * Drives the "our work right now" listings, and separates a project that
     * has stalled from one that finished — a distinction donors care about and
     * a single `is_active` boolean cannot express.
     */
    public function isOngoing(): bool
    {
        return in_array($this, [self::Planned, self::Active, self::Paused], true);
    }

    /** Whether the project may still take new beneficiaries. */
    public function acceptsBeneficiaries(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether this status may be shown publicly at all.
     *
     * A cancelled project is not hidden — quietly deleting work that was
     * announced and funded is exactly the opacity a foundation should avoid —
     * but it is never featured.
     */
    public function isPubliclyListable(): bool
    {
        return $this !== self::Planned;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static fn (array $carry, self $case): array => $carry + [$case->value => $case->label()],
            [],
        );
    }
}
