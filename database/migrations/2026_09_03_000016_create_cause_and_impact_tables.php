<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3, part 2 — causes and impact.
 *
 * `causes` is the FUNDRAISING unit; `projects` is the work. A cause can fund
 * several projects, and a project can run with no appeal behind it. Module 4's
 * `donations.cause_id` is NOT NULL, which is why a seeded General Fund exists:
 * every gift must have a destination, including one given before any appeal was
 * running.
 *
 * `impact_metrics` and `impact_metric_values` are a time series, not a single
 * "total" column. A figure without the period it covers cannot be compared with
 * last year's, which is the only thing anybody actually wants to do with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Causes
        |----------------------------------------------------------------------
        */
        Schema::create('causes', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title', 191);
            $table->string('slug', 191)->unique();
            $table->text('summary')->nullable();
            $table->longText('description')->nullable();

            /*
             * The appeal target. Nullable on purpose: the General Fund has no
             * target, and a zero would render as a progress bar permanently at
             * 100%.
             */
            $table->unsignedBigInteger('goal_minor')->nullable();

            /*
             * Denormalised, and maintained inside the donation transaction with
             * an atomic `UPDATE ... SET raised_minor = raised_minor + ?` —
             * never a read-then-write, which loses money under concurrency, and
             * two gifts landing in the same second is exactly when it matters.
             *
             * Unlike beneficiary_count this one is incremented rather than
             * recomputed, because donations are append-only: nothing is ever
             * edited or removed, so the running total cannot drift.
             */
            $table->unsignedBigInteger('raised_minor')->default(0);
            $table->unsignedInteger('donation_count')->default(0);
            $table->char('currency', 3)->default('GHS');

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            // draft | active | paused | completed | archived
            $table->string('status', 32)->default('draft');

            /*
             * The fallback destination. Exactly one cause carries this, seeded
             * and locked, so a donation always has somewhere to go — including
             * a gift made through a generic donate form with no appeal chosen.
             */
            $table->boolean('is_general_fund')->default(false);
            $table->boolean('is_locked')->default(false);

            /*
             * Tax deductibility — Act 896.
             *
             * `is_tax_deductible` records that the trustees consider this a
             * qualifying worthwhile cause. It is NECESSARY BUT NOT SUFFICIENT:
             * TaxDeductibility also requires a current written GRA approval
             * before any deductibility wording appears anywhere. A cause flagged
             * here while no approval is held claims nothing.
             *
             * `tax_approval_id` is for a s.100 approval covering this particular
             * cause, as distinct from the organisation-wide s.97 approval.
             */
            $table->boolean('is_tax_deductible')->default(false);
            $table->foreignId('tax_approval_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('allow_recurring')->default(true);
            $table->boolean('allow_fee_cover')->default(true);

            $table->foreignId('featured_image_id')->nullable()->constrained('media')->nullOnDelete();

            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['division_id', 'status']);
            $table->index(['is_published', 'is_featured', 'sort_order']);
            $table->index('is_general_fund');
            $table->index('status');
        });

        Schema::create('cause_updates', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('cause_id')->constrained()->cascadeOnDelete();

            $table->string('title', 191);
            $table->string('slug', 191);
            $table->longText('body');

            $table->foreignId('image_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['cause_id', 'slug']);
            $table->index(['cause_id', 'is_published', 'published_at']);
        });

        /*
        |----------------------------------------------------------------------
        | Impact metrics
        |----------------------------------------------------------------------
        */
        Schema::create('impact_metrics', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->text('description')->nullable();

            // "students", "boreholes", "GH¢" — what one unit of this counts.
            $table->string('unit', 64)->nullable();

            // integer | decimal | money | percentage
            $table->string('value_type', 32)->default('integer');

            // How periods combine into a headline figure:
            // sum | average | latest | max
            $table->string('aggregation', 32)->default('sum');

            /*
             * Whether this metric counts PEOPLE.
             *
             * The link between impact reporting and the privacy layer. A metric
             * counting people is subject to the minimum-group rule when
             * published: "1 beneficiary supported in Widower Support, Tamale,
             * March 2026" identifies that person as surely as printing their
             * name would. A metric counting boreholes is not.
             *
             * @see \App\Support\DisclosureControl
             */
            $table->boolean('counts_people')->default(false);

            $table->decimal('baseline_value', 20, 4)->nullable();
            $table->decimal('target_value', 20, 4)->nullable();

            $table->string('icon', 64)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_featured')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['division_id', 'is_public', 'sort_order']);
            $table->index(['is_public', 'is_featured']);
        });

        /*
        |----------------------------------------------------------------------
        | Impact metric values — a time series
        |----------------------------------------------------------------------
        |
        | One row per metric per period. A single "total" column on the metric
        | would make this year's figure indistinguishable from all time, and
        | comparison with last year impossible — which is the only thing anybody
        | actually does with an impact number.
        */
        Schema::create('impact_metric_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('impact_metric_id')->constrained()->cascadeOnDelete();

            $table->date('period_start');
            $table->date('period_end')->nullable();

            /*
             * DECIMAL(20,4), not FLOAT: an impact figure that shifts in the
             * fourth decimal between two reports is a figure nobody trusts.
             *
             * Money metrics still store integer pesewas here, in the integer
             * part — the column is wide enough, and the alternative would be a
             * second nullable amount column used by one value_type.
             */
            $table->decimal('value', 20, 4);

            $table->text('notes')->nullable();
            $table->string('source', 191)->nullable();

            /*
             * Verification. A published impact figure that nobody checked is
             * how a foundation ends up defending a number it cannot support, so
             * who verified it and when is recorded beside the figure itself.
             */
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /*
             * One value per metric per period. A second row for the same period
             * is a correction, and a correction replaces rather than adds.
             *
             * This unique index is also the read index: every query filters by
             * metric and orders or ranges on period_start, which is its leftmost
             * prefix. A separate composite index over the same columns would be
             * redundant — and, at these column names, would exceed MySQL's
             * 64-character identifier limit anyway.
             */
            $table->unique(['impact_metric_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impact_metric_values');
        Schema::dropIfExists('impact_metrics');
        Schema::dropIfExists('cause_updates');
        Schema::dropIfExists('causes');
    }
};
