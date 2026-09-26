<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file on a grant — the proposal, the agreement, a report, a letter —
 * on the private disk, served only through the grant's signed link.
 */
class GrantDocument extends Model
{
    use HasUlids;

    public const KINDS = [
        'proposal' => 'Proposal',
        'agreement' => 'Agreement or contract',
        'budget' => 'Budget',
        'report' => 'Report',
        'correspondence' => 'Correspondence',
        'other' => 'Other',
    ];

    protected $fillable = ['grant_id', 'media_id', 'title', 'kind', 'uploaded_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['kind' => 'other'];

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Grant, $this> */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
