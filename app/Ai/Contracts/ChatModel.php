<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\AiReply;
use App\Ai\Prompt;

/**
 * What a model provider has to be able to do: take a system prompt and a
 * conversation, and come back with one reply.
 *
 * Deliberately narrow. No streaming (the widget polls, and a half-finished
 * sentence is worse than a pause), no tools (nothing the assistant may do is
 * worth the blast radius of letting a language model call it — see
 * ChatAgent), no images. Same shape and the same reasons as SmsGateway and
 * WhatsappGateway: the whole module is buildable and testable with the `null`
 * driver before the foundation has an account with anybody.
 *
 * An implementation NEVER throws for a provider problem. A refusal, a
 * timeout, a rate limit and an outage all come back as an AiReply that
 * cannot answer, because every one of them means the same thing to the
 * visitor: a person will take this.
 */
interface ChatModel
{
    /** `anthropic` or `null`. */
    public function name(): string;

    /** Whether this driver can actually answer — a key present, a model set. */
    public function usable(): bool;

    public function reply(Prompt $prompt): AiReply;
}
