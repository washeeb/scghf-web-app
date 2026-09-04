<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Beneficiary stories. Publication is consent-gated in the model as well as permission-gated here.
 */
class StoryPolicy extends PublishablePolicy
{
    protected function prefix(): string
    {
        return 'stories';
    }
}
