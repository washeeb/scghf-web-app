<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3, part 1 — the programme spine.
 *
 * `divisions` is the table nearly everything else hangs off. Every content
 * table, every donation and every impact figure is either scoped to one
 * division or is foundation-wide, and that distinction is expressed everywhere
 * as a NULLABLE `division_id` — null meaning "the whole foundation", not
 * "unknown". Making it non-nullable would force an artificial "General"
 * division onto rows that genuinely belong to no single one.
 *
 * `projects` is the operational unit: a named piece of work with a start, a
 * budget, locations and beneficiaries. `causes` (part 2) is the fundraising
 * unit. They are deliberately separate tables — a cause can fund several
 * projects, and a project can run with no public appeal behind it at all.
 * Collapsing them would make every donation report ambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Divisions
        |----------------------------------------------------------------------
        */
        Schema::create('divisions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->string('tagline', 191)->nullable();
            $table->text('summary')->nullable();
            $table->longText('description')->nullable();

            /*
             * A THEME TOKEN name, not a hex value.
             *
             * The CMS rule forbids colour in code, and it applies just as much
             * to colour in a data column: a hex stored here would bypass the
             * theme_settings layer and its AA contrast validation entirely, and
             * would need editing in two places to change. This names a token
             * that theme_settings already defines and has already been checked
             * for contrast in both light and dark.
             */
            $table->string('colour_token', 64)->nullable();
            $table->string('icon', 64)->nullable();

            $table->foreignId('logo_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('hero_image_id')->nullable()->constrained('media')->nullOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            /*
             * A seeded division is structural: causes, donations, projects and
             * years of reporting hang off it. Deleting one would orphan the
             * lot, so the model refuses — the same protection the locked pages
             * and locked theme tokens carry.
             */
            $table->boolean('is_locked')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });

        /*
        |----------------------------------------------------------------------
        | Focus areas
        |----------------------------------------------------------------------
        |
        | The thematic bands a division works in — education, health,
        | livelihoods, discipleship. Reported on, filtered by, and used to group
        | projects on a division page.
        */
        Schema::create('focus_areas', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // NOT NULL here, unlike everywhere else: a focus area is a
            // subdivision of a division's work and has no meaning without one.
            $table->foreignId('division_id')->constrained()->cascadeOnDelete();

            $table->string('name', 191);

            // Globally unique rather than unique per division, because a focus
            // area appears in a URL. UNIQUE(division_id, slug) would allow two
            // /focus/education paths and the router could not tell them apart.
            $table->string('slug', 191)->unique();

            $table->text('description')->nullable();
            $table->string('icon', 64)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['division_id', 'sort_order']);
        });

        /*
        |----------------------------------------------------------------------
        | Projects
        |----------------------------------------------------------------------
        */
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // Null = foundation-wide, not unknown.
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title', 191);
            $table->string('slug', 191)->unique();
            $table->text('summary')->nullable();
            $table->longText('description')->nullable();

            // planned | active | paused | completed | cancelled
            $table->string('status', 32)->default('planned');

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->date('completed_on')->nullable();

            /*
             * The planned budget, in integer pesewas. Nullable because a
             * project can be published before its budget is agreed, and a zero
             * would read as "free" rather than "not yet costed".
             *
             * What has actually been RAISED lives on causes, and what has been
             * SPENT lives in the ledger. Three different questions, three
             * different columns, none of them derived from another.
             */
            $table->unsignedBigInteger('budget_minor')->nullable();
            $table->char('currency', 3)->default('GHS');

            /*
             * Denormalised so a project listing does not run a COUNT per row.
             * Recomputed from the source rather than incremented, because
             * beneficiary records are added, withdrawn and de-identified in any
             * order — and a counter that drifts is worse than no counter, since
             * nobody knows it is wrong.
             */
            $table->unsignedInteger('beneficiary_count')->default(0);

            $table->foreignId('featured_image_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['division_id', 'status']);
            $table->index(['is_published', 'is_featured', 'published_at']);
            $table->index('status');
        });

        Schema::create('focus_area_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('focus_area_id')->constrained()->cascadeOnDelete();

            $table->unique(['project_id', 'focus_area_id']);
        });

        Schema::create('partner_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();

            // funder | implementer | technical | host
            $table->string('role', 32)->default('implementer');

            $table->unique(['project_id', 'partner_id']);
        });

        Schema::create('document_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['project_id', 'document_id']);
        });

        /*
        |----------------------------------------------------------------------
        | Project updates
        |----------------------------------------------------------------------
        |
        | The running record donors read to see where their money went. Kept as
        | its own table rather than blog posts, because an update belongs to a
        | project's timeline and must appear there whether or not anyone thought
        | to tag it.
        */
        Schema::create('project_updates', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('title', 191);
            $table->string('slug', 191);
            $table->longText('body');

            $table->foreignId('image_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Scoped to the project, because the URL is
            // /projects/{project}/updates/{update} and two projects may both
            // reasonably have a "first-term-report".
            $table->unique(['project_id', 'slug']);
            $table->index(['project_id', 'is_published', 'published_at']);
        });

        /*
        |----------------------------------------------------------------------
        | Milestones
        |----------------------------------------------------------------------
        */
        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('title', 191);
            $table->text('description')->nullable();

            // pending | in_progress | achieved | missed | cancelled
            $table->string('status', 32)->default('pending');

            $table->date('due_on')->nullable();
            $table->date('achieved_on')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_public')->default(true);

            $table->timestamps();

            $table->index(['project_id', 'sort_order']);
            $table->index(['status', 'due_on']);
        });

        /*
        |----------------------------------------------------------------------
        | Project locations
        |----------------------------------------------------------------------
        |
        | Where the work happens. This is SITE data, not person data — a
        | community named here is the community a borehole was dug in, which is
        | published on purpose.
        |
        | Beneficiary location is an entirely different matter: it is personal
        | data, it is classified `community` / `geolocation` in the privacy
        | policy, and it is destroyed at retention expiry. The two must never be
        | conflated, which is why beneficiaries do not point at this table.
        */
        Schema::create('project_locations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('name', 191);
            $table->string('region', 191)->nullable();
            $table->string('district', 191)->nullable();
            $table->string('community', 191)->nullable();

            // Site coordinates for a map pin. DECIMAL, not float: a float
            // latitude drifts, and a drifting map pin is a wrong map pin.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['project_id', 'is_primary']);
            $table->index('region');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_locations');
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('project_updates');
        Schema::dropIfExists('document_project');
        Schema::dropIfExists('partner_project');
        Schema::dropIfExists('focus_area_project');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('focus_areas');
        Schema::dropIfExists('divisions');
    }
};
