<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * What came back.
 *
 * `answered` is the only thing callers should branch on. A model that
 * refused, a key that expired, a provider that timed out and a monthly
 * budget that ran out are four different lines in the log and one outcome in
 * the chat: nobody typed an answer, so a person must.
 */
final readonly class AiReply
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public bool $answered,
        public string $text,
        public bool $wantsHandover,
        public ?string $handoverReason,
        public ?string $department,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs,
        public ?string $failure,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function answer(
        string $text,
        bool $wantsHandover = false,
        ?string $handoverReason = null,
        ?string $department = null,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $latencyMs = 0,
        array $raw = [],
    ): self {
        return new self(true, $text, $wantsHandover, $handoverReason, $department, $inputTokens, $outputTokens, $latencyMs, null, $raw);
    }

    /** Nobody answered, for the stated reason. The chat goes to a person. */
    public static function unavailable(string $failure, int $latencyMs = 0): self
    {
        return new self(false, '', true, $failure, null, 0, 0, $latencyMs, $failure);
    }

    /**
     * What this turn is estimated to have cost, in integer pesewas.
     *
     * An estimate from the configured per-million rates, recorded per call so
     * staff can see what the assistant costs without an invoice. Never
     * presented as the bill — see config/ai.php.
     */
    public function estimatedCostMinor(): int
    {
        $in = (int) config('ai.anthropic.input_cost_per_mtok_minor', 0);
        $out = (int) config('ai.anthropic.output_cost_per_mtok_minor', 0);

        return (int) round(($this->inputTokens * $in + $this->outputTokens * $out) / 1_000_000);
    }
}
