<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Media;
use Illuminate\Console\Command;

/**
 * Sanitises images that were uploaded before anything checked them.
 *
 * New uploads are handled on the way in. This is for the library that already
 * exists — and for verifying, rather than assuming, that the ones marked
 * sanitised actually are.
 *
 *     php artisan scghf:strip-media-metadata --execute
 *
 * Dry by default. It rewrites files in place, and a command that does that on a
 * bare invocation is one keystroke from a mistake.
 */
class StripMediaMetadata extends Command
{
    protected $signature = 'scghf:strip-media-metadata
                            {--execute : Actually rewrite the files}
                            {--verify : Re-check files already marked sanitised}
                            {--limit=200 : How many to process}';

    protected $description = 'Remove camera metadata from images that have not been sanitised';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');

        if (! $execute) {
            $this->warn('DRY RUN — nothing will be rewritten. Add --execute to sanitise.');
        }

        $pending = Media::query()->unsanitised()->limit((int) $this->option('limit'))->get();

        $this->line("{$pending->count()} image(s) have never been checked.");

        $stripped = $carriedLocation = $failed = 0;

        if ($execute) {
            foreach ($pending as $media) {
                $media->stripMetadata();

                if ($media->sanitisation_error !== null) {
                    $failed++;
                    $this->warn("  {$media->file_name}: {$media->sanitisation_error}");

                    continue;
                }

                $stripped++;

                if ($media->had_gps_data) {
                    $carriedLocation++;
                    $this->line("  {$media->file_name} carried location data.");
                }
            }
        }

        $exit = $this->verifyIfAsked();

        $this->newLine();
        $this->line(sprintf(
            'Sanitised: %d   Carried location data: %d   Failed: %d',
            $stripped, $carriedLocation, $failed,
        ));

        if ($carriedLocation > 0) {
            $this->warn(
                'Images arriving with coordinates in them means somebody is photographing on a '
                .'phone with location services on. Stripping the file is the fix for these; the '
                .'conversation is the fix for the next ones.'
            );
        }

        return $failed > 0 ? self::FAILURE : $exit;
    }

    /**
     * Check that files marked sanitised really are.
     *
     * `metadata_stripped_at` records that the sanitiser RAN. This checks
     * whether it WORKED — which is a different claim, and the one an auditor
     * would actually want.
     */
    private function verifyIfAsked(): int
    {
        if (! $this->option('verify')) {
            return self::SUCCESS;
        }

        $suspect = Media::query()
            ->whereNotNull('metadata_stripped_at')
            ->limit((int) $this->option('limit'))
            ->get()
            ->filter(fn (Media $media): bool => $media->stillCarriesMetadata());

        if ($suspect->isEmpty()) {
            $this->info('Verified: every file marked sanitised is clean.');

            return self::SUCCESS;
        }

        $this->error(
            "{$suspect->count()} file(s) are marked sanitised but still carry metadata. "
            .'Re-run with --execute.'
        );

        foreach ($suspect as $media) {
            $this->line('  '.$media->file_name);
        }

        return self::FAILURE;
    }
}
