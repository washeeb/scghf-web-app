<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The assistant
|--------------------------------------------------------------------------
|
| One model provider, called from one service, for one job: answering a
| visitor's first questions in the live chat and handing over to a person
| the moment it should. Nothing else in this application calls a language
| model, and nothing else should without the same escalation rules and the
| same budget.
|
| ── The driver is `null` until somebody puts a key in .env ─────────────────
|
| `null` is not "broken": it is a driver that answers every question with
| the hand-over line, which is exactly what a chat should do when there is
| no assistant. That keeps the whole module installable, testable and
| demonstrable before the foundation has an account with anybody, and it
| means a key that expires degrades to "a person will answer you" rather
| than to an error in a visitor's face.
|
| ── What is deliberately NOT configurable here ─────────────────────────────
|
| The persona, the wording, the refusals and which department a question
| goes to are the foundation's, not a developer's, and live in Settings →
| AI assistant where staff can change them without a deploy. This file
| holds only what it costs and what it connects to.
*/

return [

    /*
     * `anthropic` or `null`. Anything else is treated as `null`, loudly —
     * see AiManager. A typo in .env must not silently disable escalation.
     */
    'driver' => env('AI_DRIVER', 'null'),

    /*
     * The ceiling on what the assistant may spend in a calendar month, in
     * integer pesewas like every other amount in this application.
     *
     * When the month's estimated spend passes this, the assistant stops
     * answering and every chat goes to a person — it does not "try one
     * more". A charity's chat widget quietly running up a bill nobody is
     * watching is the failure mode this exists to prevent. 0 means no
     * ceiling, which is a decision somebody has to make deliberately.
     */
    'monthly_budget_minor' => (int) env('AI_MONTHLY_BUDGET_MINOR', 20_000),

    'anthropic' => [

        'api_key' => env('ANTHROPIC_API_KEY'),

        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),

        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),

        /*
         * Sonnet: the reply has to arrive inside one poll of the widget, and
         * this is a question-answering job over a few thousand words of the
         * foundation's own content, not a reasoning one.
         */
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),

        /*
         * A chat reply, not an essay. Also a cost ceiling per turn: the
         * assistant cannot be talked into writing a novel.
         */
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 700),

        /*
         * The visitor is waiting. Past this the request is abandoned and the
         * chat goes to a person — which is the right answer anyway, because a
         * visitor who has waited twelve seconds has waited long enough.
         */
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 12),

        /*
         * Indicative prices per million tokens, in integer pesewas, for the
         * running total and the budget above. They are an ESTIMATE recorded
         * against each call, not a bill: the invoice is Anthropic's, in
         * dollars, and this is what staff see when they ask what the chat
         * assistant costs.
         */
        'input_cost_per_mtok_minor' => (int) env('AI_INPUT_COST_PER_MTOK_MINOR', 4_500),
        'output_cost_per_mtok_minor' => (int) env('AI_OUTPUT_COST_PER_MTOK_MINOR', 22_500),
    ],

    /*
     * How much of the foundation's own content is put in front of the model
     * with each question. Bounded on purpose: everything here is paid for on
     * every turn, and a knowledge base that grows without a limit is a bill
     * that grows without a limit.
     */
    'knowledge' => [
        'max_characters' => (int) env('AI_KNOWLEDGE_MAX_CHARACTERS', 12_000),
        'max_faqs' => (int) env('AI_KNOWLEDGE_MAX_FAQS', 12),
        'max_pages' => (int) env('AI_KNOWLEDGE_MAX_PAGES', 6),
        // Rebuilt this often; a page edited in the panel clears it at once.
        'cache_minutes' => (int) env('AI_KNOWLEDGE_CACHE_MINUTES', 60),
    ],

    /*
     * How much of the conversation goes back with each turn. Enough for the
     * assistant to follow a thread, not so much that a long chat costs more
     * with every line.
     */
    'history_messages' => (int) env('AI_HISTORY_MESSAGES', 12),
];
