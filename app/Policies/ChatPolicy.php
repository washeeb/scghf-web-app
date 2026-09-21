<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Live chat conversations.
 *
 * `chat.view` reads the inbox, `chat.reply` answers in it, `chat.manage`
 * closes or reassigns a conversation somebody else is handling. Nothing is
 * ever deleted by hand — the retention run does that on schedule.
 */
class ChatPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'chat';
    }

    public function reply(User $user, Model $model): bool
    {
        return $this->permits($user, 'reply');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
