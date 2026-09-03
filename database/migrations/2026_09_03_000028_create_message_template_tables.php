<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 7, part 1 — message templates and the suppression list.
 *
 * ── Templates are content, statutory wording is not ─────────────────────────
 *
 * CLAUDE.md's CMS rule says no hardcoded content, and an email is content: the
 * foundation must be able to reword its own receipt covering letter without a
 * deployment. So subjects and bodies live here, editable in Filament.
 *
 * What does NOT live here is the GRA acknowledgement wording. That is in
 * config/compliance.php, is composed by App\Support\Acknowledgement, and
 * reaches the template as a single variable. An editor can change the letter
 * around it; nobody can accidentally reword a statement made under s.97 of
 * Act 896, and nobody can activate the approval paragraph before the Notice of
 * Approval actually exists.
 *
 * ── The suppression list is the reason receipts keep arriving ───────────────
 *
 * Blueprint risk DEL-4. Bounces and complaints that are not suppressed degrade
 * the sending domain until mail stops being delivered at all — and the first
 * casualty is not the newsletter, it is the donation receipt.
 *
 * The list has a SCOPE, because "stop emailing me" and "this mailbox does not
 * exist" are different facts. An unsubscribe stops appeals. A bounce stops
 * everything. Getting that backwards in either direction is a real failure:
 * one destroys a domain's reputation, the other quietly withholds somebody's
 * only record of a gift they made.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Email templates
        |----------------------------------------------------------------------
        */
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // The code-facing handle: donation.receipt, order.shipped. Code
            // looks templates up by key, never by id, so a template can be
            // rebuilt in the admin without breaking the send that uses it.
            $table->string('key', 64)->unique();

            $table->string('name', 191);
            $table->text('description')->nullable();

            // transactional | marketing | system — see config/communications.php.
            // Decides which suppressions block it and whether it carries an
            // unsubscribe link.
            $table->string('category', 32)->default('transactional');

            $table->string('subject', 255);

            /*
             * The grey line under the subject in most inboxes. Left empty, mail
             * clients pull the first text they find — which is usually "View
             * this email in your browser".
             */
            $table->string('preheader', 191)->nullable();

            $table->longText('body_html');

            /*
             * The plain-text alternative. Not optional in practice: an HTML-only
             * message scores as spam with most filters, and it is the only
             * version some feature phones will ever render.
             */
            $table->longText('body_text')->nullable();

            // Which Blade shell wraps the body. The shell is layout, not
            // content, so it stays in code.
            $table->string('layout', 64)->default('mail.layouts.default');

            // Per-template overrides of the defaults in config/mail.php. A
            // safeguarding acknowledgement should not reply to the shop inbox.
            $table->string('from_name', 191)->nullable();
            $table->string('from_address', 191)->nullable();
            $table->string('reply_to', 191)->nullable();
            $table->string('bcc', 191)->nullable();

            /*
             * Declared variables, so the editor can show what is available and
             * validation can reject {{donor_nme}} at save time rather than
             * shipping "Dear ," to a donor.
             *
             * `required_variables` is the stricter subset: rendering REFUSES if
             * one of these is missing. A receipt with no amount on it is worse
             * than a receipt that failed to send and alerted somebody.
             */
            $table->json('available_variables')->nullable();
            $table->json('required_variables')->nullable();

            $table->boolean('is_active')->default(true);

            /*
             * A locked template cannot be deactivated or deleted, only edited.
             *
             * The failure this prevents: somebody tidies up the template list,
             * deactivates "Donation receipt" because it looks unused, and
             * receipts stop going out silently. Nothing throws, no page breaks,
             * and it is discovered weeks later by a donor asking where theirs is.
             */
            $table->boolean('is_locked')->default(false);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category', 'is_active']);
        });

        /*
        |----------------------------------------------------------------------
        | SMS templates
        |----------------------------------------------------------------------
        |
        | A separate table rather than a channel column on the one above,
        | because the constraints are genuinely different: no HTML, no subject,
        | a hard segment budget, and an encoding that changes cost by a factor
        | of three depending on which characters are used.
        */
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('key', 64)->unique();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->string('category', 32)->default('transactional');

            $table->text('body');

            /*
             * Override for the registered sender ID. Capped at 11 characters
             * because that is the GSM limit for an alphanumeric sender — and an
             * unregistered or over-length sender ID is dropped by Ghanaian
             * networks silently, with no error returned anywhere.
             */
            $table->string('sender_id', 11)->nullable();

            $table->json('available_variables')->nullable();
            $table->json('required_variables')->nullable();

            /*
             * Recomputed on every save from the body with its variables at
             * their longest declared example. Stored rather than derived on read
             * so the admin list can show cost per send without rendering every
             * template on every page load.
             *
             * `encoding` is the one that surprises people: the cedi sign ₵ is
             * not in the GSM-7 alphabet, so a single ₵ in a template drops the
             * segment size from 160 characters to 70 for every message sent
             * from it. The segmenter flags it; the editor sees the cost.
             */
            $table->string('encoding', 8)->default('gsm7');
            $table->unsignedSmallInteger('character_count')->default(0);
            $table->unsignedTinyInteger('estimated_segments')->default(1);
            $table->unsignedTinyInteger('max_segments')->default(2);

            $table->boolean('is_active')->default(true);
            $table->boolean('is_locked')->default(false);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category', 'is_active']);
        });

        /*
        |----------------------------------------------------------------------
        | Suppressions
        |----------------------------------------------------------------------
        */
        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // email | sms
            $table->string('channel', 16);

            /*
             * Normalised before storage — lowercased for email, E.164 for a
             * phone number. A suppression list that misses because somebody
             * typed Ama@Example.com is not a suppression list.
             */
            $table->string('address', 191);

            // all | marketing. See config/communications.php.
            $table->string('scope', 16)->default('all');

            // hard_bounce | complaint | unsubscribe | invalid | erasure_request
            // | manual | soft_bounce_repeated
            $table->string('reason', 32);

            // The provider's own bounce text, verbatim. It is the difference
            // between "mailbox full" and "user unknown", which is the
            // difference between waiting and giving up.
            $table->text('detail')->nullable();

            // webhook | manual | import | system
            $table->string('source', 32)->default('system');

            $table->timestamp('suppressed_at');

            /*
             * A time-limited suppression, for the cases that genuinely recover —
             * a mailbox over quota. Null is the normal case: suppression does
             * not expire on its own.
             */
            $table->timestamp('expires_at')->nullable();

            /*
             * Release is a decision by a person, with a reason, recorded. There
             * is no automatic path off this list; config
             * `suppression.allow_automatic_release` is false and the service
             * honours it.
             */
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release_reason', 191)->nullable();

            // How often we have seen this address fail. Drives the soft-bounce
            // threshold, and tells staff whether an address is a one-off typo
            // or a persistent problem.
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('last_seen_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * One row per address per channel. A repeat event updates the row in
             * place and may STRENGTHEN its scope — never weaken it.
             */
            $table->unique(['channel', 'address']);
            $table->index(['channel', 'scope', 'released_at']);
            $table->index('reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
        Schema::dropIfExists('sms_templates');
        Schema::dropIfExists('email_templates');
    }
};
