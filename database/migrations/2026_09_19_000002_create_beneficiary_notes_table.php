<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 2 — case notes as rows, not as one growing text column.
 *
 * `beneficiaries.case_notes` was a LONGTEXT that `decline()` appended to.
 * A note about a child must be attributable (who wrote it), dated, and
 * never edited after the fact — none of which one column can promise. So
 * each note is a row: an author, a timestamp, a kind, an encrypted body,
 * and no `updated_at`, because there is no update. The column stays for
 * what is already in it and for the retention runner, which destroys it
 * with the rest.
 *
 * Notes live and die with their case: the foreign key cascades, so the
 * retention runner's hard delete of the record takes the notes with it,
 * and they need no retention class of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficiary_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            // note (typed by a person) | status (a transition) | consent |
            // document | reveal (the ID number was shown in full)
            $table->string('kind', 16)->default('note');

            // Encrypted at rest — a note about a person is a case note.
            $table->text('body');

            $table->timestamp('created_at')->nullable();

            $table->index(['beneficiary_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficiary_notes');
    }
};
