<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\HealthCheck;
use App\Support\Settings;
use App\Support\SiteHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * "Is this installation ready?" — from the command line.
 *
 * ── It was cited before it existed ──────────────────────────────────────────
 *
 * `Settings::unfilled()` and `SettingType::validationRule()` both point at
 * `scghf:preflight` in their docblocks as the place a problem gets reported.
 * Neither the command nor the report existed, so both comments described a
 * safety net made of nothing — and "the preflight will catch it" is exactly the
 * sentence somebody says while deciding not to check something themselves.
 *
 * ── Why a command as well as the Site Health page ───────────────────────────
 *
 * The page is for the foundation. This is for the deploy: it exits non-zero
 * when something is CRITICAL, so a release script or a cPanel post-deploy hook
 * can refuse to finish. A screen nobody has opened yet cannot do that.
 *
 * ── A placeholder is a warning, not a failure ───────────────────────────────
 *
 * Deliberately. The registration number arriving a week after launch is normal
 * for a Ghanaian non-profit, and a command that fails the deploy over it is one
 * somebody adds `|| true` to — after which it reports nothing at all, including
 * the things that should have stopped the deploy.
 */
class Preflight extends Command
{
    protected $signature = 'scghf:preflight
                            {--strict : Treat warnings as failures too}';

    protected $description = 'Check that this installation is configured and working';

    public function handle(SiteHealth $health, Settings $settings): int
    {
        $this->info('Preflight — '.config('app.name').' ('.app()->environment().')');
        $this->newLine();

        $checks = $health->checks();

        $rows = $checks->map(fn (HealthCheck $check): array => [
            match ($check->status) {
                HealthCheck::OK => '<fg=green>OK</>',
                HealthCheck::WARNING => '<fg=yellow>WARN</>',
                HealthCheck::CRITICAL => '<fg=red>FAIL</>',
                default => '<fg=gray>?</>',
            },
            $check->label,
            $check->value,
        ])->all();

        $this->table(['', 'Check', 'Result'], $rows);

        // The advice, and only for the things that need it. A wall of green
        // explanations is how somebody learns to skip the output.
        $problems = $checks->filter(fn (HealthCheck $check): bool => $check->advice !== null);

        if ($problems->isNotEmpty()) {
            $this->newLine();
            $this->line('<options=bold>What to do</>');

            foreach ($problems as $check) {
                $this->newLine();
                $this->line("  <options=bold>{$check->label}</> — {$check->advice}");
            }
        }

        $this->reportUnfilledSettings($settings);

        return $this->verdict($checks);
    }

    /**
     * The list `Settings::unfilled()` has been able to produce since Phase 3,
     * printed for the first time.
     */
    private function reportUnfilledSettings(Settings $settings): void
    {
        $unfilled = $settings->unfilled();

        if ($unfilled->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('<options=bold>Settings still holding a placeholder</>');
        $this->newLine();

        foreach ($unfilled as $setting) {
            $this->line(sprintf(
                '  %-40s %s',
                $setting->qualifiedKey(),
                $setting->label ?? '',
            ));
        }

        $this->newLine();
        $this->comment('  Fill these in under Site settings. Several appear on receipts.');
    }

    /** @param  Collection<int, HealthCheck>  $checks */
    private function verdict(Collection $checks): int
    {
        $critical = $checks->where('status', HealthCheck::CRITICAL)->count();
        $warnings = $checks->where('status', HealthCheck::WARNING)->count();

        $this->newLine();

        if ($critical > 0) {
            $this->error(sprintf('%d check(s) failed. This installation is not ready.', $critical));

            return self::FAILURE;
        }

        if ($warnings > 0 && $this->option('strict')) {
            $this->error(sprintf('%d warning(s), and --strict was given.', $warnings));

            return self::FAILURE;
        }

        if ($warnings > 0) {
            $this->warn(sprintf('%d warning(s). Nothing is broken, but read them.', $warnings));

            return self::SUCCESS;
        }

        $this->info('Everything checks out.');

        return self::SUCCESS;
    }
}
