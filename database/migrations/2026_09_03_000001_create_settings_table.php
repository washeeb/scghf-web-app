<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every operational value the CMS rule demands live outside the code.
 *
 * `CLAUDE.md`: no hardcoded content in Blade — no strings, phone numbers,
 * emails, addresses, colours or images. This table is where they go instead,
 * and PHASE-1-BLUEPRINT.md §0's placeholder register is its seed data.
 *
 * One `value` TEXT column rather than sparse typed columns. A row's `type`
 * says how to read it (see App\Enums\SettingType), which keeps the table
 * narrow, keeps adding a setting to a seeder line rather than a migration,
 * and means the whole table fits in one cached array.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            // 'general', 'contact', 'social', 'donations', 'shop', 'seo',
            // 'legal', 'email', 'sms'. Drives the admin page's tab layout.
            $table->string('group', 64);
            $table->string('key', 128);

            // Nullable because an unfilled placeholder is a legitimate state —
            // most of §0 is empty until the foundation supplies it, and the
            // preflight command reports on exactly these.
            $table->text('value')->nullable();

            $table->string('type', 32)->default('string');

            // Shown in the admin form. Editable content, so it lives here
            // rather than in a translation file the client cannot reach.
            $table->string('label', 191);
            $table->text('description')->nullable();

            // Safe to expose to the browser. Anything false never reaches a
            // Blade view's JSON payload — the difference between the public
            // phone number and the SMS provider's balance threshold.
            $table->boolean('is_public')->default(false);

            // A handful of settings hold credentials that genuinely have to be
            // editable by an admin (an SMS API key, say) rather than living in
            // .env. Those are encrypted at rest by the model.
            $table->boolean('is_encrypted')->default(false);

            // Set on rows the application depends on existing — 'general_fund'
            // designation, the legal-page mapping. Blocks deletion in Filament.
            $table->boolean('is_locked')->default(false);

            // Extra validation beyond what the type implies, and options for
            // Select. JSON here is justified: the shape genuinely varies per row.
            $table->string('validation', 255)->nullable();
            $table->json('options')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A key is unique within its group, so 'contact.email' and
            // 'email.from_address' can coexist without contrivance.
            $table->unique(['group', 'key']);

            // The admin screen reads one group at a time, in display order.
            $table->index(['group', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
