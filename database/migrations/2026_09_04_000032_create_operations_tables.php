<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 8, part 2 — errors, backups and visitor counts.
 *
 * ── Errors are grouped, not accumulated ─────────────────────────────────────
 *
 * Ten thousand copies of the same undefined-index is ONE problem. Stored one
 * row per occurrence it is also a full disk, on a shared plan that counts
 * inodes as well as bytes. So rows are keyed by a fingerprint of the fault and
 * carry a count.
 *
 * Nothing from the request body is captured. PCI DSS SAQ-A rests on this
 * application never touching card data, and an error report that helpfully
 * grabbed the POST body would quietly make that untrue — as would one that
 * captured a beneficiary's narrative from a failed form submission.
 *
 * ── A backup that has never been restored is a hypothesis ───────────────────
 *
 * `CLAUDE.md` lists this among the things commonly forgotten, and it is why
 * `backups_log` records RESTORE TESTS as first-class rows rather than only
 * recording that backups ran. A year of green ticks tells you the archive was
 * written. It tells you nothing about whether the foundation could get its data
 * back, and that is the only question that matters on the day it matters.
 *
 * ── Visitor statistics are aggregate BY CONSTRUCTION ────────────────────────
 *
 * No IP, no fingerprint, no cross-site identifier, and no per-visitor row — not
 * as a policy somebody could relax, but because there is nowhere in this schema
 * to put one.
 *
 * The consequence, stated plainly because somebody will ask: this application
 * cannot report unique visitors. It reports views, and sessions counted from
 * the session cookie it already sets for its own reasons. Producing a
 * unique-visitor number would mean minting an identifier for people who did not
 * ask to be counted, which costs more than the number is worth.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Error reports
        |----------------------------------------------------------------------
        */
        Schema::create('error_reports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            /*
             * SHA-256 of exception class + file + line + normalised message.
             * Unique, so a recurring fault increments a counter instead of
             * inserting a row — which is the difference between a readable list
             * and an inode quota.
             */
            $table->char('fingerprint', 64)->unique();

            $table->string('exception_class', 191);
            $table->text('message');
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('line')->nullable();

            // Trimmed to the application's own frames. A hundred lines of
            // vendor internals is not what tells somebody what broke.
            $table->text('trace')->nullable();

            // Where it happened — a route name and method, never a full URL
            // with query parameters, which is where personal data hides.
            $table->string('route', 191)->nullable();
            $table->string('method', 10)->nullable();

            /*
             * Whether an actual person hit this, and how many distinct ones.
             *
             * A count, not a list. "Forty-three people saw this" is what
             * decides whether it is fixed today; who they were is not needed to
             * decide that, so it is not kept.
             */
            $table->unsignedInteger('affected_users')->default(0);
            $table->boolean('affected_visitor')->default(false);

            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            // info | warning | error | critical — set from context, e.g.
            // anything on the payment or webhook path is critical by definition.
            $table->string('severity', 16)->default('error');

            // Scrubbed. Route parameters and a handful of safe flags; never a
            // request body.
            $table->json('context')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();

            // Silenced rather than resolved: a known third-party noise source
            // that will not be fixed but should stop filling the list.
            $table->boolean('is_muted')->default(false);

            $table->timestamps();

            $table->index(['resolved_at', 'last_seen_at']);
            $table->index(['severity', 'last_seen_at']);
            $table->index('last_seen_at');
        });

        /*
        |----------------------------------------------------------------------
        | Backups
        |----------------------------------------------------------------------
        */
        Schema::create('backups_log', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            /*
             * backup | cleanup | restore_test
             *
             * `restore_test` is the row type that makes this table worth having.
             * Everything else records that a file was written.
             */
            $table->string('type', 32)->default('backup');

            // started | completed | failed
            $table->string('status', 32)->default('started');

            $table->string('destination', 191)->nullable();
            $table->string('filename', 500)->nullable();

            $table->unsignedBigInteger('size_bytes')->nullable();

            /*
             * File count as well as size.
             *
             * Shared hosting counts INODES, and a media library plus a month of
             * daily archives is the usual way an account hits that limit — long
             * before it runs out of disk. A backup that reports 1.2GB and
             * 180,000 files is telling two different stories.
             */
            $table->unsignedInteger('file_count')->nullable();

            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('error')->nullable();

            /*
             * Restore-test fields. Null on an ordinary backup row.
             *
             * `restored_row_count` and `verified_by` are the point: a restore
             * test that nobody signed and that counted nothing is a note
             * saying "seemed fine".
             */
            $table->foreignId('source_backup_id')->nullable()
                ->constrained('backups_log')->nullOnDelete();
            $table->unsignedInteger('restored_row_count')->nullable();
            $table->text('restore_notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status', 'started_at'], 'backups_log_type_status_index');
            $table->index('started_at');
        });

        /*
        |----------------------------------------------------------------------
        | Visitor statistics — aggregates only
        |----------------------------------------------------------------------
        |
        | One row per day per dimension value. There is no visitor table, and
        | adding one would be a schema change somebody would have to justify.
        */
        Schema::create('visitor_stats', function (Blueprint $table) {
            $table->id();

            $table->date('date');

            // total | path | referrer_host | device_type
            $table->string('dimension', 32)->default('total');

            /*
             * The value counted. For `path`, the route path with no query
             * string — query strings carry tokens, email addresses and search
             * terms, which is precisely the personal data this table exists
             * not to hold.
             */
            $table->string('value', 191)->default('');

            $table->unsignedInteger('views')->default(0);

            /*
             * Sessions, counted from the session the application already
             * creates for its own reasons. Not "unique visitors" — nothing here
             * distinguishes one person on two devices from two people, and
             * making it able to would mean minting an identifier for people who
             * did not ask to be counted.
             */
            $table->unsignedInteger('sessions')->default(0);

            $table->timestamps();

            // One row per day per dimension value; counting is an upsert.
            $table->unique(['date', 'dimension', 'value'], 'visitor_stats_unique');
            $table->index(['dimension', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_stats');
        Schema::dropIfExists('backups_log');
        Schema::dropIfExists('error_reports');
    }
};
