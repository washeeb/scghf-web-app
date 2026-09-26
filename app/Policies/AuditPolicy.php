<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * The audit trail and its archives.
 *
 * Read-only for everybody, including Super Admin — the model refuses updates
 * and deletes outright, and the hash chain exists so that tidying an
 * inconvenient entry away is detectable.
 *
 * There is no `audit.manage` permission to fall back to, which is why this
 * extends ReadOnlyPolicy rather than relying on one not being granted.
 */
class AuditPolicy extends ReadOnlyPolicy
{
    protected function prefix(): string
    {
        return 'audit';
    }
}
