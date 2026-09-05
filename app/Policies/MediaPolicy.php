<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The media library.
 *
 * ── Why this overrides the base ─────────────────────────────────────────────
 *
 * The permission set here is `media.view`, `media.upload`, `media.delete` —
 * there is no `media.create`, no `media.update` and no `media.manage`.
 *
 * `BasePolicy::create()` tries create, then update, then manage, and
 * `update()` tries update then manage. None of those five exist, and the base
 * denies by default — correctly, and with the consequence that NOBODY could
 * upload a file or write its alt text. This class previously carried a comment
 * claiming the base resolved `upload` "through its create/update fallback",
 * which it does not: `upload` is not in that chain.
 *
 * The effect was invisible in the way that matters. A permission was seeded, a
 * role held it, and the policy that was supposed to honour it silently answered
 * no — so the library would have looked correctly permissioned to anybody
 * auditing the seeder, and refused everybody at the point of use.
 *
 * ── Uploading and describing are one job ────────────────────────────────────
 *
 * `media.upload` grants both, and that is deliberate rather than lazy.
 * `Media::isPublishable()` refuses any image with no alt text, so somebody who
 * may add a file and may not describe it can only ever add files that cannot be
 * used. Splitting the two would produce a role whose whole output is unusable.
 *
 * Deleting stays separate — it has its own permission, and its own reasons.
 */
class MediaPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'media';
    }

    /** Adding a file to the library. */
    public function create(User $user): bool
    {
        return $this->permits($user, 'upload');
    }

    /**
     * Editing alt text, caption, credit and folder — and replacing the file.
     *
     * Replacing is an update rather than a delete-and-create because the row
     * keeps its id and every reference to it follows. It is the safe operation,
     * so gating it behind `media.delete` would push people towards the unsafe
     * one.
     */
    public function update(User $user, Model $model): bool
    {
        return $this->permits($user, 'upload');
    }
}
