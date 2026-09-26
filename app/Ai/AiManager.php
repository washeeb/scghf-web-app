<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Contracts\ChatModel;
use App\Models\AiInteraction;
use Illuminate\Support\Facades\Log;

/**
 * Picks the driver, and keeps the running total.
 *
 * ── An unknown driver name is `null`, loudly ────────────────────────────────
 *
 * A typo in `AI_DRIVER` must not silently turn the assistant off with no
 * trace — the symptom would be "the chat stopped answering" with nothing in
 * the log to explain it, which is a morning wasted. It falls back and says
 * so, once per resolution.
 *
 * ── The budget is checked here, not at the call site ────────────────────────
 *
 * So that there is exactly one place that can spend money, and exactly one
 * place that can stop. The ceiling is in config/ai.php and the spend is the
 * sum of the estimates recorded against this month's calls.
 */
class AiManager
{
    private ?ChatModel $driver = null;

    public function driver(): ChatModel
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        $name = (string) config('ai.driver', 'null');

        return $this->driver = match ($name) {
            'anthropic' => app(AnthropicChatModel::class),
            'null' => app(NullChatModel::class),
            default => $this->unknown($name),
        };
    }

    /** Whether the assistant can answer at all right now. */
    public function usable(): bool
    {
        return $this->driver()->usable() && ! $this->overBudget();
    }

    /**
     * This calendar month's estimated spend against the ceiling.
     *
     * Estimated, from the rates in config — never presented as the invoice.
     * A ceiling of 0 means somebody decided there is none.
     */
    public function overBudget(): bool
    {
        $ceiling = (int) config('ai.monthly_budget_minor', 0);

        return $ceiling > 0 && $this->spentThisMonthMinor() >= $ceiling;
    }

    public function spentThisMonthMinor(): int
    {
        return (int) AiInteraction::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('estimated_cost_minor');
    }

    private function unknown(string $name): ChatModel
    {
        Log::warning('AI: unknown driver in config, falling back to none.', ['driver' => $name]);

        return app(NullChatModel::class);
    }
}
