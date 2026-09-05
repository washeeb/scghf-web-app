<?php

declare(strict_types=1);

namespace App\Filament\Resources\ThemeSettings\Schemas;

use App\Models\ThemeSetting;
use App\Support\ContrastChecker;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Editing one token.
 *
 * ── The contrast is checked as you type, not on save ────────────────────────
 *
 * `live(onBlur: true)` means the ratio under the field updates the moment the
 * colour changes. A checker that only speaks at save time is one you consult
 * after you have already decided — and the person picking a brand colour has
 * usually stopped thinking about legibility by then.
 *
 * ── A failing colour is a warning, not a rejection ──────────────────────────
 *
 * Deliberately. WCAG AA is a stated requirement of this project, and it is
 * still a design decision made by people who can see the whole page: a token
 * might be used only on a surface this check does not know about, and a hard
 * refusal would leave somebody unable to save with no way forward. The audit
 * that fails a build is elsewhere; this is the part that tells a human.
 */
class ThemeSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (?ThemeSetting $record): string => $record?->label ?? __('Token'))
                ->description(fn (?ThemeSetting $record): ?string => $record?->description)
                ->schema([
                    TextEntry::make('token')
                        ->label(__('Name'))
                        ->state(fn (ThemeSetting $record): string => sprintf('--%s (%s)', $record->token, $record->theme)),

                    ColorPicker::make('value')
                        ->label(__('Colour'))
                        ->required()
                        ->live(onBlur: true)
                        ->visible(fn (?ThemeSetting $record): bool => (bool) $record?->isColour())
                        ->helperText(__('Sampled from the logo pack. Changing it moves every use of this token across the site.')),

                    TextInput::make('value')
                        ->label(__('Value'))
                        ->required()
                        ->maxLength(191)
                        ->visible(fn (?ThemeSetting $record): bool => ! ($record?->isColour() ?? true))
                        ->helperText(__('A size, a radius or a font stack. Not a colour, so there is nothing to check contrast against.')),

                    TextEntry::make('contrast')
                        ->label(__('Contrast against :token', ['token' => '…']))
                        ->visible(fn (?ThemeSetting $record): bool => $record?->contrast_against !== null)
                        /*
                         * Computed from the value being TYPED, not the one in
                         * the database. Reading the record here would show the
                         * ratio of the colour they are replacing, which is the
                         * one number that is certainly not useful.
                         */
                        ->state(function (Get $get, ThemeSetting $record): string {
                            $partner = $record->contrastPartner();

                            if ($partner === null) {
                                return __('The token this is checked against is missing.');
                            }

                            $ratio = app(ContrastChecker::class)
                                ->ratioRounded((string) $get('value'), $partner->value);

                            $needs = (float) $record->min_contrast;

                            return __(':ratio against :token — needs :needs. :verdict', [
                                'ratio' => number_format($ratio, 2).':1',
                                'token' => $partner->label,
                                'needs' => number_format($needs, 1).':1',
                                'verdict' => $ratio >= $needs
                                    ? __('Passes WCAG AA.')
                                    : __('FAILS WCAG AA — text in this colour will be hard to read.'),
                            ]);
                        })
                        ->color(function (Get $get, ThemeSetting $record): string {
                            $partner = $record->contrastPartner();

                            if ($partner === null) {
                                return 'gray';
                            }

                            return app(ContrastChecker::class)
                                ->ratioRounded((string) $get('value'), $partner->value) >= (float) $record->min_contrast
                                    ? 'success'
                                    : 'danger';
                        }),
                ]),
        ]);
    }
}
