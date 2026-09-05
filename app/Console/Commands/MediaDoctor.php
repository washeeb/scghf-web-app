<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Media\ImageToolchain;
use App\Models\Media;
use Illuminate\Console\Command;

/**
 * What this server can do to an image, and how close the library is to the
 * inode wall.
 *
 * ── Run it on the server, not on the laptop ─────────────────────────────────
 *
 * That is the whole point. Every answer here is different on a developer's
 * machine from the answer on the shared account the site runs on, and the ones
 * that matter — whether exec is disabled, what upload_max_filesize really is,
 * whether AVIF can be encoded at all — are exactly the ones nobody can guess.
 *
 * Exits non-zero when something needs attention, so it can be a deploy check
 * rather than a thing somebody remembers to run.
 */
class MediaDoctor extends Command
{
    protected $signature = 'scghf:media-doctor';

    protected $description = 'Report what this server can do to images, and how much inode budget the media library is using.';

    public function handle(ImageToolchain $toolchain): int
    {
        $report = $toolchain->report();

        $this->components->info('Image toolchain');
        $this->newLine();

        $this->table(['', ''], [
            ['Imagick', $this->yesNo($report['driver']['imagick']).($report['driver']['imagick_version'] ? '  '.$report['driver']['imagick_version'] : '')],
            ['GD', $this->yesNo($report['driver']['gd'])],
            ['Driver in use', $report['driver']['configured']],
            ['Can encode WebP', $this->yesNo($report['formats']['webp'])],
            ['Can encode AVIF', $this->yesNo($report['formats']['avif'])],
            ['Conversions written as', $report['formats']['will_write'] === []
                ? 'the original format (no conversion format available)'
                : implode(', ', $report['formats']['will_write'])],
        ]);

        $this->newLine();
        $this->components->info('PHP limits — these are the real ceiling, not config/media.php');
        $this->newLine();

        $this->table(['', ''], [
            ['upload_max_filesize', $report['php']['upload_max_filesize']],
            ['post_max_size', $report['php']['post_max_size']],
            ['memory_limit', $report['php']['memory_limit']],
            ['max_execution_time', $report['php']['max_execution_time']],
            ['fileinfo extension', $this->yesNo($report['php']['fileinfo'])],
            ['exif extension', $this->yesNo($report['php']['exif'])],
        ]);

        $this->newLine();
        $this->components->info('Optimisers');
        $this->newLine();

        $this->line($report['optimisers']['can_run_binaries']
            ? '  PHP may launch external processes.'
            : '  <fg=yellow>PHP may NOT launch external processes — every optimiser below is unusable</>');

        foreach ($report['optimisers']['found'] as $binary => $found) {
            $this->line(sprintf('  %s %s', $found ? '<fg=green>✓</>' : '<fg=gray>·</>', $binary));
        }

        $this->newLine();
        $this->reportInodes();

        $warnings = $toolchain->warnings();

        if ($warnings === []) {
            $this->newLine();
            $this->components->info('Nothing needs attention.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->warn('Worth knowing');
        $this->newLine();

        foreach ($warnings as $warning) {
            $this->line('  • '.wordwrap($warning, 92, "\n    "));
            $this->newLine();
        }

        /*
         * Non-zero, so cron emails somebody and a deploy check fails. None of
         * these warnings is an outage — every one of them is the site quietly
         * doing less than it says it does, which is the failure mode this whole
         * project keeps trying to make loud.
         */
        return self::FAILURE;
    }

    /**
     * How many files the library owns, against the account's quota.
     *
     * Shared hosting counts FILES, not bytes, and a media library is what
     * exhausts that count — quietly, one upload at a time, until a deploy fails
     * to write. Each image is its original plus its conversions.
     */
    private function reportInodes(): void
    {
        $this->components->info('Inode budget');
        $this->newLine();

        $count = Media::count();

        if ($count === 0) {
            $this->line('  No media yet.');

            return;
        }

        /*
         * Summed from `generated_conversions` rather than by walking the disk.
         * Walking is the accurate answer and takes minutes over a shared NFS
         * mount; the database knows what it asked for, and the difference
         * between the two is itself worth knowing — see the orphan note below.
         */
        $files = Media::query()->get(['generated_conversions'])
            ->sum(fn (Media $media): int => $media->inodeCost());

        $budget = (int) config('media.inode_budget', 200_000);
        $share = $budget > 0 ? ($files / $budget) * 100 : 0.0;

        $this->table(['', ''], [
            ['Media rows', number_format($count)],
            ['Files on disk (originals + conversions)', number_format($files)],
            ['Files per image, average', $count > 0 ? round($files / $count, 1) : 0],
            ['Account inode budget', number_format($budget)],
            ['Used by the media library', sprintf('%.1f%%', $share)],
        ]);

        if ($share >= 50.0) {
            $this->components->warn(sprintf(
                'The media library is using %.0f%% of the inode budget. Raise MEDIA_INODE_BUDGET if '
                .'that is not the real quota — and if it is, the next thing to go is the ability to '
                .'write files at all, which looks like a broken deploy rather than a full disk.',
                $share,
            ));
        }

        /*
         * The count above is what the database believes. Orphans — files left
         * behind by a failed conversion or an interrupted replace — are on disk
         * and in nobody's row, so they are invisible to it and count against
         * the quota anyway.
         */
        $unsanitised = Media::query()->unsanitised()->count();

        if ($unsanitised > 0) {
            $this->components->warn(sprintf(
                '%d image%s not had metadata removed, so %s no conversions at all and cannot be '
                .'published. Run `scghf:strip-media-metadata --execute` then '
                .'`scghf:regenerate-media-conversions`.',
                $unsanitised,
                $unsanitised === 1 ? ' has' : 's have',
                $unsanitised === 1 ? 'it has' : 'they have',
            ));
        }
    }

    private function yesNo(bool $value): string
    {
        return $value ? '<fg=green>yes</>' : '<fg=yellow>no</>';
    }
}
