<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToDivision;
use App\Models\Concerns\HasConsents;
use App\Models\Concerns\HasSeo;
use App\Models\Concerns\RecordsAuthor;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A beneficiary's story, told publicly.
 *
 * **Consent-gated, in code.** A story cannot be published without a valid story
 * consent; adding a photograph requires a photo consent as well; publishing the
 * person's real name requires a name-use consent on top of both. Revoking any
 * of them unpublishes the story.
 *
 * The gate is a model-level refusal rather than a form validation rule, so it
 * holds however the row was changed — an import, a console command, a Filament
 * action somebody added later.
 *
 * @see Consent
 */
class Story extends Model
{
    use BelongsToDivision;
    use HasConsents;
    use HasFactory;
    use HasSeo;
    use HasUlids;
    use LogsActivity;
    use RecordsAuthor;
    use SoftDeletes;

    protected $fillable = [
        'beneficiary_id', 'division_id', 'project_id', 'title', 'slug',
        'summary', 'body', 'subject_display_name', 'uses_pseudonym',
        'image_id', 'is_featured', 'is_published', 'published_at', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'uses_pseudonym' => false,
        'is_featured' => false,
        'is_published' => false,
    ];

    protected function casts(): array
    {
        return [
            'uses_pseudonym' => 'boolean',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $story): void {
            $story->slug = Slug::for($story->slug, $story->title);

            /*
             * The gate, enforced on every save rather than only in publish().
             * Otherwise `$story->update(['is_published' => true])` — the most
             * natural thing an admin action would do — walks straight past it.
             */
            if ($story->is_published) {
                $story->assertPublishable();
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

    /** @return BelongsTo<Beneficiary, $this> */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_id');
    }

    // ── Consent ──────────────────────────────────────────────────────────────

    /**
     * What this particular story needs consent for.
     *
     * Derived from the story itself, not from a fixed list: a story with no
     * photograph does not need a photo consent, and demanding one would make an
     * anonymous account impossible to publish.
     *
     * @return array<int, string>
     */
    public function requiredConsentTypes(): array
    {
        $required = [Consent::TYPE_STORY];

        if ($this->image_id !== null) {
            $required[] = Consent::TYPE_PHOTO;
        }

        // A pseudonym needs no name-use consent — that is the point of one.
        if (! $this->uses_pseudonym && filled($this->subject_display_name)) {
            $required[] = Consent::TYPE_NAME_USE;
        }

        return $required;
    }

    /**
     * Consents held by this story, or by the beneficiary it is about.
     *
     * A consent form signed once at intake covers the stories that follow, so
     * the beneficiary's own consents count here. Recording the same permission
     * again on every story would be a worse record, not a better one.
     */
    public function hasConsentFor(string $type, string $scope = Consent::SCOPE_WEBSITE): bool
    {
        if ($this->consents
            ->where('consent_type', $type)
            ->contains(fn (Consent $c): bool => $c->isValid() && $c->coversScope($scope))
        ) {
            return true;
        }

        return $this->beneficiary?->hasConsentFor($type, $scope) ?? false;
    }

    public function assertPublishable(string $scope = Consent::SCOPE_WEBSITE): void
    {
        $missing = $this->missingConsents($scope);

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(
            'This story cannot be published without consent for: '.implode(', ', $missing).'. '
            .'Record the signed consent against the beneficiary or the story first.'
        );
    }

    public function publish(?\DateTimeInterface $at = null): void
    {
        $this->assertPublishable();

        $this->forceFill([
            'is_published' => true,
            'published_at' => $at ?? now(),
        ])->save();
    }

    /**
     * Take the story down.
     *
     * What happens when consent is revoked — and it must be possible without
     * the save hook refusing, which is why it writes `is_published` false
     * first.
     */
    public function unpublish(string $reason = ''): void
    {
        $this->forceFill([
            'is_published' => false,
            'published_at' => null,
        ])->save();

        if ($reason !== '') {
            activity('story')->performedOn($this)->log($reason);
        }
    }

    public function isLive(): bool
    {
        return $this->is_published
            && $this->hasAllRequiredConsents()
            && ($this->published_at === null || $this->published_at->isPast());
    }

    /** The name to show — the pseudonym, the real name, or nothing. */
    public function displayName(): ?string
    {
        if (blank($this->subject_display_name)) {
            return null;
        }

        if ($this->uses_pseudonym) {
            return $this->subject_display_name;
        }

        // Belt and braces: a name-use consent revoked after publication must
        // stop the name appearing even before anyone reruns the audit.
        return $this->hasConsentFor(Consent::TYPE_NAME_USE) ? $this->subject_display_name : null;
    }

    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('is_published', true)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->orderByDesc('published_at');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'slug', 'is_published', 'published_at', 'beneficiary_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('story');
    }
}
