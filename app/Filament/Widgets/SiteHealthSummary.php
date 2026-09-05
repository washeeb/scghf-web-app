<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\SiteHealthPage;
use App\Support\HealthCheck;
use App\Support\SiteHealth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * A line on the dashboard saying whether anything is broken.
 *
 * ── It is here because nobody opens a health page ───────────────────────────
 *
 * Site Health answers questions that only get asked once somebody has already
 * noticed a problem — by which point receipts have been sitting in a queue for
 * a week. The dashboard is the screen people actually open, so the summary
 * lives here and the detail lives one click away.
 *
 * ── It shows the worst thing, not a count ───────────────────────────────────
 *
 * "3 problems" is a number somebody defers. "The scheduler has stopped" is a
 * sentence they act on — and among a scheduler failure, a low SMS balance and a
 * placeholder setting, only one of those is why the other two will not fix
 * themselves.
 */
class SiteHealthSummary extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    public static function canView(): bool
    {
        return SiteHealthPage::canAccess();
    }

    protected function getStats(): array
    {
        $problems = app(SiteHealth::class)->problems();

        if ($problems->isEmpty()) {
            return [
                Stat::make(__('Site health'), __('Everything checks out'))
                    ->description(__('Cron, queue, backups, payments and storage all responding'))
                    ->descriptionIcon('heroicon-m-check-circle')
                    ->color('success')
                    ->url(SiteHealthPage::getUrl()),
            ];
        }

        // Critical first: a stopped scheduler is why the SMS balance is not
        // being topped up and the backup is not running.
        $worst = $problems->sortBy(fn (HealthCheck $check): int => $check->status === HealthCheck::CRITICAL ? 0 : 1)
            ->first();

        return [
            Stat::make(__('Site health'), $worst->label.' — '.$worst->value)
                ->description(trans_choice(
                    '{1}One thing needs attention|[2,*]:count things need attention',
                    $problems->count(),
                    ['count' => $problems->count()],
                ))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($worst->colour())
                ->url(SiteHealthPage::getUrl()),
        ];
    }
}
