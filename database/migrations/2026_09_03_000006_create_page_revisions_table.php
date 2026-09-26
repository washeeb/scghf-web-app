<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A snapshot of a page and all its blocks, taken before each save.
 *
 * Append-only and NOT soft-deletable, for the same reason the financial tables
 * are not: an undo history an admin can quietly edit is not an undo history.
 * Pruned on a schedule by retention policy instead.
 *
 * This is what makes "content edits break the layout and there is no way back"
 * (Blueprint risk OPS-7) a non-issue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_revisions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Monotonic per page, so the admin can say "revision 7" rather than
            // quoting a timestamp.
            $table->unsignedInteger('revision_number');

            // The whole page plus its sections, as it was BEFORE this save.
            $table->longText('snapshot');

            // Free text from the editor, or a generated summary of what changed.
            $table->string('summary', 191)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->unique(['page_id', 'revision_number']);
            $table->index(['page_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_revisions');
    }
};
