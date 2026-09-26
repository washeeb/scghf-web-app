<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Live chat
|--------------------------------------------------------------------------
|
| A conversation between a visitor and the office, held on this server —
| no third-party chat service, so nothing a visitor types leaves the
| foundation's own database. The visitor is identified by a secret token
| the browser keeps (stored hashed here, like a password); a signed-in
| visitor is also linked to their account. Staff pick a conversation up
| from the admin inbox and reply; the visitor's page polls for the answer.
|
| Kept for twelve months from the last message (config/compliance.php,
| `chat_conversation`) and then deleted, messages with it.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            // sha256 of the visitor's secret. Never the secret itself.
            $table->string('visitor_token_hash', 64)->unique();
            $table->string('visitor_name', 120);
            $table->string('visitor_email', 191)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('open')->index();
            $table->string('page_url', 500)->nullable();
            $table->string('visitor_ip', 45)->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('last_visitor_message_at')->nullable();
            $table->timestamp('last_staff_message_at')->nullable();
            $table->timestamp('staff_seen_at')->nullable();
            $table->timestamp('visitor_seen_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();
            // visitor | staff | system
            $table->string('sender', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['chat_conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};
