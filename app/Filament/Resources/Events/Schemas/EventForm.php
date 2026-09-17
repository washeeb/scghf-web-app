<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Schemas;

use App\Filament\Support\MediaPicker;
use App\Models\Cause;
use App\Models\Division;
use App\Models\Event;
use App\Models\Gallery;
use App\Models\Project;
use App\Models\ShippingZone;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * An event.
 *
 * ── Accessibility is described, not ticked ──────────────────────────────────
 *
 * "Step-free entrance, no accessible toilet" is useful to somebody deciding
 * whether to come; a checkbox is not. The field is free text and the helper
 * says what to put in it.
 *
 * ── Capacity counts people, not bookings ────────────────────────────────────
 *
 * A registration bringing three guests takes four places. The count shown here
 * is the headcount, and it is not editable: it is derived from the
 * registrations, and a number typed over it would be a room built for eighty
 * with a hundred and forty in it.
 *
 * ── Cancelling is an action, not a status change ────────────────────────────
 *
 * Setting the status to "cancelled" in this form is refused, because the
 * people who registered have to be told and the form has no way to do that.
 * The action on the list asks for the reason and sends it to them.
 */
class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([
                Tabs\Tab::make(__('The event'))->schema([
                    Grid::make(2)->schema([
                        TextInput::make('title')
                            ->label(__('Title'))
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

                    Grid::make(3)->schema([
                        Select::make('event_type')
                            ->label(__('Kind'))
                            ->options([
                                'outreach' => __('Outreach'),
                                'fundraiser' => __('Fundraiser'),
                                'service' => __('Service'),
                                'training' => __('Training'),
                                'community' => __('Community'),
                                'other' => __('Other'),
                            ])
                            ->default('outreach')
                            ->required(),

                        DateTimePicker::make('starts_at')->label(__('Starts'))->seconds(false)->required(),
                        DateTimePicker::make('ends_at')->label(__('Ends'))->seconds(false)->after('starts_at'),
                    ]),

                    Textarea::make('summary')
                        ->label(__('One-line summary'))
                        ->rows(2)
                        ->maxLength(300)
                        ->helperText(__('On the events list, and in search results.')),

                    RichEditor::make('description')
                        ->label(__('About it'))
                        ->toolbarButtons(['bold', 'italic', 'link', 'bulletList', 'orderedList', 'h3', 'undo', 'redo']),

                    MediaPicker::image('featured_image_id')->label(__('Image')),

                    Grid::make(3)->schema([
                        Select::make('division_id')
                            ->label(__('Part of'))
                            ->options(fn (): array => Division::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                        Select::make('project_id')
                            ->label(__('Project'))
                            ->options(fn (): array => Project::query()->orderBy('title')->pluck('title', 'id')->all())
                            ->searchable(),
                        Select::make('cause_id')
                            ->label(__('Appeal'))
                            ->options(fn (): array => Cause::query()->orderBy('title')->pluck('title', 'id')->all())
                            ->searchable()
                            ->helperText(__('A fundraiser links to the appeal it raises money for.')),
                    ]),
                ]),

                Tabs\Tab::make(__('Where'))->schema([
                    Toggle::make('is_online')
                        ->label(__('Online'))
                        ->live(),

                    TextInput::make('online_url')
                        ->label(__('Link to join'))
                        ->url()
                        ->maxLength(500)
                        ->visible(fn (Get $get): bool => (bool) $get('is_online'))
                        ->helperText(__('Sent to people who register; not shown on the public page.')),

                    Grid::make(2)->schema([
                        TextInput::make('venue_name')->label(__('Venue'))->maxLength(191),
                        TextInput::make('address')->label(__('Address'))->maxLength(255),
                        TextInput::make('area')->label(__('Town or area'))->maxLength(191),
                        Select::make('region')
                            ->label(__('Region'))
                            ->options(array_combine(ShippingZone::REGIONS, ShippingZone::REGIONS))
                            ->searchable(),
                    ])->hidden(fn (Get $get): bool => (bool) $get('is_online')),

                    Textarea::make('accessibility_notes')
                        ->label(__('Getting in and around'))
                        ->rows(3)
                        ->helperText(__('Describe it rather than tick it: "step-free entrance, no accessible toilet, parking on the street". This is what somebody deciding whether to come actually needs.')),
                ]),

                Tabs\Tab::make(__('Registration'))->schema([
                    Toggle::make('registration_required')
                        ->label(__('People register to come'))
                        ->live()
                        ->helperText(__('Off for something anybody can simply turn up to.')),

                    Grid::make(3)->schema([
                        TextInput::make('capacity')
                            ->label(__('Places'))
                            ->numeric()
                            ->minValue(1)
                            ->helperText(__('People, not bookings. Empty for no limit. Beyond it, people join a waiting list rather than being refused.')),

                        DateTimePicker::make('registration_opens_at')->label(__('Registration opens'))->seconds(false),
                        DateTimePicker::make('registration_closes_at')->label(__('Registration closes'))->seconds(false),
                    ])->visible(fn (Get $get): bool => (bool) $get('registration_required')),

                    TextEntry::make('headcount')
                        ->label(__('Registered so far'))
                        ->visible(fn (?Event $record): bool => $record !== null)
                        ->state(fn (Event $record): string => $record->capacity === null
                            ? trans_choice('{1}:count person|[2,*]:count people', $record->registered_count, ['count' => $record->registered_count])
                            : __(':count of :capacity places', ['count' => $record->registered_count, 'capacity' => $record->capacity]))
                        ->helperText(__('Counted from the registrations, guests included. Not editable here.')),

                    TextEntry::make('ticketing')
                        ->label(__('Tickets'))
                        ->state(__('Paid tickets are switched off (FEATURE_EVENT_TICKETING). Registration is free; a fundraiser links to its appeal instead.'))
                        ->hidden(fn (): bool => (bool) config('features.event_ticketing', false)),
                ]),

                Tabs\Tab::make(__('Publishing'))->schema([
                    Grid::make(2)->schema([
                        Select::make('status')
                            ->label(__('Status'))
                            ->options([
                                Event::STATUS_SCHEDULED => __('Going ahead'),
                                Event::STATUS_POSTPONED => __('Postponed'),
                                Event::STATUS_COMPLETED => __('Took place'),
                                Event::STATUS_CANCELLED => __('Cancelled'),
                            ])
                            // Cancelling happens through the action, which tells
                            // the people registered. Here it can only be seen.
                            ->disableOptionWhen(fn (string $value): bool => $value === Event::STATUS_CANCELLED)
                            ->disabled(fn (?Event $record): bool => $record?->status === Event::STATUS_CANCELLED)
                            ->dehydrated(fn (?Event $record): bool => $record?->status !== Event::STATUS_CANCELLED)
                            ->default(Event::STATUS_SCHEDULED)
                            ->required()
                            ->helperText(__('Cancelling is the action on the list, because everybody registered has to be told and told why.')),

                        TextEntry::make('cancellation_reason')
                            ->label(__('Cancelled because'))
                            ->visible(fn (?Event $record): bool => $record?->status === Event::STATUS_CANCELLED),
                    ]),

                    Grid::make(3)->schema([
                        Toggle::make('is_published')->label(__('Show on the site')),
                        DateTimePicker::make('published_at')->label(__('From'))->seconds(false)->helperText(__('Leave empty to publish immediately.')),
                        Toggle::make('is_featured')->label(__('Feature it')),
                    ]),
                ]),

                Tabs\Tab::make(__('Afterwards'))
                    ->schema([
                        RichEditor::make('outcomes')
                            ->label(__('What came of it'))
                            ->toolbarButtons(['bold', 'italic', 'link', 'bulletList', 'orderedList', 'undo', 'redo'])
                            ->helperText(__('Shown on the page once the event has taken place. What was raised, who came, what happens next.')),
                        Grid::make(2)->schema([
                            TextInput::make('attendance_count')
                                ->label(__('How many came'))
                                ->numeric()
                                ->minValue(0)
                                ->helperText(__('A headcount from the day. The registrations count is what was expected; this is what happened.')),
                            Select::make('gallery_id')
                                ->label(__('Photographs'))
                                ->options(fn (): array => Gallery::query()->orderByDesc('taken_on')->pluck('title', 'id')->all())
                                ->searchable()
                                ->nullable()
                                ->helperText(__('A gallery from the media library. Its consent flag governs whether faces may appear.')),
                        ]),
                    ]),
            ]),
        ]);
    }
}
