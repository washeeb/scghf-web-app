<?php

declare(strict_types=1);

namespace App\Communications\Contracts;

use App\Communications\SmsBalance;

/**
 * A gateway that can say what is left in the account.
 *
 * Providers count differently — mNotify and Arkesel in credits, Twilio in
 * dollars — so the answer carries its unit rather than pretending they are
 * the same number. Null means "could not be read", which is not "zero".
 */
interface ReportsBalance
{
    public function balance(): ?SmsBalance;
}
