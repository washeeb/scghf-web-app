<?php

declare(strict_types=1);

namespace App\Chat\Agent;

use App\Models\ChatConversation;
use Illuminate\Support\Str;

/**
 * When a person has to take over, decided here rather than by the model.
 *
 * ── Why these rules exist at all ────────────────────────────────────────────
 *
 * The system prompt tells the assistant to hand over for these things, and a
 * good model mostly will. "Mostly" is not a safeguarding policy. A model can
 * be talked out of its instructions, can misread a disclosure as a
 * hypothetical, and cannot be audited afterwards — so the cases where
 * getting it wrong is serious are decided in PHP, from a list the foundation
 * controls, before the model is called at all.
 *
 * The model's own request to hand over is honoured on top of these, never
 * instead of them.
 *
 * ── Every phrase is in the settings ─────────────────────────────────────────
 *
 * Settings → AI assistant. The seeded lists are a starting point written in
 * the English a Ghanaian visitor is likely to type, including "momo",
 * "deducted" and "dey" — not a translation of an American support script.
 * Staff add to them as they see what people actually ask.
 */
class EscalationRules
{
    /**
     * The first rule that fires, or null to let the assistant answer.
     */
    public function check(ChatConversation $conversation, string $message): ?Escalation
    {
        $text = ' '.Str::lower($this->fold($message)).' ';

        // 1. A crisis. Before anything else, and never answered by a machine.
        if ($this->matches($text, $this->phrases('agent.crisis_phrases'))) {
            return new Escalation(
                reason: 'crisis',
                department: $this->departmentFor('safeguarding'),
                immediate: true,
                notice: (string) setting('agent.crisis_reply', '') ?: null,
            );
        }

        // 2. The visitor asked for a person. They do not have to justify it,
        //    and they should not have to ask twice.
        if ($this->matches($text, $this->phrases('agent.handover_phrases'))) {
            return new Escalation('visitor_asked', null, true);
        }

        // 3. A subject the foundation has said an assistant must not handle.
        foreach ((array) setting('agent.escalate_topics', []) as $department => $phrases) {
            if (is_string($department) && $this->matches($text, $this->normalise($phrases))) {
                return new Escalation('topic:'.$department, $this->departmentFor($department), true);
            }
        }

        // 4. It has answered enough times. A visitor going round for a ninth
        //    turn is not being helped, whatever the transcript looks like.
        $ceiling = max(1, (int) setting('agent.max_replies', 8));

        if ($conversation->agent_replies >= $ceiling) {
            return new Escalation('reply_limit', null, true);
        }

        return null;
    }

    /**
     * The department key to route to, if the foundation still has one by
     * that name and it is switched on.
     */
    public function departmentFor(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        return $key;
    }

    /**
     * @param  array<int, string>  $phrases
     */
    private function matches(string $haystack, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            $needle = trim(Str::lower($this->fold($phrase)));

            if ($needle === '') {
                continue;
            }

            /*
             * Word-boundary, not substring. "sue" inside "issue" and "abuse"
             * inside "abuses" are opposite mistakes — the first escalates a
             * question about a delivery issue to the legal department, the
             * second fails to escalate a disclosure. A phrase of several
             * words matches as a phrase.
             */
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip the accents and the punctuation somebody types on a phone, so
     * "can't" and "cant" are the same word to a rule.
     */
    private function fold(string $value): string
    {
        return (string) preg_replace('/[\x{2018}\x{2019}\x{201C}\x{201D}\']/u', '', $value);
    }

    /** @return array<int, string> */
    private function phrases(string $key): array
    {
        return $this->normalise(setting($key, []));
    }

    /** @return array<int, string> */
    private function normalise(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n|,/', $value) ?: [];
        }

        return collect(is_array($value) ? $value : [])
            ->filter(fn (mixed $phrase): bool => is_string($phrase) && trim($phrase) !== '')
            ->map(fn (string $phrase): string => trim($phrase))
            ->values()
            ->all();
    }
}
