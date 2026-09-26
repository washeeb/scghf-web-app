<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Contracts\ChatModel;

/**
 * The driver that never answers.
 *
 * Not a stub and not a test double: it is what this application runs with
 * until somebody puts a key in `.env`, and what it falls back to if the key
 * is removed. Every question goes straight to a person, which is precisely
 * what a live chat should do when there is no assistant — so the module is
 * installable, demonstrable and safe with no account anywhere, and the chat
 * behaves exactly as it did before the assistant existed.
 */
class NullChatModel implements ChatModel
{
    public function name(): string
    {
        return 'null';
    }

    public function usable(): bool
    {
        return false;
    }

    public function reply(Prompt $prompt): AiReply
    {
        return AiReply::unavailable('no_driver');
    }
}
