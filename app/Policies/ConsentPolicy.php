<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Recorded permission to use somebody's photograph, story, video or name.
 *
 * ── A consent record is evidence, so it is not editable ─────────────────────
 *
 * The whole value of this table is being able to show, two years later, exactly
 * what somebody agreed to and when. A consent that can be edited proves
 * nothing: "we had permission" becomes an assertion about the current contents
 * of a row rather than a record of what happened.
 *
 * So consent is RECORDED and REVOKED, never amended. Getting it wrong means
 * capturing a new one, which is also what would happen on paper.
 *
 * ── Revocation is not deletion ──────────────────────────────────────────────
 *
 * Somebody withdrawing consent must leave a trace: the foundation needs to be
 * able to show it stopped publishing on the day it was told to, and a deleted
 * row shows nothing at all. `Consent::revoke()` sets a date; nothing removes
 * the row.
 */
class ConsentPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'consents';
    }

    /**
     * Never. A consent record is amended by capturing a new one, exactly as it
     * would be on paper.
     */
    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    /**
     * Withdrawing a consent. The action that replaces editing it.
     *
     * Held to `consents.manage` rather than to a viewer's permission: taking a
     * photograph down is a decision, and the person who made it should be
     * recorded on the row.
     */
    public function revoke(User $user, Model $model): bool
    {
        return $this->permits($user, 'manage');
    }
}
