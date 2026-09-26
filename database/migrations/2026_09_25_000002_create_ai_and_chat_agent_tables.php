<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The assistant, and the chat it answers in
|--------------------------------------------------------------------------
|
| Three things:
|
|  1. `ai_interactions` — one row per call to a model. What it was asked to
|     do, how long it took, how many tokens, what it cost (estimated), and
|     whether it handed over. Without this, "what is the chat assistant
|     costing us?" and "why did it say that?" are both unanswerable, and the
|     second one is the question that matters when a donor complains.
|
|  2. Columns on `chat_conversations` for who is handling it — the assistant
|     or a person — and, when it has been handed over, why and to which
|     department.
|
|  3. Columns for WhatsApp: which channel a conversation arrived on, the
|     visitor's WhatsApp id, and when Meta's 24-hour window closes. Outside
|     that window a free-form reply is refused by Meta, so the application
|     has to know.
|
| No message body is stored twice: `ai_interactions` records the SHAPE of the
| call, not the conversation. The conversation is in `chat_messages`, is
| covered by the twelve-month retention rule, and is deleted with it.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_interactions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            // Null once the conversation is deleted by the retention run: the
            // cost record outlives the words, which is the right way round.
            $table->foreignId('chat_conversation_id')->nullable()->constrained()->nullOnDelete();

            $table->string('driver', 32);
            $table->string('model', 64)->nullable();

            // answered | handover | failed
            $table->string('outcome', 16)->index();

            // Why a person was needed, when one was: the rule that fired, or
            // the provider failure. Never the visitor's words.
            $table->string('reason', 191)->nullable();

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);

            // Integer pesewas, like every other amount here. An estimate from
            // the configured rates — see config/ai.php.
            $table->unsignedInteger('estimated_cost_minor')->default(0);

            /*
             * Staff marking an answer wrong.
             *
             * An assistant answering for a charity will eventually say
             * something it should not have, and the only way that gets better
             * is if the person who spotted it can say so where somebody will
             * see it. Nullable: most answers are never reviewed.
             */
            $table->boolean('flagged')->default(false)->index();
            $table->string('flag_note', 500)->nullable();
            $table->foreignId('flagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('flagged_at')->nullable();

            $table->timestamps();

            // The month's spend, which is read on every single turn.
            $table->index(['created_at', 'estimated_cost_minor'], 'ai_interactions_spend_idx');
        });

        Schema::table('chat_conversations', function (Blueprint $table): void {
            // web | whatsapp
            $table->string('channel', 16)->default('web')->after('visitor_token_hash')->index();

            // agent | staff. `staff` from the moment a person is needed or
            // takes over, and it never goes back on its own — a visitor who
            // has been handed to a person is not handed back to a machine.
            $table->string('handled_by', 16)->default('staff')->after('status')->index();

            $table->timestamp('escalated_at')->nullable()->after('handled_by');
            $table->string('escalation_reason', 191)->nullable()->after('escalated_at');
            $table->foreignId('contact_department_id')->nullable()->after('escalation_reason')
                ->constrained()->nullOnDelete();

            // How many times the assistant has answered in this conversation,
            // for the per-conversation ceiling in Settings → AI assistant.
            $table->unsignedSmallInteger('agent_replies')->default(0)->after('contact_department_id');

            // WhatsApp. The id is Meta's (a phone number in international
            // form); the window is when a free-form reply stops being allowed.
            $table->string('whatsapp_wa_id', 32)->nullable()->after('visitor_ip')->index();
            $table->timestamp('whatsapp_window_expires_at')->nullable()->after('whatsapp_wa_id');
        });

        Schema::table('chat_messages', function (Blueprint $table): void {
            // The row in ai_interactions that produced this line, when one
            // did — so an answer in the thread can be flagged as wrong by the
            // person reading it.
            $table->foreignId('ai_interaction_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ai_interaction_id');
        });

        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('contact_department_id');
            $table->dropColumn([
                'channel', 'handled_by', 'escalated_at', 'escalation_reason',
                'agent_replies', 'whatsapp_wa_id', 'whatsapp_window_expires_at',
            ]);
        });

        Schema::dropIfExists('ai_interactions');
    }
};
