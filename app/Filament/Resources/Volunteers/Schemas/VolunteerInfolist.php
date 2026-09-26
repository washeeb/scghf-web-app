<?php

declare(strict_types=1);

namespace App\Filament\Resources\Volunteers\Schemas;

use App\Models\Volunteer;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VolunteerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $mayViewPii = auth()->user()?->can('volunteers.view_pii') ?? false;

        return $schema->components([
            Section::make(__('Volunteer'))->columns(3)->schema([
                TextEntry::make('full_name')->label(__('Name')),
                TextEntry::make('role')->label(__('Role'))->placeholder('—'),
                TextEntry::make('division.name')->label(__('Division'))->placeholder('—'),
                TextEntry::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Volunteer::STATUS_ACTIVE => __('Active'),
                        Volunteer::STATUS_INACTIVE => __('Inactive'),
                        Volunteer::STATUS_SUSPENDED => __('Suspended'),
                        Volunteer::STATUS_LEFT => __('Left'),
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Volunteer::STATUS_ACTIVE => 'success',
                        Volunteer::STATUS_SUSPENDED => 'danger',
                        Volunteer::STATUS_INACTIVE => 'warning',
                        default => 'gray',
                    }),
                TextEntry::make('started_on')->label(__('Started'))->date('j M Y'),
                TextEntry::make('ended_on')->label(__('Left'))->date('j M Y')->placeholder(__('Still with us')),
                TextEntry::make('application.reference')->label(__('Application'))->fontFamily('mono')->placeholder('—')
                    ->url(fn (Volunteer $record): ?string => $record->application
                        ? route('filament.admin.resources.volunteer-applications.view', $record->application)
                        : null),
                TextEntry::make('leaving_reason')->label(__('Leaving reason'))->placeholder('—')->columnSpan(2),
            ]),

            Section::make(__('Recognition'))->columns(3)->schema([
                TextEntry::make('total_hours')
                    ->label(__('Verified hours'))
                    ->numeric()
                    ->helperText(__('Only hours a second person has verified count.')),
                TextEntry::make('unverified_hours')
                    ->label(__('Awaiting verification'))
                    ->state(fn (Volunteer $record): string => number_format(intdiv((int) $record->hours()->whereNull('verified_at')->sum('minutes'), 60), 0).' h'),
                TextEntry::make('shifts_completed')
                    ->label(__('Shifts completed'))
                    ->state(fn (Volunteer $record): int => $record->shifts()->where('status', 'completed')->count()),
            ]),

            Section::make(__('Safeguarding'))->columns(3)->schema([
                TextEntry::make('involves_vulnerable_contact')
                    ->label(__('Vulnerable contact'))
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Yes — clearance required') : __('No')),
                TextEntry::make('is_cleared')
                    ->label(__('Cleared'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state, Volunteer $record): string => match (true) {
                        ! $record->involves_vulnerable_contact => __('Not required'),
                        $record->clearanceHasLapsed() => __('LAPSED'),
                        $state => __('Yes'),
                        default => __('NO'),
                    })
                    ->color(fn (bool $state, Volunteer $record): string => match (true) {
                        ! $record->involves_vulnerable_contact => 'gray',
                        $record->clearanceHasLapsed(), ! $state => 'danger',
                        default => 'success',
                    }),
                TextEntry::make('clearance_expires_on')->label(__('Police clearance expires'))->date('j M Y')->placeholder('—'),
                TextEntry::make('concern_note')
                    ->label(fn (Volunteer $record): string => $record->hasOpenConcern() ? __('OPEN CONCERN') : __('Concern history'))
                    ->placeholder(__('None'))
                    ->columnSpanFull()
                    ->prose(),
            ]),

            Section::make(__('Contact'))
                ->description(__('Visible only with permission to see volunteer personal data.'))
                ->visible($mayViewPii)
                ->columns(2)
                ->schema([
                    TextEntry::make('email')->label(__('Email'))->placeholder('—')->copyable(),
                    TextEntry::make('phone')->label(__('Phone'))->placeholder('—')->copyable(),
                ]),
        ]);
    }
}
