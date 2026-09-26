<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A page as it was before a save. Append-only.
 *
 * @property int $revision_number
 * @property string $snapshot
 */
class PageRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['page_id', 'user_id', 'revision_number', 'snapshot', 'summary', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{page: array<string, mixed>, sections: array<int, array<string, mixed>>} */
    public function decoded(): array
    {
        return json_decode($this->snapshot, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Restore the page to this revision.
     *
     * Snapshots the CURRENT state first, so restoring is itself undoable — an
     * editor who restores the wrong revision is not stuck.
     *
     * Sections are replaced wholesale rather than diffed: reconciling
     * additions, removals and reorderings is far more code and far more ways to
     * be subtly wrong than simply rebuilding from the snapshot.
     */
    public function restore(?User $user = null): Page
    {
        $page = $this->page;
        $data = $this->decoded();

        return DB::transaction(function () use ($page, $data, $user): Page {
            $page->snapshot("Before restoring revision {$this->revision_number}", $user);

            $page->forceFill($data['page'])->save();

            $page->sections()->delete();

            foreach ($data['sections'] as $section) {
                $page->sections()->create($section);
            }

            return $page->fresh();
        });
    }
}
