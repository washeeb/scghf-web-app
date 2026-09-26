<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Contracts\ChatModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Claude, through Anthropic's Messages API.
 *
 * One POST, no SDK: the request is four keys and the answer is one string,
 * and a vendor SDK would be a dependency with its own release cycle sitting
 * between this application and an HTTP call it already knows how to make.
 *
 * ── How the assistant asks for a person ─────────────────────────────────────
 *
 * On its own line, as `[[HANDOVER: department | reason]]`. A marker rather
 * than a tool call or structured output because it has to survive the model
 * putting it in the middle of a sentence, in the wrong case, or with the
 * department missing — all of which a caller can recover from, and none of
 * which should ever end with a visitor being told nothing. The marker is
 * stripped before anybody sees the reply.
 *
 * It is belt and braces regardless: ChatAgent escalates on its own rules
 * whatever the model says, so a model that never emits the marker still
 * cannot keep a safeguarding disclosure to itself.
 */
class AnthropicChatModel implements ChatModel
{
    /** `[[HANDOVER: donations | they are asking about a specific receipt]]` */
    private const HANDOVER = '/\[\[\s*HANDOVER\s*:?\s*([a-z_\- ]*)\|?([^\]]*)\]\]/i';

    public function name(): string
    {
        return 'anthropic';
    }

    public function usable(): bool
    {
        return filled(config('ai.anthropic.api_key')) && filled(config('ai.anthropic.model'));
    }

    public function reply(Prompt $prompt): AiReply
    {
        if (! $this->usable()) {
            return AiReply::unavailable('no_api_key');
        }

        $started = hrtime(true);

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('ai.anthropic.api_key'),
                'anthropic-version' => (string) config('ai.anthropic.version', '2023-06-01'),
            ])
                ->timeout((int) config('ai.anthropic.timeout', 12))
                ->acceptJson()
                ->post(rtrim((string) config('ai.anthropic.base_url'), '/').'/v1/messages', [
                    'model' => (string) config('ai.anthropic.model'),
                    'max_tokens' => $prompt->maxTokens,
                    'system' => $prompt->system,
                    'messages' => $prompt->messages,
                ]);
        } catch (Throwable $e) {
            report($e);

            return AiReply::unavailable('unreachable', $this->msSince($started));
        }

        $latency = $this->msSince($started);
        $body = (array) $response->json();

        if (! $response->successful()) {
            // 400 malformed, 401 bad key, 429 rate limit, 529 overloaded —
            // one outcome for all of them, and the type in the log.
            return AiReply::unavailable('http_'.$response->status().':'.Str::limit((string) data_get($body, 'error.type', ''), 40, ''), $latency);
        }

        $text = collect((array) data_get($body, 'content', []))
            ->filter(fn (mixed $part): bool => is_array($part) && ($part['type'] ?? null) === 'text')
            ->map(fn (array $part): string => (string) ($part['text'] ?? ''))
            ->implode("\n");

        $text = trim($text);

        if ($text === '') {
            return AiReply::unavailable('empty_answer', $latency);
        }

        [$text, $handover, $department, $reason] = $this->extractHandover($text);

        return AiReply::answer(
            text: $text,
            wantsHandover: $handover,
            handoverReason: $reason,
            department: $department,
            inputTokens: (int) data_get($body, 'usage.input_tokens', 0),
            outputTokens: (int) data_get($body, 'usage.output_tokens', 0),
            latencyMs: $latency,
            raw: ['stop_reason' => data_get($body, 'stop_reason'), 'id' => data_get($body, 'id')],
        );
    }

    /**
     * Pull the marker out of the answer.
     *
     * @return array{0: string, 1: bool, 2: string|null, 3: string|null}
     */
    private function extractHandover(string $text): array
    {
        if (preg_match(self::HANDOVER, $text, $matches) !== 1) {
            return [$text, false, null, null];
        }

        $department = Str::of($matches[1])->trim()->lower()->replace(' ', '_')->value();
        $reason = Str::of($matches[2])->trim()->limit(190, '')->value();

        $cleaned = trim((string) preg_replace(self::HANDOVER, '', $text));

        return [$cleaned, true, $department !== '' ? $department : null, $reason !== '' ? $reason : null];
    }

    private function msSince(float|int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
