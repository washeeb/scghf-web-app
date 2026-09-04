<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A record the application writes and a person only reads.
 *
 * Logs, delivery records, webhook events, visitor counts. Their value is that
 * they are an account of what happened, and an account somebody can edit is not
 * an account.
 *
 * Distinct from AppendOnlyPolicy, which allows updates because those models
 * have a lifecycle a person legitimately advances — settling a transaction,
 * attaching a receipt. Nothing here has one.
 */
abstract class ReadOnlyPolicy extends BasePolicy
{
    public function create(User $user): bool
    {
        return false;
    }

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

    public function restore(User $user, Model $model): bool
    {
        return false;
    }
}
