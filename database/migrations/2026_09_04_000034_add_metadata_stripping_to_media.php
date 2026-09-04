<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence that an uploaded image had its metadata removed.
 *
 * ── Why this is not a nice-to-have ──────────────────────────────────────────
 *
 * `CLAUDE.md` lists "EXIF/GPS stripping on uploaded images" among the things
 * commonly forgotten on projects like this. It was, and this closes it.
 *
 * The concrete failure: a volunteer photographs a beneficiary outside their
 * home on a phone with location services on. The JPEG carries the coordinates
 * of that home, to a few metres. The photograph is uploaded, published on the
 * website, and anybody who downloads it can read the address of a vulnerable
 * child out of the file.
 *
 * Nothing about that is visible in the admin panel. The image looks like an
 * image. It is the most serious privacy failure this application could have,
 * and it happens by default unless something removes the metadata.
 *
 * ── Why columns rather than just doing it ───────────────────────────────────
 *
 * Because "we strip metadata" is a claim, and a claim needs evidence. These
 * columns let the foundation show WHICH files were sanitised and WHEN, and let
 * the publication gate refuse an image that has not been — rather than trusting
 * that the upload path was the one that ran.
 *
 * `had_gps_data` is kept because it is a safeguarding signal in itself: a run of
 * uploads carrying coordinates means somebody's phone is configured in a way
 * that needs a conversation, not just a stripped file.
 *
 * ── What is deliberately NOT stored ─────────────────────────────────────────
 *
 * The metadata itself. Only the KEY NAMES are recorded. Storing the coordinates
 * we removed, in a column next to the photograph, would be a complete defeat of
 * the exercise — and it is exactly the shape of mistake that gets made when
 * somebody wants an audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            /*
             * Null means NOT YET SANITISED, and the publication gate reads it
             * that way. An image uploaded before this migration ran is null,
             * which is correct: nothing has looked at it.
             */
            $table->timestamp('metadata_stripped_at')->nullable()->after('caption');

            /*
             * The signal worth keeping. Not the coordinates — the fact that
             * there were some.
             */
            $table->boolean('had_gps_data')->default(false)->after('metadata_stripped_at');

            /*
             * Which keys were removed, by NAME only. `GPSLatitude`, `Make`,
             * `DateTimeOriginal`. Never their values.
             */
            $table->json('stripped_metadata_keys')->nullable()->after('had_gps_data');

            /*
             * Why sanitising failed, if it did.
             *
             * A failure must be loud and must block publication. The dangerous
             * outcome is not "stripping failed" — it is "stripping failed and
             * the image was published anyway because nothing checked".
             */
            $table->string('sanitisation_error', 500)->nullable()->after('stripped_metadata_keys');

            $table->index('metadata_stripped_at');
            $table->index('had_gps_data');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['metadata_stripped_at']);
            $table->dropIndex(['had_gps_data']);
            $table->dropColumn([
                'metadata_stripped_at', 'had_gps_data',
                'stripped_metadata_keys', 'sanitisation_error',
            ]);
        });
    }
};
