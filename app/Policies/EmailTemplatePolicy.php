<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Email templates. Locked ones refuse deactivation in the model.
 */
class EmailTemplatePolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'templates.email';
    }
}
