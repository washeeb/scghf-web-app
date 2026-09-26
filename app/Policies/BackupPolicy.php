<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Backup runs and restore tests.
 *
 * Read-only as records. Running a backup and restoring one are actions rather
 * than model writes, and carry their own permissions (`backups.run`,
 * `backups.restore`) — the latter withheld even from Admin.
 */
class BackupPolicy extends ReadOnlyPolicy
{
    protected function prefix(): string
    {
        return 'backups';
    }
}
