<?php

declare(strict_types=1);

namespace App\Filament\Resources\VolunteerOpportunities\Schemas;

use App\Filament\Support\SeoFields;
use App\Models\Division;
use App\Models\Project;
use App\Models\ShippingZone;
use App\Models\User;
use App\Models\VolunteerOpportunity;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * A volunteer role.
 *
 * ── The contact flag is on by default, and the form says what it costs ──────
 *
 * A role marked as involving contact with children or vulnerable adults needs
 * the full check set — police clearance, two references taken up, an
 * interview — before anybody can be approved into it. Switching it off is a
 * deliberate statement that the role has no such contact, and the helper text
 * says so in those words, because the person ticking it is the person who
 * will be asked why.
 */
class VolunteerOpportunityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('title')
                    ->label(__('Role'))
                    ->required()
                    ->maxLength(191)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),

                TextInput::make('slug')
                    ->label(__('Address'))
                    ->required()
                    ->maxLength(191)
                    ->unique(ignoreRecord: true),
            ]),

            Textarea::make('summary')
                ->label(__('One-line summary'))
                ->rows(2)
                ->maxLength(300),

            RichEditor::make('description')
                ->label(__('What the role involves'))
                ->toolbarButtons(['bold', 'italic', 'link', 'bulletList', 'orderedList', 'h3', 'undo', 'redo']),

            RichEditor::make('requirements')
                ->label(__('What we are looking for'))
                ->toolbarButtons(['bold', 'italic', 'link', 'bulletList', 'orderedList', 'undo', 'redo'])
                ->helperText(__('Skills, experience, languages. Be specific — "comfortable talking to older people in Frafra" is a requirement somebody can measure themselves against.')),

            TagsInput::make('skills_needed')
                ->label(__('Skills that help'))
                ->placeholder(__('Type one and press Enter'))
                ->helperText(__('Short tags shown on the page and used to match applicants — "Twi", "first aid", "driving licence".')),

            Section::make(__('Safeguarding'))->schema([
                Toggle::make('involves_vulnerable_contact')
                    ->label(__('This role involves contact with children or vulnerable adults'))
                    ->default(true)
                    ->helperText(__(
                        'On by default. Anybody approved into a role with contact must first have a police '
                        .'clearance, two references taken up and an interview recorded. Switch it off only '
                        .'for a role with genuinely no such contact — a driver, somebody folding leaflets — '
                        .'and expect to be asked why.'
                    )),

                TextEntry::make('checks')
                    ->label(__('Checks before approval'))
                    ->visible(fn (?VolunteerOpportunity $record): bool => $record !== null)
                    ->state(fn (VolunteerOpportunity $record): string => implode(' · ', $record->requiredCheckLabels())),
            ]),

            Section::make(__('Where and when'))->schema([
                Grid::make(3)->schema([
                    Select::make('placement_type')
                        ->label(__('Kind'))
                        ->options([
                            VolunteerOpportunity::PLACEMENT_FIELD => __('In the field'),
                            VolunteerOpportunity::PLACEMENT_OFFICE => __('In the office'),
                            VolunteerOpportunity::PLACEMENT_EVENTS => __('At events'),
                            VolunteerOpportunity::PLACEMENT_REMOTE => __('Remote'),
                        ])
                        ->default(VolunteerOpportunity::PLACEMENT_FIELD)
                        ->required(),

                    TextInput::make('location')->label(__('Where'))->maxLength(191),

                    Select::make('region')
                        ->label(__('Region'))
                        ->options(array_combine(ShippingZone::REGIONS, ShippingZone::REGIONS))
                        ->searchable(),
                ]),

                Grid::make(3)->schema([
                    TextInput::make('time_commitment')
                        ->label(__('Time commitment'))
                        ->maxLength(191)
                        ->helperText(__('"Two Saturdays a month", "one afternoon a week for a term".')),
                    DatePicker::make('starts_on')->label(__('Starts')),
                    DatePicker::make('closes_on')->label(__('Applications close'))->helperText(__('Empty for open-ended.')),
                ]),

                Grid::make(3)->schema([
                    TextInput::make('positions_available')
                        ->label(__('Places'))
                        ->numeric()
                        ->minValue(1)
                        ->helperText(__('Empty for no limit. The role closes to new applications when filled.')),

                    Select::make('division_id')
                        ->label(__('Part of'))
                        ->options(fn (): array => Division::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),

                    Select::make('project_id')
                        ->label(__('Project'))
                        ->options(fn (): array => Project::query()->orderBy('title')->pluck('title', 'id')->all())
                        ->searchable(),
                ]),

                Select::make('contact_user_id')
                    ->label(__('Who applicants are referred to'))
                    ->options(fn (): array => User::query()->staff()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
            ]),

            Grid::make(2)->schema([
                Toggle::make('is_published')->label(__('Show on the site')),
                DateTimePicker::make('published_at')->label(__('From'))->seconds(false)->helperText(__('Leave empty to publish immediately.')),
            ]),

            SeoFields::section(),
        ]);
    }
}
