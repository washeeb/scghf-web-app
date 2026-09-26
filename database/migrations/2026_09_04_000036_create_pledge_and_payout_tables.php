<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two halves of the ledger the ERD named and Module 4 never built.
 *
 * §2.4 lists "pledges, offline_donations, payouts". Offline donations were
 * folded into `donations` with their own channel and fields, which was the
 * right call — an offline gift is a gift. The other two were left out.
 *
 * ── A pledge is not income ──────────────────────────────────────────────────
 *
 * This is the entire reason `pledges` is a separate table rather than a
 * donation with a status.
 *
 * A promise to give GH₵ 5,000 at harvest is a promise. It is not money. If a
 * pledge could sit in `donations` with a `pledged` status, then every query
 * that sums donations — the cause thermometer, the annual report, the figure a
 * trustee quotes to a partner — would have to remember to exclude it. One that
 * forgets reports money the foundation does not have, to somebody making
 * decisions with it.
 *
 * So pledges live apart, and a pledge becomes income only through a real
 * donation that references it. `donations` stays exactly what it was: things
 * that actually happened.
 *
 * Pledges matter here specifically. Harvest and thanksgiving pledging is
 * ordinary practice in Ghanaian church-linked giving, and a foundation that
 * cannot record one either loses it or — worse — books it as a gift.
 *
 * ── Payouts are the direction nothing else records ──────────────────────────
 *
 * Everything built so far records money coming IN. A foundation is judged on
 * what it does with it, and until now the application had nowhere to say that
 * GH₵ 12,000 went to school fees for forty children in the Northern Region.
 *
 * The control that matters is separation of duties: whoever REQUESTS a payment
 * may not be whoever APPROVES it. That is the single most effective control
 * against both fraud and honest error in a small organisation where the same
 * two or three people do everything — and it is enforced in the model, not
 * left to a screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Pledges — promises, kept apart from money
        |----------------------------------------------------------------------
        */
        Schema::create('pledges', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // Quotable back to somebody who telephones about their pledge.
            $table->string('reference', 32)->unique();

            $table->foreignId('donor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // NOT NULL, like donations. A promise with no destination cannot be
            // reported on, and the General Fund is the seeded fallback.
            $table->foreignId('cause_id')->constrained()->restrictOnDelete();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot, so a pledge survives the donor record being merged or
            // erased — the same reasoning donations already use.
            $table->string('pledger_name', 191);
            $table->string('pledger_email', 191)->nullable();
            $table->string('pledger_phone', 20)->nullable();

            /*
             * Integer pesewas, like every other amount. `fulfilled_minor` is
             * maintained from the donations that reference this pledge — it is
             * a cached sum of real money, never a figure somebody types.
             */
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('fulfilled_minor')->default(0);
            $table->char('currency', 3)->default('GHS');

            // pledged | partially_fulfilled | fulfilled | lapsed | cancelled
            $table->string('status', 32)->default('pledged');

            /*
             * When it was promised for. Nullable, because "at harvest" is a
             * real answer and inventing a date would make a lapse notice go out
             * on a day nobody agreed to.
             */
            $table->date('due_on')->nullable();

            // harvest | thanksgiving | appeal | event | personal | other —
            // the occasion, which is how the foundation actually files these.
            $table->string('occasion', 32)->nullable();

            $table->text('notes')->nullable();

            /*
             * Reminders. Recorded so a second one is a decision rather than an
             * accident: chasing somebody weekly about a promise is how a
             * supporter becomes a former supporter.
             */
            $table->unsignedTinyInteger('reminders_sent')->default(0);
            $table->timestamp('last_reminded_at')->nullable();

            /*
             * Consent to be reminded at all. A pledge is not consent to
             * marketing, and the reminder is transactional only while the
             * pledge is open.
             */
            $table->boolean('consent_to_remind')->default(false);

            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 191)->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'due_on']);
            $table->index(['cause_id', 'status']);
            $table->index('pledger_email');
        });

        /*
        |----------------------------------------------------------------------
        | Payouts — money going out
        |----------------------------------------------------------------------
        */
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('reference', 32)->unique();

            // What it was spent on. Nullable individually, but a payout with
            // none of them set is refused by the model — an unattributed
            // disbursement cannot be reported to a funder.
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cause_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Who it went to. A beneficiary where the payment is assistance; a
             * free-text payee where it is a supplier, a school or a hospital.
             *
             * `beneficiary_id` is nullOnDelete so a payout survives the
             * beneficiary record being destroyed at retention expiry — the
             * financial trail outlives the person, which is the whole point of
             * de-identifying rather than deleting.
             */
            $table->foreignId('beneficiary_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payee_name', 191);
            $table->string('payee_reference', 191)->nullable();

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('GHS');

            // school_fees | medical | food | rent | stipend | supplier |
            // transport | equipment | other
            $table->string('category', 32)->default('other');

            // mobile_money | bank_transfer | cash | cheque
            $table->string('method', 32)->default('mobile_money');
            $table->string('momo_network', 32)->nullable();

            $table->text('purpose');

            // draft | pending_approval | approved | paid | rejected | cancelled
            $table->string('status', 32)->default('draft');

            /*
             * Separation of duties, enforced by the model.
             *
             * `requested_by` and `approved_by` must be different people. In a
             * small organisation where two or three staff do everything, this
             * is the single most effective control against both fraud and
             * honest error — and it is worth the friction precisely because it
             * is inconvenient for the person it constrains.
             */
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 191)->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * The receipt, the signed collection slip, the bank advice.
             *
             * A disbursement with no evidence is the finding every audit opens
             * with. Nullable in the schema because evidence sometimes arrives a
             * day later, but the model refuses to mark a payout PAID without it.
             */
            $table->foreignId('evidence_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();
            // No soft delete. This is a financial record: corrections are new
            // rows, the same rule donations and receipts follow.

            $table->index(['status', 'requested_at']);
            $table->index(['division_id', 'paid_at']);
            $table->index(['project_id', 'paid_at']);
            $table->index('beneficiary_id');
        });

        /*
        |----------------------------------------------------------------------
        | Peer-to-peer fundraisers
        |----------------------------------------------------------------------
        |
        | Behind `features.p2p_fundraising`, which is OFF. The table exists so
        | that turning it on is a decision rather than a migration, and because
        | `donations.fundraiser_id` was promised in the Module 4 migration and
        | never delivered — a foreign key documented in a comment and absent
        | from the schema is worse than one that was never mentioned.
        */
        Schema::create('fundraisers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('title', 191);
            $table->string('slug', 191)->unique();
            $table->text('story')->nullable();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cause_id')->constrained()->restrictOnDelete();

            $table->unsignedBigInteger('goal_minor')->nullable();
            $table->unsignedBigInteger('raised_minor')->default(0);
            $table->char('currency', 3)->default('GHS');
            $table->unsignedInteger('donation_count')->default(0);

            $table->date('ends_on')->nullable();

            /*
             * pending_review | active | completed | suspended
             *
             * `pending_review` is the default and it matters: a supporter page
             * carrying the foundation's name and taking money in its name is
             * published by the foundation, not by the supporter. The reputational
             * exposure of the alternative is the reason the feature is flagged
             * off in the first place.
             */
            $table->string('status', 32)->default('pending_review');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('suspension_reason', 191)->nullable();

            $table->foreignId('image_id')->nullable()->constrained('media')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'ends_on']);
            $table->index(['cause_id', 'status']);
        });

        /*
        |----------------------------------------------------------------------
        | The columns donations was promised
        |----------------------------------------------------------------------
        |
        | The Module 4 migration says: "`subscription_id` and `fundraiser_id`
        | are NOT here. Those tables arrive in a later migration." The
        | subscription column arrived with recurring giving. This one did not,
        | and neither did the pledge link.
        |
        | Both arrive WITH their foreign keys, which is the same reasoning that
        | deferred them: a column that dangles unconstrained is a column that
        | eventually points at nothing.
        */
        Schema::table('donations', function (Blueprint $table) {
            $table->foreignId('fundraiser_id')->nullable()->after('subscription_id')
                ->constrained()->nullOnDelete();

            /*
             * Which promise this gift fulfils, if any.
             *
             * This is the ONLY way a pledge turns into income: a real donation
             * pointing back at it. Nothing sums pledges into a total anywhere.
             */
            $table->foreignId('pledge_id')->nullable()->after('fundraiser_id')
                ->constrained()->nullOnDelete();

            $table->index('fundraiser_id');
            $table->index('pledge_id');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pledge_id');
            $table->dropConstrainedForeignId('fundraiser_id');
        });

        Schema::dropIfExists('fundraisers');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('pledges');
    }
};
