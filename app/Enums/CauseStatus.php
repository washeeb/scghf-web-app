<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The life of a fundraising appeal.
 *
 * A backed string enum over `VARCHAR(32)`, never a MySQL ENUM — altering one
 * rewrites the table, and adding a status should not need a maintenance window.
 */
enum CauseStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
        };
    }

    /**
     * Whether this appeal may take a donation right now.
     *
     * Paused is deliberately false and Completed is deliberately false: a
     * closed appeal that still takes money is how a foundation ends up holding
     * funds it has already told donors it stopped raising.
     */
    public function acceptsDonations(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether it may be shown at all.
     *
     * A completed appeal stays visible — its total and its updates are the
     * evidence the money did something. Archived is the one that disappears.
     */
    public function isPubliclyListable(): bool
    {
        return in_array($this, [self::Active, self::Paused, self::Completed], true);
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
