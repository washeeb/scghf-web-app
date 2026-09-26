<?php

declare(strict_types=1);

namespace App\Filament\Resources\ThemeSettings;

use App\Filament\Resources\ThemeSettings\Pages\EditThemeSetting;
use App\Filament\Resources\ThemeSettings\Pages\ListThemeSettings;
use App\Filament\Resources\ThemeSettings\Schemas\ThemeSettingForm;
use App\Filament\Resources\ThemeSettings\Tables\ThemeSettingsTable;
use App\Models\ThemeSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The palette.
 *
 * No create and no delete: the token list is what the stylesheet references, so
 * a token nobody wrote a rule for does nothing, and removing one leaves a
 * `var(--missing)` that renders as nothing at all. What is editable is the
 * VALUE of each, which is the part the foundation owns.
 *
 * The badge counts tokens failing their own declared contrast. It has been
 * computable since Phase 3 and shown nowhere.
 */
class ThemeSettingResource extends Resource
{
    protected static ?string $model = ThemeSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'label';

    public static function getNavigationLabel(): string
    {
        return __('Theme');
    }

    public static function getNavigationBadge(): ?string
    {
        $failing = static::failingCount();

        return $failing > 0 ? (string) $failing : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * How many colour tokens no longer meet the contrast they declare.
     *
     * Every one of these is text somebody cannot read. WCAG 2.2 AA is a stated
     * requirement of this project, and this is the only place in the panel that
     * would ever mention a failure.
     */
    public static function failingCount(): int
    {
        return ThemeSetting::query()
            ->withContrastObligation()
            ->get()
            ->reject(fn (ThemeSetting $token): bool => $token->meetsContrast())
            ->count();
    }

    public static function form(Schema $schema): Schema
    {
        return ThemeSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ThemeSettingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListThemeSettings::route('/'),
            'edit' => EditThemeSetting::route('/{record}/edit'),
        ];
    }
}
