<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Testimonials shown on the public site.
 */
class TestimonialPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'testimonials';
    }
}
