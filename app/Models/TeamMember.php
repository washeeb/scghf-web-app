<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToDivision;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TeamMember extends Model
{
    use BelongsToDivision;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'division_id', 'team_department_id', 'user_id', 'name', 'slug', 'role_title', 'bio',
        'photo_id', 'public_email', 'linkedin_url', 'member_type', 'is_trustee',
        'joined_on', 'left_on', 'sort_order', 'is_published',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'member_type' => 'staff',
        'is_trustee' => false,
        'sort_order' => 0,
        'is_published' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_trustee' => 'boolean',
            'is_published' => 'boolean',
            'joined_on' => 'date',
            'left_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $m) => $m->slug = Slug::for($m->slug, $m->name));
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

    /** @return BelongsTo<TeamDepartment, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(TeamDepartment::class, 'team_department_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'photo_id');
    }

    /** Someone who has left is history, not the current team. */
    public function isCurrent(): bool
    {
        return $this->left_on === null || $this->left_on->isFuture();
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true)->orderBy('sort_order');
    }

    #[Scope]
    protected function current(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('left_on')->orWhere('left_on', '>', now()));
    }

    #[Scope]
    protected function trustees(Builder $query): void
    {
        $query->where('is_trustee', true);
    }
}
