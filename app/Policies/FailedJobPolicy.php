<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The failed-jobs list. One permission, `queue.manage`, for seeing,
 * retrying and discarding — a failed job is an operational fact, not a
 * record anybody edits.
 */
class FailedJobPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'queue';
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->permits($user, 'manage');
    }
}
