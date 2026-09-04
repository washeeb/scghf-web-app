<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * FAQs and their categories.
 */
class FaqPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'faqs';
    }
}
