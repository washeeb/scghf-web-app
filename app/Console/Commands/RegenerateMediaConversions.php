<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Media;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Throwable;

/**
 * Rebuild the thumb/card/hero files for images that are missing them.
 *
 * ── The case this exists for ────────────────────────────────────────────────
 *
 * An image whose metadata could not be stripped gets NO conversions, on
 * purpose — see App\Media\Concerns\HasLibraryMedia. Once the cause is fixed and
 * `scghf:strip-media-metadata --execute` has cleaned the originals, the
 * conversions still do not exist, because nothing re-runs them.
 *
 * Also covers the ordinary reasons: a conversion width changed, WebP became
 * available on the account after an upgrade, or a queue worker died halfway
 * through a bulk import.
 *
 * ── Dry by default ─────────────────────────────────────────────────────────
 *
 * Not because regenerating is dangerous — it is idempotent — but because on
 * shared hosting it is EXPENSIVE. Rebuilding three conversions for two thousand
 * images is hours of CPU on an account that is sharing it, and the kind of
 * thing that gets a foundation a warning email from its host. Seeing the number
 * first is what lets somebody decide to run it overnight in batches.
 */
class RegenerateMediaConversions extends Command
{
    protected $signature = 'scghf:regenerate-media-conversions
                            {--execute : Actually generate them}
                            {--limit=100 : How many to process in one run}
                            {--all : Include images that already have every conversion}';

    protected $description = 'Rebuild missing image conversions, for images that were skipped or whose settings changed.';

    public function handle(FileManipulator $manipulator): int
    {
        $expected = array_keys((array) config('media.conversions', []));

        $candidates = Media::query()
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])
            /*
             * Only sanitised files. An unsanitised one would be skipped by the
             * conversion registration anyway, so including it would report work
             * that then silently did nothing — and the honest answer for those
             * is "strip the metadata first", which the summary says.
             */
            ->whereNotNull('metadata_stripped_at')
            ->whereNull('sanitisation_error')
            ->orderBy('id')
            ->get();

        $needing = $this->option('all')
            ? $candidates
            : $candidates->filter(
                fn (Media $media): bool => $this->missingConversions($media, $expected) !== []
            );

        $blocked = Media::query()->unsanitised()->count();

        if ($needing->isEmpty()) {
            $this->components->info('Every sanitised image already has its conversions.');
            $this->reportBlocked($blocked);

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $batch = $needing->take($limit);

        if (! $this->option('execute')) {
            $this->components->warn(sprintf(
                '%d image%s missing conversions. This run would rebuild %d of them.',
                $needing->count(),
                $needing->count() === 1 ? ' is' : 's are',
                $batch->count(),
            ));

            $this->newLine();
            $this->line('  Re-run with <fg=cyan>--execute</> to generate them.');
            $this->line('  Rebuilding is CPU-heavy on shared hosting — consider running it in');
            $this->line('  batches, overnight, with --limit.');

            $this->reportBlocked($blocked);

            return self::SUCCESS;
        }

        $done = 0;
        $failed = 0;

        $this->withProgressBar($batch, function (Media $media) use ($manipulator, &$done, &$failed): void {
            try {
                // `false` for `onlyMissing`: the conversions this rebuilds are
                // either absent or being rebuilt because their definition
                // changed, and in the second case "only missing" would do
                // nothing while reporting success.
                $manipulator->createDerivedFiles($media, [], false);
                $done++;
            } catch (Throwable $e) {
                $failed++;

                $this->newLine();
                $this->components->error(sprintf(
                    'Media %d (%s): %s',
                    $media->getKey(),
                    $media->file_name,
                    $e->getMessage(),
                ));
            }
        });

        $this->newLine(2);
        $this->components->info(sprintf('Rebuilt %d.', $done));

        if ($failed > 0) {
            $this->components->error(sprintf('%d failed — see above.', $failed));
        }

        $remaining = $needing->count() - $batch->count();

        if ($remaining > 0) {
            $this->components->warn(sprintf('%d still to do. Run again to continue.', $remaining));
        }

        $this->reportBlocked($blocked);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $expected
     * @return array<int, string>
     */
    private function missingConversions(Media $media, array $expected): array
    {
        $generated = array_keys(array_filter((array) $media->generated_conversions));

        return array_values(array_diff($expected, $generated));
    }

    /**
     * Say what this command cannot fix.
     *
     * An unsanitised image is not a conversion problem and regenerating will
     * never help it, so reporting "0 missing" without this would be true and
     * misleading at the same time.
     */
    private function reportBlocked(int $blocked): void
    {
        if ($blocked === 0) {
            return;
        }

        $this->newLine();
        $this->components->warn(sprintf(
            '%d image%s no conversions because %s metadata has not been removed, and this command '
            .'cannot fix that. Run `scghf:strip-media-metadata --execute` first — an unsanitised '
            .'photograph is deliberately given no derivatives, because each one would be another '
            .'copy of it carrying whatever the camera wrote in.',
            $blocked,
            $blocked === 1 ? ' has' : 's have',
            $blocked === 1 ? 'its' : 'their',
        ));
    }
}
