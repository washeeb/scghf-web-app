<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToDivision;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * A quote from a beneficiary, volunteer, partner or donor.
 */
class Testimonial extends Model
{
    use BelongsToDivision;
    use HasFactory;
    use HasUlids;
    use RecordsAuthor;
    use SoftDeletes;

    protected $fillable = [
        'division_id', 'author_name', 'author_role', 'author_location', 'quote', 'author_type',
        'photo_id', 'has_consent', 'consent_date', 'sort_order', 'is_published', 'is_featured',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'author_type' => 'beneficiary', 'sort_order' => 0,
        'has_consent' => false, 'is_published' => false, 'is_featured' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'has_consent' => 'boolean',
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
            'consent_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        /*
         * A testimonial from a beneficiary is a story about a real person, very
         * often a vulnerable one. Publishing it without recorded consent is the
         * single most damaging thing this CMS could allow, so the gate is in
         * the model rather than in a form an admin might bypass.
         *
         * Partners and staff speak for themselves in a professional capacity
         * and are exempt.
         */
        static::saving(function (self $t): void {
            $needsConsent = in_array($t->author_type, ['beneficiary', 'volunteer', 'donor'], true);

            if ($t->is_published && $needsConsent && ! $t->has_consent) {
                throw new RuntimeException(
                    'This testimonial cannot be published without a recorded consent. '
                    .'Record the consent first, or mark the author as a partner or staff member.'
                );
            }
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<Media, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'photo_id');
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true)->orderBy('sort_order');
    }
}
