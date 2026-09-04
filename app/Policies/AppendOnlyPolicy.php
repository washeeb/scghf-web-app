<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A financial record. It can be created and read; it cannot be deleted.
 *
 * ── Why deletion is refused here as well as in the model ────────────────────
 *
 * `donations`, `payment_transactions`, `receipts`, `payouts` and the rest are
 * append-only — the design document says so, the migrations carry no
 * `deleted_at`, and the models refuse it. So why say it again in a policy?
 *
 * Because the model guard and the authorisation layer answer different
 * questions. The model answers "can this row be removed?" at the moment
 * something tries. The policy answers "should this person be shown a delete
 * button?" — and a button that appears, is clicked, and then throws an
 * exception is a worse experience than one that was never there, and it trains
 * staff to expect errors.
 *
 * More importantly: Filament builds its bulk actions from the policy. Without
 * this, a "delete selected" checkbox appears on the donations table for anybody
 * holding a broad `.manage` grant, and the first person to try it gets a stack
 * trace after selecting forty rows.
 *
 * ── Update is still allowed ─────────────────────────────────────────────────
 *
 * Because the models permit specific, narrow updates — settling a transaction,
 * attaching a receipt, annotating a donation for Finance. What they refuse is
 * changing what happened. That distinction belongs in the model, which knows
 * which columns are which; the policy cannot.
 */
abstract class AppendOnlyPolicy extends BasePolicy
{
    /**
     * Never, by anybody, whatever permissions they hold.
     *
     * Not `$user->can('...delete')` — there is no permission that grants this,
     * and adding one would be adding the ability to rewrite the ledger.
     */
    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        // Nothing is ever soft-deleted here, so there is nothing to restore.
        return false;
    }
}
