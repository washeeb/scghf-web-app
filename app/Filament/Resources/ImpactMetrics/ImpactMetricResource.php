<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImpactMetrics;

use App\Filament\Resources\ImpactMetrics\Pages\CreateImpactMetric;
use App\Filament\Resources\ImpactMetrics\Pages\EditImpactMetric;
use App\Filament\Resources\ImpactMetrics\Pages\ListImpactMetrics;
use App\Filament\Resources\ImpactMetrics\Schemas\ImpactMetricForm;
use App\Filament\Resources\ImpactMetrics\Tables\ImpactMetricsTable;
use App\Models\ImpactMetric;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ImpactMetricResource extends Resource
{
    protected static ?string $model = ImpactMetric::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Programmes';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'Impact measure';

    protected static ?string $pluralModelLabel = 'Impact';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ImpactMetricForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImpactMetricsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImpactMetrics::route('/'),
            'create' => CreateImpactMetric::route('/create'),
            'edit' => EditImpactMetric::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
