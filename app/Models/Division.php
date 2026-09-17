<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasSeo;
use App\Models\Concerns\RecordsAuthor;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A division of the foundation's work.
 *
 * The spine of the whole application. Projects, causes, donations, impact
 * figures and most CMS content are either scoped to a division or are
 * foundation-wide, and everything expresses that as a nullable `division_id`
 * where null means the whole foundation.
 *
 * Seeded divisions are LOCKED. Years of donations, projects and reporting hang
 * off them; deleting one would strand the lot behind a null, and the reports
 * that mattered most would be the ones that broke.
 */
class Division extends Model
{
    use HasFactory;
    use HasSeo;
    use HasUlids;
    use LogsActivity;
    use RecordsAuthor;
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'tagline', 'summary', 'description',
        'colour_token', 'icon', 'logo_id', 'hero_image_id',
        'sort_order', 'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
        'is_locked' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $division): void {
            $division->slug = Slug::for($division->slug, $division->name);
        });

        static::deleting(function (self $division): void {
            if ($division->is_locked) {
                throw new RuntimeException(
                    "The [{$division->name}] division is part of the foundation's structure and "
                    .'cannot be deleted. Deactivate it instead — projects, causes, donations and '
                    .'years of reporting are attached to it.'
                );
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

    /** @return HasMany<FocusArea, $this> */
    public function focusAreas(): HasMany
    {
        return $this->hasMany(FocusArea::class)->orderBy('sort_order');
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function logo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'logo_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function heroImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'hero_image_id');
    }

    // ── Behaviour ────────────────────────────────────────────────────────────

    /**
     * The theme token this division is coloured with.
     *
     * Returns a token NAME, never a colour value. Resolving it to a hex is the
     * theme layer's job, which is where the AA contrast check lives — a raw hex
     * returned here would bypass that check entirely.
     */
    public function colourToken(): string
    {
        return $this->colour_token ?? 'color-brand-primary';
    }

    /**
     * Deactivate rather than delete.
     *
     * The honest operation for a division that has wound down: it disappears
     * from navigation and from new donation forms, while every project,
     * donation and receipt that references it stays intact and reportable.
     */
    public function deactivate(): void
    {
        $this->forceFill(['is_active' => false])->save();
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'is_active', 'sort_order', 'colour_token'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('division');
    }
}
