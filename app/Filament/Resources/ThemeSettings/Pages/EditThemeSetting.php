<?php

declare(strict_types=1);

namespace App\Filament\Resources\ThemeSettings\Pages;

use App\Filament\Resources\ThemeSettings\ThemeSettingResource;
use Filament\Resources\Pages\EditRecord;

/**
 * No delete action: a token the stylesheet references cannot be removed
 * without leaving a `var(--missing)` behind, which renders as nothing.
 */
class EditThemeSetting extends EditRecord
{
    protected static string $resource = ThemeSettingResource::class;
}
