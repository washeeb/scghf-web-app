<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToDivision;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Partner extends Model
{
    use BelongsToDivision;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'division_id', 'name', 'slug', 'description', 'website_url', 'logo_id',
        'partner_type', 'partnership_started_on', 'partnership_ended_on',
        'sort_order', 'is_published', 'is_featured',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'partner_type' => 'organisation', 'sort_order' => 0,
        'is_published' => true, 'is_featured' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
            'partnership_started_on' => 'date',
            'partnership_ended_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $p) => $p->slug = Slug::for($p->slug, $p->name));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<Media, $this> */
    public function logo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'logo_id');
    }

    /** A partnership with an end date in the past is former, not current. */
    public function isCurrent(): bool
    {
        return $this->partnership_ended_on === null || $this->partnership_ended_on->isFuture();
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true)->orderBy('sort_order');
    }

    #[Scope]
    protected function current(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('partnership_ended_on')
            ->orWhere('partnership_ended_on', '>', now()));
    }
}
