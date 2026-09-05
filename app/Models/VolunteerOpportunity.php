<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToDivision;
use App\Models\Concerns\HasSeo;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A role the foundation is recruiting for.
 *
 * `involves_vulnerable_contact` defaults to TRUE, and that default is the
 * design. For a foundation whose work is orphans, vulnerable children, widows
 * and the elderly, the safe assumption is that a role involves contact — and a
 * recruiter who is sure it does not has to say so deliberately. A default of
 * false would mean every role somebody forgot to configure skipped its checks.
 */
class VolunteerOpportunity extends Model
{
    use BelongsToDivision;
    use HasFactory;
    use HasSeo;
    use HasUlids;
    use RecordsAuthor;
    use SoftDeletes;

    public const PLACEMENT_OFFICE = 'office';

    public const PLACEMENT_FIELD = 'field';

    public const PLACEMENT_REMOTE = 'remote';

    public const PLACEMENT_EVENTS = 'events';

    protected $fillable = [
        'division_id', 'project_id', 'title', 'slug', 'summary', 'description',
        'requirements', 'involves_vulnerable_contact', 'placement_type',
        'location', 'region', 'time_commitment', 'positions_available',
        'starts_on', 'closes_on', 'is_published', 'published_at',
        'contact_user_id', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'involves_vulnerable_contact' => true,
        'placement_type' => self::PLACEMENT_FIELD,
        'positions_filled' => 0,
        'is_published' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'involves_vulnerable_contact' => 'boolean',
            'is_published' => 'boolean',
            'starts_on' => 'date',
            'closes_on' => 'date',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $opportunity): void {
            if (blank($opportunity->slug)) {
                $opportunity->slug = Str::slug($opportunity->title);
            }

            $opportunity->slug = Str::slug($opportunity->slug);
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<VolunteerApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(VolunteerApplication::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contact_user_id');
    }

    /** The checks an application for this role will have to satisfy. */
    public function requiredCheckLabels(): array
    {
        $key = $this->involves_vulnerable_contact
            ? 'compliance.safeguarding.required_checks'
            : 'compliance.safeguarding.basic_checks';

        $checks = (array) config($key, []);

        if (! $this->involves_vulnerable_contact) {
            return array_map(
                fn (string $type): string => (string) config(
                    "compliance.safeguarding.required_checks.{$type}.label",
                    $type,
                ),
                array_values($checks),
            );
        }

        return array_column($checks, 'label');
    }

    public function isOpen(): bool
    {
        if (! $this->is_published) {
            return false;
        }

        if ($this->closes_on !== null && $this->closes_on->endOfDay()->isPast()) {
            return false;
        }

        return $this->positions_available === null
            || $this->positions_filled < $this->positions_available;
    }

    public function remainingPositions(): ?int
    {
        return $this->positions_available === null
            ? null
            : max(0, $this->positions_available - $this->positions_filled);
    }

    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('is_published', true)
            ->where(fn (Builder $q) => $q->whereNull('closes_on')->orWhereDate('closes_on', '>=', now()))
            ->where(fn (Builder $q) => $q->whereNull('positions_available')
                ->orWhereColumn('positions_filled', '<', 'positions_available'));
    }

    /** Roles that will need the full check set. */
    #[Scope]
    protected function requiringFullChecks(Builder $query): void
    {
        $query->where('involves_vulnerable_contact', true);
    }
}
