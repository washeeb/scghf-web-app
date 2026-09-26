<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Shop\ShopReports;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The shop's numbers, and the one figure that adds the shop to the giving.
 *
 * Drawn from `ShopReports`, which is tested on its own; this page only
 * picks the window. Tables, not charts, for the same reason as the giving
 * reports: the question is a number in a cell.
 */
class ShopReportsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Shop';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.shop-reports';

    protected static ?string $slug = 'shop-reports';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public string $granularity = 'day';

    public static function getNavigationLabel(): string
    {
        return __('Reports');
    }

    public function getTitle(): string
    {
        return __('Shop reports');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('orders.view') ?? false;
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->until = $this->until ?: now()->toDateString();
    }

    public function reports(): ShopReports
    {
        return ShopReports::between(Carbon::parse($this->from), Carbon::parse($this->until));
    }

    public function setRange(string $range): void
    {
        [$this->from, $this->until, $this->granularity] = match ($range) {
            'week' => [now()->subDays(6)->toDateString(), now()->toDateString(), 'day'],
            'year' => [now()->startOfYear()->toDateString(), now()->toDateString(), 'month'],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString(), 'day'],
            default => [now()->startOfMonth()->toDateString(), now()->toDateString(), 'day'],
        };
    }
}
