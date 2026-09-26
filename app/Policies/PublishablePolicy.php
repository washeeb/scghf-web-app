<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Content where writing it and publishing it are different jobs.
 *
 * Pages and blog posts both carry a `.publish` permission distinct from
 * `.update`, and that separation is the whole reason this archetype exists: a
 * volunteer contributor can draft, and somebody accountable decides what the
 * public sees under the foundation's name.
 *
 * `publish` is a first-class ability rather than a check buried in a Filament
 * action, so the same rule holds however the record is saved.
 */
abstract class PublishablePolicy extends BasePolicy
{
    public function publish(User $user, Model $model): bool
    {
        return $this->permitsExactly($user, 'publish');
    }

    /**
     * Unpublishing is the same permission as publishing.
     *
     * Somebody able to put a page in front of the public must be able to take
     * it down again — the alternative is an editor watching something they know
     * is wrong stay up while they look for whoever can remove it.
     */
    public function unpublish(User $user, Model $model): bool
    {
        return $this->publish($user, $model);
    }
}
