<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactMessages\Schemas;

use App\Models\ContactMessage;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One enquiry.
 *
 * ── The message itself is read-only ─────────────────────────────────────────
 *
 * What somebody sent is a record of what they sent. An inbox that lets staff
 * edit the enquiry is one where "that is not what I asked" has no answer — and
 * for a safeguarding report, that record may be evidence.
 *
 * Everything editable here is the foundation's own handling: who owns it, what
 * state it is in, and the internal notes.
 *
 * ── Consent is displayed, not editable ──────────────────────────────────────
 *
 * `consent_given` and `consent_text` are the Act 843 evidence — the exact
 * wording the sender agreed to, snapshotted at the time. Editing either after
 * the fact would destroy the only thing that makes it evidence.
 */
class ContactMessageForm
{
    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            'new' => __('New'),
            'assigned' => __('Assigned'),
            'replied' => __('Replied'),
            'resolved' => __('Resolved'),
            'spam' => __('Spam'),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('What they sent'))
                ->description(fn (?ContactMessage $record): ?string => $record?->reference)
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('name')->label(__('From')),
                        TextEntry::make('email')->label(__('Email'))->copyable(),
                        TextEntry::make('phone')->label(__('Phone'))->placeholder('—')->copyable(),
                    ]),

                    Grid::make(2)->schema([
                        TextEntry::make('department.name')->label(__('Department'))->placeholder(__('Not chosen')),
                        TextEntry::make('created_at')->label(__('Received'))->dateTime(),
                    ]),

                    TextEntry::make('subject')->label(__('Subject'))->placeholder('—'),

                    TextEntry::make('message')
                        ->label(__('Message'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('Handling'))->schema([
                Grid::make(2)->schema([
                    Select::make('status')
                        ->label(__('Status'))
                        ->options(static::statuses())
                        ->required(),

                    /*
                     * Only staff. A donor account can sign in, and offering the
                     * whole users table here would let somebody assign a
                     * safeguarding enquiry to a member of the public.
                     */
                    Select::make('assigned_to')
                        ->label(__('Owned by'))
                        ->options(fn (): array => User::query()
                            ->staff()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->placeholder(__('Nobody yet'))
                        ->helperText(__('An enquiry nobody owns is one everybody assumes somebody else answered.')),
                ]),

                Textarea::make('internal_notes')
                    ->label(__('Internal notes'))
                    ->rows(4)
                    ->helperText(__('Not sent to them. Visible to anybody who can open this inbox.')),
            ]),

            Section::make(__('Record'))
                ->collapsed()
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('consent_given')
                            ->label(__('Consent given'))
                            ->badge()
                            ->state(fn (ContactMessage $record): string => $record->consent_given ? __('Yes') : __('No'))
                            ->color(fn (ContactMessage $record): string => $record->consent_given ? 'success' : 'danger'),

                        TextEntry::make('replied_at')
                            ->label(__('Replied'))
                            ->dateTime()
                            ->placeholder(__('Not yet')),
                    ]),

                    TextEntry::make('consent_text')
                        ->label(__('What they agreed to'))
                        ->placeholder('—')
                        ->columnSpanFull(),

                    Grid::make(2)->schema([
                        TextEntry::make('source_url')->label(__('Sent from'))->placeholder('—'),
                        TextEntry::make('ip_address')->label(__('IP address'))->placeholder('—'),
                    ]),
                ]),
        ]);
    }
}
