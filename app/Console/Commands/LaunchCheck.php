<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\HealthCheck;
use App\Support\LaunchChecks;
use App\Support\SiteHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The go-live checklist, answered.
 *
 *     php artisan scghf:launch-check
 *
 * Runs `scghf:preflight`'s checks (does the installation work?) and then
 * the launch checks (is it ready to be public?): legal pages, content,
 * the live gift, the mail domain's DNS, the certificate, staff second
 * factors, leftover demo data. Exit code 1 on any critical row, so the
 * launch runbook can say "green means go" and mean it.
 */
class LaunchCheck extends Command
{
    protected $signature = 'scghf:launch-check
                            {--strict : Treat warnings as failures too}';

    protected $description = 'Every pre-launch check the application can answer itself: preflight, then readiness';

    public function handle(SiteHealth $health, LaunchChecks $launch): int
    {
        $this->info('Launch check — '.config('app.name').' ('.app()->environment().', '.config('app.url').')');

        $this->newLine();
        $this->line('<options=bold>Does it work?</> (scghf:preflight)');
        $installation = $health->checks();
        $this->render($installation);

        $this->newLine();
        $this->line('<options=bold>Is it ready to be public?</>');
        $readiness = $launch->checks();
        $this->render($readiness);

        $all = $installation->concat($readiness);
        $problems = $all->filter(fn (HealthCheck $c): bool => $c->advice !== null);

        if ($problems->isNotEmpty()) {
            $this->newLine();
            $this->line('<options=bold>What to do</>');

            foreach ($problems as $check) {
                $this->newLine();
                $this->line("  <options=bold>{$check->label}</> — {$check->advice}");
            }
        }

        $critical = $all->where('status', HealthCheck::CRITICAL)->count();
        $warnings = $all->where('status', HealthCheck::WARNING)->count();

        $this->newLine();

        if ($critical > 0) {
            $this->error(sprintf('%d blocker(s). Not ready to launch.', $critical));

            return self::FAILURE;
        }

        if ($warnings > 0) {
            $this->warn(sprintf('%d warning(s). Read them; launch is a decision, not a script.', $warnings));

            return $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        $this->info('Green. Nothing the application can see stands in the way.');

        return self::SUCCESS;
    }

    /** @param Collection<int, HealthCheck> $checks */
    private function render(Collection $checks): void
    {
        $this->table(['', 'Check', 'Result'], $checks->map(fn (HealthCheck $check): array => [
            match ($check->status) {
                HealthCheck::OK => '<fg=green>OK</>',
                HealthCheck::WARNING => '<fg=yellow>WARN</>',
                HealthCheck::CRITICAL => '<fg=red>FAIL</>',
                default => '<fg=gray>?</>',
            },
            $check->label,
            $check->value,
        ])->all());
    }
}
