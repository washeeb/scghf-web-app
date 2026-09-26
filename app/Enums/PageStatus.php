<?php

declare(strict_types=1);

namespace App\Enums;

enum PageStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    /**
     * Whether this status can EVER be publicly visible.
     *
     * Scheduled counts: a scheduled page becomes live the moment its
     * `published_at` passes, without anyone touching it. The date check lives
     * on the model — this only answers the status half.
     */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Published || $this === self::Scheduled;
    }

    public function colour(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Scheduled => 'warning',
            self::Published => 'success',
            self::Archived => 'danger',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
