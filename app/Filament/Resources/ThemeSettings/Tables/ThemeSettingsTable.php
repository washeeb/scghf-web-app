<?php

declare(strict_types=1);

namespace App\Filament\Resources\ThemeSettings\Tables;

use App\Models\ThemeSetting;
use App\Support\ContrastChecker;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The palette, with the contrast checker in front of it.
 *
 * ── The ratio is a column, not a validation message ─────────────────────────
 *
 * `ThemeSetting` has carried `contrastRatio()` and `meetsContrast()` since
 * Phase 3 with nothing showing them. A contrast failure that only appears when
 * you try to save is one you meet after choosing; a column shows every token's
 * standing at once, which is how somebody adjusting a palette actually works —
 * change the green, look down the list, see what broke.
 *
 * ── Both themes in one table ────────────────────────────────────────────────
 *
 * Grouped rather than split across two screens. The commonest way a themed site
 * fails accessibility is a palette checked in light and never in dark, and
 * separating them makes that the default outcome. Filtering to one theme is a
 * click; forgetting the other is free.
 */
class ThemeSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->defaultGroup('theme')
            ->columns([
                TextColumn::make('label')
                    ->label(__('Token'))
                    ->searchable()
                    ->description(fn (ThemeSetting $record): string => (string) $record->token),

                TextColumn::make('value')
                    ->label(__('Value'))
                    ->badge()
                    /*
                     * The swatch. A hex code tells nobody what the colour is,
                     * and a palette screen where the colours are invisible is a
                     * spreadsheet.
                     */
                    ->color(fn (ThemeSetting $record): string|array|null => $record->isColour()
                        ? Color::hex($record->value)
                        : null)
                    ->copyable(),

                TextColumn::make('contrast')
                    ->label(__('Contrast'))
                    ->state(fn (ThemeSetting $record): string => $record->contrastRatio() === null
                        ? '—'
                        : number_format($record->contrastRatio(), 2).':1')
                    ->description(fn (ThemeSetting $record): ?string => $record->contrast_against
                        ? __('against :token, needs :min', [
                            'token' => $record->contrast_against,
                            'min' => number_format((float) $record->min_contrast, 1).':1',
                        ])
                        : null)
                    ->badge()
                    ->color(fn (ThemeSetting $record): string => match (true) {
                        $record->contrastRatio() === null => 'gray',
                        $record->meetsContrast() => 'success',
                        default => 'danger',
                    }),

                TextColumn::make('category')
                    ->label(__('Kind'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('theme')
                    ->label(__('Theme'))
                    ->options(['light' => __('Light'), 'dark' => __('Dark'), 'vibrant' => __('Vibrant')]),

                SelectFilter::make('category')
                    ->label(__('Kind'))
                    ->options(fn (): array => ThemeSetting::query()
                        ->distinct()
                        ->orderBy('category')
                        ->pluck('category', 'category')
                        ->all()),

                Filter::make('failing')
                    ->label(__('Failing contrast'))
                    ->toggle()
                    /*
                     * Resolved in PHP rather than in SQL. The ratio is computed
                     * from two rows in different records, which a WHERE clause
                     * cannot express without doing the colour arithmetic in
                     * MySQL — and the table is a few dozen rows.
                     */
                    ->query(fn (Builder $query): Builder => $query->whereIn(
                        'id',
                        ThemeSetting::query()
                            ->withContrastObligation()
                            ->get()
                            ->reject(fn (ThemeSetting $token): bool => $token->meetsContrast())
                            ->pluck('id'),
                    )),
            ])
            ->recordActions([
                EditAction::make(),
                self::suggestAction(),
            ]);
    }

    /**
     * "Make this legible."
     *
     * Offered only for a token that is currently failing, and it changes the
     * FOREGROUND rather than the background — the background is usually a brand
     * colour somebody chose deliberately, and the text on it is the part with
     * no opinion of its own.
     *
     * It suggests rather than applying silently, because a palette is a design
     * decision. The tool's job is to stop an illegible one shipping, not to
     * take the choice away.
     */
    private static function suggestAction(): Action
    {
        return Action::make('suggest')
            ->label(__('Fix contrast'))
            ->icon('heroicon-o-sparkles')
            ->visible(fn (ThemeSetting $record): bool => ! $record->meetsContrast())
            ->requiresConfirmation()
            ->modalHeading(__('Use a legible colour instead'))
            ->modalDescription(fn (ThemeSetting $record): string => __(
                ':label is :ratio against :against and needs :min. The suggestion is black or white, '
                .'whichever is more legible on that background.',
                [
                    'label' => $record->label,
                    'ratio' => number_format((float) $record->contrastRatio(), 2).':1',
                    'against' => $record->contrast_against,
                    'min' => number_format((float) $record->min_contrast, 1).':1',
                ],
            ))
            ->action(function (ThemeSetting $record): void {
                $partner = $record->contrastPartner();

                if ($partner === null) {
                    return;
                }

                $record->update([
                    'value' => app(ContrastChecker::class)->bestTextOn($partner->value),
                ]);

                Notification::make()
                    ->title(__('Updated'))
                    ->body(__('Now :ratio.', [
                        'ratio' => number_format((float) $record->fresh()->contrastRatio(), 2).':1',
                    ]))
                    ->success()
                    ->send();
            });
    }
}
