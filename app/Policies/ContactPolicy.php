<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Contact enquiries and the departments they route to.
 *
 * ── A safeguarding report is not an inbox item ──────────────────────────────
 *
 * `contact_departments.is_confidential` exists so that a report about a child
 * does not appear in the general admin inbox beside shop queries. The column
 * has been there since Phase 3 and, until the inbox screen, nothing consulted
 * it — so the routing decision it encodes had no effect on who could read what.
 *
 * `contact.view_safeguarding` is a separate permission for exactly this, and it
 * is granted to a much smaller group than `contact.view`. Reading a
 * confidential message needs both.
 */
class ContactPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'contact';
    }

    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) && $this->maySee($user, $model);
    }

    public function update(User $user, Model $model): bool
    {
        return parent::update($user, $model) && $this->maySee($user, $model);
    }

    /**
     * Answering the sender.
     *
     * Separate from `update`, because handling an enquiry internally — noting
     * it, passing it to a colleague — is a different act from sending an email
     * from the foundation's address to a member of the public.
     */
    public function reply(User $user, Model $model): bool
    {
        return $this->permits($user, 'reply') && $this->maySee($user, $model);
    }

    /** Whether this person may see this particular message at all. */
    private function maySee(User $user, Model $model): bool
    {
        if (! $model instanceof ContactMessage || ! $model->isConfidential()) {
            return true;
        }

        return $this->permitsExactly($user, 'view_safeguarding');
    }
}
