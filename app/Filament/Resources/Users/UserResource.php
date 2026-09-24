<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
Staff accounts. The `users.*` permissions were seeded in Phase 3 and, until Phase 12, protected nothing: the only way to make an administrator was the terminal. Donor accounts are not listed here; they are on the Donors screen and manage themselves.
 */
class UserResource extends Resource
{
    /**
     * Whether a set of roles (ids or names, as the form gives them) is the
     * Courier role alone — the one role that makes a public account rather
     * than a member of staff. See CreateUser / EditUser.
     *
     * @param  array<int, int|string>  $roles
     */
    public static function courierOnly(array $roles): bool
    {
        if ($roles === []) {
            return false;
        }

        $names = Role::query()->whereIn('id', array_filter($roles, 'is_numeric'))->pluck('name')
            ->merge(array_filter($roles, 'is_string'))
            ->unique()
            ->values();

        return $names->count() === 1 && $names->first() === 'Courier';
    }

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Staff account';

    protected static ?string $pluralModelLabel = 'Staff accounts';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
