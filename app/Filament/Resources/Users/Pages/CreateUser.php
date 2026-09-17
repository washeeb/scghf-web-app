<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserType;
use App\Filament\Resources\Users\UserResource;
use App\Support\AuditLogger;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * A new staff account gets a random password nobody knows and a reset
 * email to set their own. Two-factor enrolment follows on first sign-in.
 */
class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = UserType::Staff;
        $data['password'] = Str::random(64);
        $data['is_active'] = $data['is_active'] ?? true;

        return $data;
    }

    protected function afterCreate(): void
    {
        $user = $this->getRecord();

        Password::broker()->sendResetLink(['email' => $user->email]);

        app(AuditLogger::class)->record(
            'user.created',
            sprintf('Created staff account %s (%s) with roles: %s.', $user->name, $user->email, $user->roles->pluck('name')->implode(', ')),
            subject: $user,
        );
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Account created. They have been emailed a link to set their password.');
    }
}
