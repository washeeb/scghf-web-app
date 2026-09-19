<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RecordsAuthor;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organisation (or person) that gives grants — Wave 2 (1.6).
 *
 * Internal: a funder is never published. The public "partners" page is
 * the `partners` table; a funder who is also a public partner links to
 * that row, and the two are kept apart so a funder's contact and the
 * notes about them never reach a template.
 */
class Funder extends Model
{
    use HasFactory;
    use HasUlids;
    use RecordsAuthor;

    public const TYPES = [
        'foundation' => 'Foundation or trust',
        'government' => 'Government or agency',
        'corporate' => 'Company',
        'multilateral' => 'Multilateral or embassy',
        'church' => 'Church or diocese',
        'individual' => 'Individual',
        'other' => 'Other',
    ];

    protected $fillable = [
        'name', 'slug', 'funder_type', 'website_url', 'contact_name', 'contact_email',
        'contact_phone', 'notes', 'partner_id', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['funder_type' => 'foundation'];

    protected static function booted(): void
    {
        static::saving(fn (self $funder) => $funder->slug = Slug::for($funder->slug, $funder->name));
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<Grant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class);
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
