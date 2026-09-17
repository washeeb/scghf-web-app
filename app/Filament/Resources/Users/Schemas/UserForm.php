<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A staff account: who, how to reach them, what they may do.
 *
 * No password field. A new account gets a password-reset email and sets
 * its own; an administrator never knows a colleague's password, and
 * never has to email one. Two-factor is enrolled by the account holder on
 * first sign-in, because the panel requires it.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Who'))->columns(2)->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(191),
                TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(191)->unique(ignoreRecord: true)
                    ->helperText(__('The sign-in address, and where the password-reset email goes.')),
                TextInput::make('job_title')->label(__('Job title'))->maxLength(191),
                TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(32),
            ]),

            Section::make(__('Access'))->columns(2)->schema([
                Select::make('roles')
                    ->label(__('Roles'))
                    ->relationship('roles', 'name', fn ($query) => $query->where('name', '!=', 'Donor'))
                    ->multiple()
                    ->preload()
                    ->required()
                    ->helperText(__('Roles are bundles of permissions. Super Admin holds every permission, including the ones that delete.')),
                Toggle::make('is_active')->label(__('Active'))->default(true)
                    ->helperText(__('Off is a soft stop; Suspend (an action on the record) is the one that records why.')),
                TextEntry::make('two_factor')
                    ->label(__('Two-factor'))
                    ->visible(fn (?User $record): bool => $record !== null)
                    ->state(fn (User $record): string => $record->hasTwoFactorEnabled() ? __('Enrolled') : __('Not yet — they will be asked on first sign-in.')),
                TextEntry::make('last_sign_in')
                    ->label(__('Last signed in'))
                    ->visible(fn (?User $record): bool => $record !== null)
                    ->state(fn (User $record): string => $record->loginHistories()->where('outcome', 'success')->latest()->value('created_at')?->diffForHumans() ?? __('Never')),
            ]),
        ]);
    }
}
