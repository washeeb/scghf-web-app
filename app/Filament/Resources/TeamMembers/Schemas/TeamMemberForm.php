<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamMembers\Schemas;

use App\Filament\Support\MediaPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * A trustee, staff member or volunteer on the public page.
 *
 * ── The public email is not their email ─────────────────────────────────────
 *
 * `public_email` is separate from the linked user account's address, and
 * deliberately so. Publishing a trustee's personal mobile or private email on a
 * page that a scraper reads within the hour is a real risk to a real person,
 * and the commonest way it happens is a form that helpfully pre-fills "their"
 * contact details from the account they sign in with.
 *
 * ── Linking to a user account is optional in both directions ────────────────
 *
 * A trustee is not necessarily a system user, and a system user is not
 * necessarily on the public page. The bookkeeper has an account and no entry
 * here; the board chair has an entry here and no account.
 */
class TeamMemberForm
{
    /** @return array<string, string> */
    public static function memberTypes(): array
    {
        return [
            'trustee' => __('Trustee / board'),
            'leadership' => __('Leadership'),
            'staff' => __('Staff'),
            'volunteer' => __('Volunteer'),
            'advisor' => __('Advisor'),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The person'))->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
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

                Grid::make(2)->schema([
                    TextInput::make('role_title')
                        ->label(__('Role'))
                        ->required()
                        ->maxLength(191)
                        ->helperText(__('As it should read on the page — "Founder & Executive Director".')),

                    Select::make('member_type')
                        ->label(__('Group'))
                        ->options(static::memberTypes())
                        ->default('staff')
                        ->required()
                        ->live(),
                ]),

                Textarea::make('bio')
                    ->label(__('Biography'))
                    ->rows(4),

                Select::make('team_department_id')
                    ->label(__('Department'))
                    ->relationship('department', 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        TextInput::make('name')->label(__('Name'))->required()->maxLength(191),
                        TextInput::make('slug')->label(__('Address'))->required()->maxLength(191),
                    ]),
            ]),

            Section::make(__('Photograph'))->schema([
                MediaPicker::image('photo_id')->label(__('Photograph')),
            ]),

            Section::make(__('Public contact'))
                ->description(__('Optional, and published. Never put a personal mobile or private address here.'))
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('public_email')
                            ->label(__('Public email'))
                            ->email()
                            ->maxLength(191)
                            ->helperText(__('A role address — chair@ or director@ — not the address they sign in with.')),

                        TextInput::make('linkedin_url')
                            ->label(__('LinkedIn'))
                            ->url()
                            ->maxLength(500),
                    ]),

                    Select::make('user_id')
                        ->label(__('Linked account'))
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->helperText(__('Optional. Links this entry to their staff login. It does not publish anything from that account.')),
                ]),

            Section::make(__('On the board, and on the page'))->schema([
                Grid::make(2)->schema([
                    Toggle::make('is_trustee')
                        ->label(__('Is a trustee'))
                        /*
                         * Separate from `member_type`, because it is a legal
                         * fact rather than a grouping. A registered non-profit
                         * has to be able to say who its trustees are, and
                         * "whoever is in the Trustees column of the website"
                         * is not an answer if somebody has been moved into
                         * Leadership for layout reasons.
                         */
                        ->helperText(__('A registered position, not a heading on the page. Keep it accurate even if they are shown under another group.')),

                    TextInput::make('sort_order')
                        ->label(__('Order'))
                        ->numeric()
                        ->default(0),
                ]),

                Grid::make(2)->schema([
                    DatePicker::make('joined_on')->label(__('Joined')),

                    DatePicker::make('left_on')
                        ->label(__('Left'))
                        ->helperText(__('Recording a leaving date keeps the history without keeping them on the page.')),
                ]),

                Toggle::make('is_published')
                    ->label(__('Show on the site'))
                    ->helperText(__('Off by default. A photograph and a biography go public the moment this is on.')),
            ]),
        ]);
    }
}
