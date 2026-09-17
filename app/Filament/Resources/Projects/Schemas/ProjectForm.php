<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\ProjectStatus;
use App\Filament\Support\MediaPicker;
use App\Filament\Support\SeoFields;
use App\Models\Project;
use App\ValueObjects\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * A project.
 *
 * ── Locations are rows, not a text field ────────────────────────────────────
 *
 * `project_locations` carries region, district, community and coordinates, and
 * it is what the public filter is built on. A single "Location" text box would
 * make "Upper East" and "Upper East Region" two different regions in the
 * filter, and nobody would ever find out why one of them returns nothing.
 *
 * ── Milestones have a public switch ─────────────────────────────────────────
 *
 * `is_public` on each. An internal target the team missed is not a promise the
 * foundation made to anybody, and a transparency page that publishes every slip
 * is one a team stops recording honestly.
 */
class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([

                Tabs\Tab::make(__('The project'))->schema([
                    TextInput::make('title')
                        ->label(__('Title'))
                        ->required()
                        ->maxLength(191)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, callable $set, ?Project $record): void {
                            // A published project keeps its address. Renaming it
                            // breaks every link a funder or a newsletter used.
                            if ($record?->exists && $record->is_published) {
                                return;
                            }

                            $set('slug', Str::slug((string) $state));
                        }),

                    TextInput::make('slug')
                        ->label(__('Address'))
                        ->required()
                        ->maxLength(191)
                        ->unique(ignoreRecord: true),

                    Textarea::make('summary')
                        ->label(__('Summary'))
                        ->rows(2)
                        ->maxLength(500)
                        ->helperText(__('One or two lines, shown in the projects list and when the link is shared.')),

                    RichEditor::make('description')
                        ->label(__('The full story'))
                        ->columnSpanFull(),

                    Select::make('focusAreas')
                        ->label(__('Areas of work'))
                        ->relationship('focusAreas', 'name')
                        ->multiple()
                        ->preload()
                        ->helperText(__('Used to group this on "What we do" and to filter the projects list.')),
                ]),

                Tabs\Tab::make(__('Where and when'))->schema([
                    Grid::make(3)->schema([
                        Select::make('status')
                            ->label(__('Status'))
                            ->options(ProjectStatus::options())
                            ->default(ProjectStatus::Planned->value)
                            ->required(),

                        DatePicker::make('starts_on')->label(__('Starts')),
                        DatePicker::make('ends_on')->label(__('Ends'))->after('starts_on'),
                    ]),

                    TextInput::make('budget')
                        ->label(__('Budget'))
                        ->numeric()
                        ->minValue(0)
                        ->prefix(__('pesewas'))
                        ->live(onBlur: true)
                        /*
                         * ⚠ Same conversion as the appeal's goal. `MoneyCast`
                         * takes a Money or an integer of minor units and throws
                         * on a string — which is what a form field submits.
                         */
                        ->formatStateUsing(fn (?Money $state): ?int => $state?->toMinor())
                        ->dehydrateStateUsing(fn (?string $state): ?int => blank($state) ? null : (int) $state)
                        ->helperText(fn ($state): string => __('= :amount', [
                            'amount' => 'GH₵ '.number_format(((int) $state) / 100, 2),
                        ])),

                    Repeater::make('locations')
                        ->label(__('Where it happens'))
                        ->relationship()
                        ->orderColumn('sort_order')
                        ->collapsible()
                        ->collapsed()
                        ->addActionLabel(__('Add a location'))
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? $state['community'] ?? null)
                        ->schema([
                            Grid::make(2)->schema([
                                TextInput::make('name')
                                    ->label(__('Name'))
                                    ->required()
                                    ->maxLength(191)
                                    // NOT NULL on the table. Offering it as
                                    // optional produces a save that fails at
                                    // the database with no field to point at.
                                    ->helperText(__('What you call this site — "Bongo District" or "Zorko community".')),
                                TextInput::make('region')
                                    ->label(__('Region'))
                                    ->maxLength(191)
                                    ->helperText(__('Exactly as you would write it elsewhere — this is what the public filter groups on.')),
                            ]),

                            Grid::make(2)->schema([
                                TextInput::make('district')->label(__('District'))->maxLength(191),
                                TextInput::make('community')->label(__('Community'))->maxLength(191),
                            ]),

                            Toggle::make('is_primary')
                                ->label(__('Main location'))
                                ->helperText(__('Shown beside the project in lists.')),
                        ]),
                ]),

                Tabs\Tab::make(__('Milestones'))->schema([
                    Repeater::make('milestones')
                        ->label('')
                        ->relationship()
                        ->orderColumn('sort_order')
                        ->collapsible()
                        ->collapsed()
                        ->addActionLabel(__('Add a milestone'))
                        ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                        ->schema([
                            TextInput::make('title')->label(__('Milestone'))->required()->maxLength(191),

                            Textarea::make('description')->label(__('Detail'))->rows(2),

                            Grid::make(3)->schema([
                                DatePicker::make('due_on')->label(__('Due')),
                                DatePicker::make('achieved_on')->label(__('Achieved')),

                                Toggle::make('is_public')
                                    ->label(__('Show publicly'))
                                    ->helperText(__('An internal target is not a promise made to the public.')),
                            ]),
                        ]),
                ]),

                Tabs\Tab::make(__('Publishing'))->schema([
                    Grid::make(2)->schema([
                        Toggle::make('is_published')->label(__('Show on the site')),

                        DateTimePicker::make('published_at')
                            ->label(__('Publish at'))
                            ->seconds(false),
                    ]),

                    Toggle::make('is_featured')->label(__('Feature')),

                    MediaPicker::image('featured_image_id')->label(__('Image')),
                ]),
                SeoFields::tab(),
            ]),
        ]);
    }
}
