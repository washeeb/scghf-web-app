<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * One request to a model: the standing instructions, and the conversation so
 * far.
 *
 * A value object rather than an array so that the two things that must never
 * be confused — what the FOUNDATION told the assistant, and what a VISITOR
 * typed — cannot be flattened into one list by accident. Everything in
 * `$messages` is untrusted input from a stranger on the internet; everything
 * in `$system` is the foundation's own words. A provider implementation must
 * keep them apart, and this shape is what makes forgetting to hard.
 */
final readonly class Prompt
{
    /**
     * @param  array<int, array{role: 'user'|'assistant', content: string}>  $messages
     */
    public function __construct(
        public string $system,
        public array $messages,
        public int $maxTokens,
    ) {}
}
