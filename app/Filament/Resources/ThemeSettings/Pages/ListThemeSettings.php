<?php

declare(strict_types=1);

namespace App\Filament\Resources\ThemeSettings\Pages;

use App\Filament\Resources\ThemeSettings\ThemeSettingResource;
use App\Models\ThemeSetting;
use App\Support\ThemeTokens;
use Database\Seeders\ThemeSettingsSeeder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/**
 * The palette list, and the way back from a bad afternoon.
 */
class ListThemeSettings extends ListRecords
{
    protected static string $resource = ThemeSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->resetAction(),
        ];
    }

    /**
     * Put every token back to the brand defaults.
     *
     * ── Why this is worth having ────────────────────────────────────────────
     *
     * The palette is the one part of the CMS where an afternoon of small
     * adjustments can leave a site that is off-brand, illegible, and hard to
     * unpick — because nobody remembers what nine hex codes used to be. The
     * seeder holds the values sampled from the logo pack, so there is a known
     * good state to return to.
     *
     * ── It reseeds rather than storing a backup ─────────────────────────────
     *
     * A "previous values" table would be a second source of truth that drifts
     * from the seeder the first time a token is added. Re-running the seeder is
     * the same operation a fresh install performs, so what it restores is by
     * definition the palette the design ships with.
     */
    private function resetAction(): Action
    {
        return Action::make('reset')
            ->label(__('Reset to brand defaults'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Put the palette back to the brand defaults'))
            ->modalDescription(__(
                'Every colour, radius and spacing token returns to the value sampled from the logo '
                .'pack. Anything you have changed is lost. This cannot be undone.'
            ))
            ->modalSubmitActionLabel(__('Reset everything'))
            ->action(function (): void {
                /*
                 * Deleted first. The seeder does not overwrite a token that
                 * already exists — that is what makes it safe to re-run on
                 * deploy — so resetting has to clear the way for it.
                 */
                ThemeSetting::query()->delete();

                app(ThemeSettingsSeeder::class)->run();

                // The inlined `<style>` block is cached forever and busted by
                // the model's save hook, which a mass delete does not fire.
                ThemeTokens::flush();

                Notification::make()
                    ->title(__('The palette is back to the brand defaults.'))
                    ->success()
                    ->send();
            });
    }
}
