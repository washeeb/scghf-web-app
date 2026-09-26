<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Delivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Deliveries.
 *
 * The office sees and assigns (`deliveries.view`, `deliveries.assign`). A
 * courier holds `deliveries.courier` and may see only a delivery that is
 * theirs — their portal looks records up through that scope, and this
 * policy says the same thing for anything that asks. Never deleted: a
 * delivery is part of an order's history.
 */
class DeliveryPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'deliveries';
    }

    public function view(User $user, Model $model): bool
    {
        if ($model instanceof Delivery && $model->courier_id === $user->getKey() && $user->can('deliveries.courier')) {
            return true;
        }

        return parent::view($user, $model);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'assign');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->permits($user, 'assign');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
