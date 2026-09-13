<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Newsletters, campaigns and subscribers.
 *
 * The permissions are `newsletter.view`, `newsletter.draft` and
 * `newsletter.send` — named for the job, because writing a campaign and
 * being allowed to send one to four thousand people are different jobs.
 * The base policy would look for `.create` and `.update`, find neither,
 * and let nobody but Super Admin open the composer.
 */
class NewsletterPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'newsletter';
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'draft');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->permits($user, 'draft');
    }

    /** A draft can be thrown away by whoever may draft; sent campaigns are history. */
    public function delete(User $user, Model $model): bool
    {
        return $this->permits($user, 'draft');
    }
}
