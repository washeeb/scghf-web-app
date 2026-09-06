<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ProjectStatus;
use App\Models\Concerns\HasSeo;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A named piece of work: a start, a budget, locations, beneficiaries.
 *
 * Deliberately separate from `Cause`, which is the FUNDRAISING unit. A cause
 * can fund several projects, and a project can run with no public appeal behind
 * it — collapsing the two would make every donation report ambiguous about
 * whether it was describing money raised or work done.
 *
 * @property ProjectStatus $status
 */
class Project extends Model
{
    use HasFactory;
    use HasSeo;
    use HasUlids;
    use LogsActivity;
    use RecordsAuthor;
    use SoftDeletes;

    protected $fillable = [
        'division_id', 'title', 'slug', 'summary', 'description', 'status',
        'starts_on', 'ends_on', 'completed_on', 'budget', 'currency',
        'featured_image_id', 'lead_id', 'is_featured', 'is_published', 'published_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'planned',
        'currency' => 'GHS',
        'beneficiary_count' => 0,
        'is_featured' => false,
        'is_published' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'completed_on' => 'date',
            'published_at' => 'datetime',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
            // The attribute is `budget`; the column is `budget_minor`. Every
            // amount column carries the suffix so nothing in the schema reads
            // like a decimal.
            'budget' => MoneyCast::class.':budget_minor,currency',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $project): void {
            if (blank($project->slug)) {
                $project->slug = Str::slug($project->title);
            }

            $project->slug = Str::slug($project->slug);

            /*
             * A completed project carries the date it completed. Setting the
             * status without the date leaves "when did this finish?" answerable
             * only by reading the activity log, and it is the first thing a
             * report asks.
             */
            if ($project->status === ProjectStatus::Completed && $project->completed_on === null) {
                $project->completed_on = $project->ends_on ?? now()->toDateString();
            }
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

    // ── Relationships ────────────────────────────────────────────────────────

    /** @return BelongsTo<Division, $this> */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /** @return BelongsToMany<FocusArea, $this> */
    public function focusAreas(): BelongsToMany
    {
        return $this->belongsToMany(FocusArea::class);
    }

    /** @return BelongsToMany<Partner, $this> */
    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(Partner::class)->withPivot('role');
    }

    /** @return BelongsToMany<Document, $this> */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class)
            ->withPivot('sort_order')
            ->orderBy('document_project.sort_order');
    }

    /**
     * The appeals raising money for this project.
     *
     * The inverse of `Cause::project()`, which has existed since Phase 3 with
     * nothing able to walk it the other way — so a project page had no way to
     * ask what somebody could give to. A page describing work with no way to
     * support it has told a visitor what to care about and then stopped.
     *
     * @return HasMany<Cause, $this>
     */
    public function causes(): HasMany
    {
        return $this->hasMany(Cause::class);
    }

    /** @return HasMany<ProjectUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(ProjectUpdate::class);
    }

    /** @return HasMany<ProjectMilestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('sort_order');
    }

    /** @return HasMany<ProjectLocation, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(ProjectLocation::class)->orderBy('sort_order');
    }

    /** @return BelongsTo<Media, $this> */
    public function featuredImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'featured_image_id');
    }

    /** @return BelongsTo<User, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    /** @return MorphToMany<Tag, $this> */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    // ── Publication ──────────────────────────────────────────────────────────

    public function isLive(): bool
    {
        if (! $this->is_published || ! $this->status->isPubliclyListable()) {
            return false;
        }

        return $this->published_at === null || $this->published_at->isPast();
    }

    public function publish(?\DateTimeInterface $at = null): void
    {
        $this->forceFill([
            'is_published' => true,
            'published_at' => $at ?? now(),
        ])->save();
    }

    // ── Reporting ────────────────────────────────────────────────────────────

    /**
     * The primary site, for a single map pin or a one-line location.
     *
     * Falls back to the first location rather than returning null, because a
     * project with locations but none flagged primary is a data-entry gap, not
     * a project with no location.
     */
    public function primaryLocation(): ?ProjectLocation
    {
        return $this->locations->firstWhere('is_primary', true) ?? $this->locations->first();
    }

    public function progressPercent(): ?int
    {
        if ($this->starts_on === null || $this->ends_on === null) {
            return null;
        }

        if ($this->status === ProjectStatus::Completed) {
            return 100;
        }

        $total = $this->starts_on->diffInDays($this->ends_on);

        if ($total <= 0) {
            return null;
        }

        $elapsed = $this->starts_on->diffInDays(now(), false);

        return (int) max(0, min(100, round($elapsed / $total * 100)));
    }

    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('is_published', true)
            ->where('status', '!=', ProjectStatus::Planned->value)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    #[Scope]
    protected function ongoing(Builder $query): void
    {
        $query->whereIn('status', [
            ProjectStatus::Planned,
            ProjectStatus::Active,
            ProjectStatus::Paused,
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'slug', 'status', 'division_id', 'is_published', 'budget_minor'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('project');
    }
}
