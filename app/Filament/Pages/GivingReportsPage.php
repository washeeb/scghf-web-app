<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Payments\GivingReports;
use App\Payments\ReconciliationService;
use App\Support\AuditLogger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The finance reports.
 *
 * ── One period, every cut ───────────────────────────────────────────────────
 *
 * Pick a window and see the totals, the split by period, appeal, channel,
 * region and source, the new-versus-returning donors, the state of regular
 * giving, and what has not yet been reconciled. The numbers come from
 * `GivingReports`, which is tested on its own; this page only draws them.
 *
 * ── The reconciliation report is a dry run of the real thing ────────────────
 *
 * "Run reconciliation" here runs the same `ReconciliationService` the 06:30
 * cron runs, with `--execute`, and shows its summary. It takes no money and
 * asks the gateway what it already knows.
 */
class GivingReportsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.giving-reports';

    protected static ?string $slug = 'giving-reports';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public string $granularity = 'day';

    /** @var array<string, mixed>|null */
    public ?array $reconciliationRun = null;

    public static function getNavigationLabel(): string
    {
        return __('Reports');
    }

    public function getTitle(): string
    {
        return __('Giving reports');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('donations.view') ?? false;
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->until = $this->until ?: now()->toDateString();
    }

    public function reports(): GivingReports
    {
        return GivingReports::between(
            Carbon::parse($this->from),
            Carbon::parse($this->until),
        );
    }

    /** The quick ranges at the top of the page. */
    public function setRange(string $range): void
    {
        [$this->from, $this->until, $this->granularity] = match ($range) {
            'week' => [now()->subDays(6)->toDateString(), now()->toDateString(), 'day'],
            'year' => [now()->startOfYear()->toDateString(), now()->toDateString(), 'month'],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString(), 'day'],
            default => [now()->startOfMonth()->toDateString(), now()->toDateString(), 'day'],
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reconcile')
                ->label(__('Run reconciliation now'))
                ->icon('heroicon-o-scale')
                ->visible(fn (): bool => auth()->user()->can('payments.reconcile'))
                ->requiresConfirmation()
                ->modalDescription(__('Asks the gateway about every recent payment, writes off the ones the donor abandoned, recovers any that settled late, and lists anything that needs a person. Takes no money.'))
                ->action(function (): void {
                    $summary = app(ReconciliationService::class)->run(true);
                    $this->reconciliationRun = $summary;

                    app(AuditLogger::class)->record('reconciliation.run', 'Reconciliation run from the reports page', null, auth()->user(), [
                        'checked' => $summary['checked'] ?? null,
                        'recovered' => $summary['recovered'] ?? null,
                        'abandoned' => $summary['abandoned'] ?? null,
                    ]);

                    Notification::make()
                        ->title(__('Reconciliation finished'))
                        ->body(__('Checked :checked, recovered :recovered, abandoned :abandoned, needing review :review.', [
                            'checked' => $summary['checked'] ?? 0,
                            'recovered' => $summary['recovered'] ?? 0,
                            'abandoned' => $summary['abandoned'] ?? 0,
                            'review' => $summary['mismatches'] ?? 0,
                        ]))
                        ->{app(ReconciliationService::class)->needsAttention($summary) ? 'warning' : 'success'}()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
