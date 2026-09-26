<?php

declare(strict_types=1);

namespace App\Communications\Exceptions;

use RuntimeException;

/**
 * A template could not be rendered because a required variable had no value.
 *
 * Thrown rather than swallowed. A message that goes out saying "Dear ," or
 * "Thank you for your gift of ." cannot be recalled; a job that fails on the
 * queue can be fixed and retried. Loud is the cheaper failure.
 */
class UnresolvedVariable extends RuntimeException
{
    /** @param array<int, string> $names */
    public static function forNames(array $names): self
    {
        return new self(sprintf(
            'Refusing to send: required template %s %s had no value. '
            .'A message with a blank where the amount, the name or the reference should be '
            .'is worse than a message that failed to send.',
            count($names) === 1 ? 'variable' : 'variables',
            implode(', ', array_map(fn (string $n): string => '{{'.$n.'}}', $names)),
        ));
    }
}
