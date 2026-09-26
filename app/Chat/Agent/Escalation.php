<?php

declare(strict_types=1);

namespace App\Chat\Agent;

/**
 * The decision to put a person on a chat: why, and to which department.
 *
 * `$immediate` is the difference between "answer this, then hand over" and
 * "do not let a machine answer this at all". A question about opening hours
 * that ends with "and can I speak to someone" gets an answer and a hand-over;
 * a disclosure about a child does not get an answer from an assistant, ever.
 */
final readonly class Escalation
{
    public function __construct(
        public string $reason,
        public ?string $department = null,
        public bool $immediate = false,
        /** A line the visitor is shown before anything else — a crisis number. */
        public ?string $notice = null,
    ) {}
}
