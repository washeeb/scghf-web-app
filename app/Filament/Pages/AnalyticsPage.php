<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\AnalyticsReports;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Visits, conversions and which campaigns brought the money — without
 * signing in anywhere else. `visitor_stats.view` was seeded in Phase 3
 * with nothing behind it; this is the screen.
 */
class AnalyticsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 60;

    protected string $view = 'filament.pages.analytics';

    protected static ?string $slug = 'analytics';

    public string $from = '';

    public string $until = '';

    public static function getNavigationLabel(): string
    {
        return __('Site analytics');
    }

    public function getTitle(): string
    {
        return __('Site analytics');
    }

    public function getSubheading(): ?string
    {
        return __('Visits, conversions and campaigns — from this site’s own database, nobody else’s.');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('visitor_stats.view') ?? false;
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now()->subDays(29)->toDateString();
        $this->until = $this->until ?: now()->toDateString();
    }

    public function reports(): AnalyticsReports
    {
        return AnalyticsReports::between(Carbon::parse($this->from), Carbon::parse($this->until));
    }

    public function setRange(string $range): void
    {
        [$this->from, $this->until] = match ($range) {
            'week' => [now()->subDays(6)->toDateString(), now()->toDateString()],
            'month' => [now()->startOfMonth()->toDateString(), now()->toDateString()],
            'quarter' => [now()->subDays(89)->toDateString(), now()->toDateString()],
            'year' => [now()->startOfYear()->toDateString(), now()->toDateString()],
            default => [now()->subDays(29)->toDateString(), now()->toDateString()],
        };
    }
}
