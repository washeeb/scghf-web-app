<?php

declare(strict_types=1);

namespace App\Filament\Resources\VolunteerApplications\Schemas;

use App\Models\VolunteerApplication;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One application, read-only.
 *
 * ── The personal details are behind `volunteers.view_pii` ───────────────────
 *
 * Date of birth, home address, next of kin, disclosed convictions: somebody
 * allowed to see that applications exist and move them along is not thereby
 * allowed to read all of that. The permission has been seeded since Phase 3
 * and gated nothing; here it gates the section.
 *
 * ── The declaration is shown as evidence, not as a tick ─────────────────────
 *
 * The text the applicant agreed to, the time and the address it came from.
 * "They ticked a box" is not evidence of what they were asked; the text is.
 */
class VolunteerApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $mayViewPii = fn (): bool => auth()->user()?->can('volunteers.view_pii') ?? false;

        return $schema->components([
            Section::make(__('The application'))->columns(3)->schema([
                TextEntry::make('reference')->label(__('Reference'))->fontFamily('mono')->copyable(),
                TextEntry::make('opportunity.title')->label(__('Role'))->placeholder(__('General application')),
                TextEntry::make('submitted_at')->label(__('Applied'))->dateTime('j M Y, H:i')->placeholder(__('Not submitted')),
                TextEntry::make('full_name')->label(__('Name')),
                TextEntry::make('email')->label(__('Email'))->copyable(),
                TextEntry::make('phone')->label(__('Phone'))->placeholder('—')->copyable(),
                TextEntry::make('region')->label(__('Region'))->placeholder('—'),
                TextEntry::make('occupation')->label(__('Occupation'))->placeholder('—'),
                TextEntry::make('availability')->label(__('Availability'))->placeholder('—'),
            ]),

            Section::make(__('In their words'))->schema([
                TextEntry::make('motivation')->label(__('Why they want to volunteer'))->placeholder('—')->prose(),
                TextEntry::make('experience')->label(__('Relevant experience'))->placeholder('—')->prose(),
            ]),

            Section::make(__('Personal details'))
                ->description(__('Visible only with permission to see volunteer personal data.'))
                ->visible($mayViewPii)
                ->columns(2)
                ->schema([
                    TextEntry::make('date_of_birth')->label(__('Date of birth'))->date('j F Y')->placeholder('—'),
                    TextEntry::make('address')->label(__('Address'))->placeholder('—'),
                    TextEntry::make('next_of_kin_name')->label(__('Next of kin'))->placeholder('—'),
                    TextEntry::make('next_of_kin_phone')->label(__('Next of kin phone'))->placeholder('—'),
                    TextEntry::make('disclosed_convictions')
                        ->label(__('Disclosed convictions'))
                        ->placeholder(__('None disclosed'))
                        ->columnSpanFull()
                        ->prose(),
                ]),

            Section::make(__('The declaration'))->columns(3)->schema([
                TextEntry::make('declaration_agreed')
                    ->label(__('Agreed'))
                    ->state(fn (VolunteerApplication $record): string => $record->declaration_agreed ? __('Yes') : __('No')),
                TextEntry::make('declaration_at')->label(__('When'))->dateTime('j M Y, H:i')->placeholder('—'),
                TextEntry::make('declaration_ip')->label(__('From'))->fontFamily('mono')->placeholder('—'),
                TextEntry::make('declaration_text')->label(__('What they agreed to'))->columnSpanFull()->prose(),
            ]),

            Section::make(__('Decision'))
                ->visible(fn (VolunteerApplication $record): bool => $record->decided_at !== null)
                ->columns(3)
                ->schema([
                    TextEntry::make('status')->label(__('Outcome'))->badge(),
                    TextEntry::make('decided_at')->label(__('When'))->dateTime('j M Y, H:i'),
                    TextEntry::make('assessedBy.name')->label(__('By'))->placeholder('—'),
                    TextEntry::make('decline_reason')->label(__('Reason'))->placeholder('—')->columnSpanFull(),
                ]),

            Grid::make(1)->schema([
                TextEntry::make('assessor_notes')->label(__('Assessor notes'))->placeholder(__('None yet'))->prose(),
            ]),
        ]);
    }
}
