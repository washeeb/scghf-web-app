<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\DonationStatus;
use App\Models\Cause;
use App\Models\Donation;
use App\ValueObjects\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which appeals people are actually giving to, this month.
 *
 * ── This month, not all time ────────────────────────────────────────────────
 *
 * An all-time table is a permanent ranking in which the appeal that ran two
 * Christmases ago sits at the top for ever, and nothing anybody does this week
 * changes it. The month is the window in which a decision — push this appeal,
 * quietly retire that one — is still available.
 *
 * ── Progress is not capped at 100% ──────────────────────────────────────────
 *
 * An appeal that raised 140% of its target should say so. Capping it hides the
 * one piece of news worth telling the trustees.
 */
class TopCauses extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return __('Where this month\'s giving went');
    }

    public static function canView(): bool
    {
        return auth()->user()?->can('donations.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->query())
            ->defaultSort('month_total', 'desc')
            ->paginated(false)
            ->emptyStateHeading(__('No giving recorded this month yet'))
            ->emptyStateDescription(__('Completed donations appear here as they arrive.'))
            ->columns([
                TextColumn::make('title')
                    ->label(__('Appeal'))
                    ->wrap(),

                TextColumn::make('month_total')
                    ->label(__('This month'))
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => Money::ofMinor((int) $state)->format()),

                TextColumn::make('month_gifts')
                    ->label(__('Gifts'))
                    ->alignEnd(),

                TextColumn::make('raised_minor')
                    ->label(__('Raised in total'))
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => Money::ofMinor((int) $state)->format())
                    ->description(fn (Cause $record): ?string => $record->progressPercent() === null
                        ? null
                        : __(':percent% of target', ['percent' => $record->progressPercent()])),
            ]);
    }

    /** @return Builder<Cause> */
    private function query(): Builder
    {
        /*
         * Aggregated in SQL with a correlated subquery rather than by loading
         * every donation and grouping in PHP. The dashboard is the page most
         * often left open in a tab, and it should not be the most expensive
         * query in the application.
         */
        $window = [now()->startOfMonth(), now()];

        return Cause::query()
            ->select('causes.*')
            ->withCount([])
            ->addSelect([
                'month_total' => Donation::query()
                    ->selectRaw('COALESCE(SUM(amount_minor), 0)')
                    ->whereColumn('donations.cause_id', 'causes.id')
                    ->where('donations.status', DonationStatus::Completed->value)
                    ->whereBetween('donations.paid_at', $window),

                'month_gifts' => Donation::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('donations.cause_id', 'causes.id')
                    ->where('donations.status', DonationStatus::Completed->value)
                    ->whereBetween('donations.paid_at', $window),
            ])
            ->havingRaw('month_total > 0')
            ->limit(5);
    }
}
