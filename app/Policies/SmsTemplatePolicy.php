<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * SMS templates. Segment budget enforced in the model.
 */
class SmsTemplatePolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'templates.sms';
    }
}
