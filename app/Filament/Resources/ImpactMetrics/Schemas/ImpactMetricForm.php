<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImpactMetrics\Schemas;

use App\Models\Division;
use App\Models\ImpactMetric;
use App\Models\Project;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * What the foundation measures, and what it has measured.
 *
 * ── "Counts people" is a safeguarding switch, not a label ───────────────────
 *
 * ⚠ The most important field on this form. A metric marked as counting people
 * goes through disclosure control before it is ever published: a figure below
 * the minimum group size is WITHHELD, because this foundation's categories
 * include health, orphan status and widowhood, and "3 widows supported in
 * Bongo" identifies those three women to anybody who lives there.
 *
 * A metric counting things is never suppressed — one borehole is one borehole.
 * Getting this switch wrong in the safe direction costs a number on a web page;
 * getting it wrong the other way is a disclosure that cannot be taken back.
 *
 * ── Values are recorded per period, not overwritten ─────────────────────────
 *
 * "1,400 people served" is a number nobody can check. A row per period is a
 * series: it shows the work continuing, it survives somebody mistyping this
 * quarter, and it is what makes a chart possible later.
 */
class ImpactMetricForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The measure'))->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')
                        ->label(__('What is measured'))
                        ->required()
                        ->maxLength(191)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state)))
                        ->helperText(__('"People served", "Boreholes drilled", "Scholarships awarded".')),

                    TextInput::make('slug')
                        ->label(__('Address'))
                        ->required()
                        ->maxLength(191)
                        ->unique(ignoreRecord: true),
                ]),

                Textarea::make('description')
                    ->label(__('What it means'))
                    ->rows(2)
                    ->helperText(__('Shown under the number on the impact page. Say what counts and what does not — a figure nobody can interpret is a figure nobody believes.')),

                Grid::make(2)->schema([
                    Select::make('division_id')
                        ->label(__('Part of'))
                        ->options(fn (): array => Division::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),

                    Select::make('project_id')
                        ->label(__('For one project'))
                        ->options(fn (): array => Project::query()->orderBy('title')->pluck('title', 'id')->all())
                        ->searchable()
                        ->helperText(__('Optional. Leave empty for a measure that spans the whole foundation.')),
                ]),
            ]),

            Section::make(__('How it is counted'))->schema([
                Grid::make(3)->schema([
                    Select::make('value_type')
                        ->label(__('Kind of number'))
                        ->options([
                            ImpactMetric::TYPE_INTEGER => __('A whole number'),
                            ImpactMetric::TYPE_DECIMAL => __('A decimal'),
                            ImpactMetric::TYPE_MONEY => __('An amount of money'),
                            ImpactMetric::TYPE_PERCENTAGE => __('A percentage'),
                        ])
                        ->default(ImpactMetric::TYPE_INTEGER)
                        ->required(),

                    Select::make('aggregation')
                        ->label(__('Across periods'))
                        ->options([
                            ImpactMetric::AGGREGATION_SUM => __('Add them up'),
                            ImpactMetric::AGGREGATION_AVERAGE => __('Average them'),
                            ImpactMetric::AGGREGATION_LATEST => __('Use the most recent'),
                            ImpactMetric::AGGREGATION_MAX => __('Use the highest'),
                        ])
                        ->default(ImpactMetric::AGGREGATION_SUM)
                        ->required()
                        ->helperText(__('"Boreholes drilled" adds up. "Pupils enrolled" is the latest — adding it would count the same child every term.')),

                    TextInput::make('unit')
                        ->label(__('Unit'))
                        ->maxLength(32)
                        ->helperText(__('"boreholes", "pupils". Left off a money or percentage measure.')),
                ]),

                Toggle::make('counts_people')
                    ->label(__('This counts people'))
                    ->helperText(__(
                        'Switch this on for anything that counts individuals. A small figure is then '
                        .'WITHHELD from the public page rather than published — "3 widows supported '
                        .'in Bongo" identifies those three women to anybody who lives there. Getting '
                        .'this wrong in the cautious direction costs a number on a web page; getting '
                        .'it wrong the other way cannot be taken back.'
                    )),

                Grid::make(2)->schema([
                    TextInput::make('baseline_value')
                        ->label(__('Where we started'))
                        ->numeric()
                        ->helperText(__('Optional. What the figure was before the work began.')),

                    TextInput::make('target_value')
                        ->label(__('Where we are heading'))
                        ->numeric()
                        ->helperText(__('Optional. Used to show progress towards a goal.')),
                ]),
            ]),

            Section::make(__('The readings'))
                ->description(__('One row per period. A single overwritten total is a number nobody can check.'))
                ->schema([
                    Repeater::make('values')
                        ->label('')
                        ->relationship()
                        ->collapsible()
                        ->collapsed()
                        ->addActionLabel(__('Record a reading'))
                        ->itemLabel(fn (array $state): ?string => isset($state['period_start'])
                            ? $state['period_start'].' — '.($state['value'] ?? '')
                            : null)
                        ->schema([
                            Grid::make(3)->schema([
                                DatePicker::make('period_start')->label(__('From'))->required(),
                                DatePicker::make('period_end')->label(__('To'))->after('period_start'),
                                TextInput::make('value')->label(__('Figure'))->numeric()->required(),
                            ]),

                            Grid::make(2)->schema([
                                TextInput::make('source')
                                    ->label(__('Where this came from'))
                                    ->maxLength(191)
                                    ->helperText(__('A register, a survey, a partner report. An unsourced statistic on a fundraising site is a trust risk.')),

                                TextInput::make('notes')->label(__('Notes'))->maxLength(191),
                            ]),
                        ]),
                ]),

            Section::make(__('Publishing'))->schema([
                Grid::make(3)->schema([
                    Toggle::make('is_public')
                        ->label(__('Show on the impact page'))
                        ->helperText(__('Still subject to the people check above.')),

                    Toggle::make('is_featured')->label(__('Feature')),

                    TextInput::make('sort_order')->label(__('Order'))->numeric()->default(0),
                ]),
            ]),
        ]);
    }
}
