<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Support\AuditLogger;
use App\Support\Sessions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * What can be done to a staff account that is not editing a field.
 *
 * Every one of these is audited as a security event, and none can be done
 * to yourself — the account that could lock out the last administrator is
 * the last administrator's.
 */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function afterSave(): void
    {
        $user = $this->getRecord();

        app(AuditLogger::class)->record(
            'user.updated',
            sprintf('Edited staff account %s; roles now: %s.', $user->email, $user->roles()->pluck('name')->implode(', ')),
            subject: $user,
        );
    }

    protected function getHeaderActions(): array
    {
        $notSelf = fn (): bool => $this->getRecord()->getKey() !== auth()->id();

        return [
            Action::make('revokeSessions')
                ->label(__('Sign out everywhere'))
                ->icon('heroicon-o-arrow-right-start-on-rectangle')
                ->color('warning')
                ->visible($notSelf)
                ->requiresConfirmation()
                ->modalDescription(__('Ends every session this account has open, on every device, and invalidates any "keep me signed in" cookie. They can sign in again with their password and second factor.'))
                ->action(function (): void {
                    $ended = Sessions::revokeAll($this->getRecord());

                    app(AuditLogger::class)->record('auth.sessions_revoked', sprintf('An administrator ended %d session(s) for %s.', $ended, $this->getRecord()->email), subject: $this->getRecord());

                    Notification::make()->title(trans_choice('{0}No sessions were open.|{1}One session ended.|[2,*]:count sessions ended.', $ended))->success()->send();
                }),

            Action::make('resetTwoFactor')
                ->label(__('Reset two-factor'))
                ->icon('heroicon-o-device-phone-mobile')
                ->color('danger')
                ->visible(fn (): bool => $notSelf() && $this->getRecord()->hasTwoFactorEnabled())
                ->modalDescription(__('For somebody who has lost their phone AND their recovery codes. Removes their second factor and ends their sessions; they enrol again at next sign-in. Confirm who you are talking to first — this is the request an attacker makes.'))
                ->schema([
                    Textarea::make('reason')->label(__('How you verified it was them'))->required()->rows(3),
                ])
                ->action(function (array $data): void {
                    $user = $this->getRecord();
                    $user->disableTwoFactor();
                    Sessions::revokeAll($user);

                    app(AuditLogger::class)->record('auth.two_factor_reset', sprintf('An administrator reset two-factor for %s. Verification: %s', $user->email, $data['reason']), subject: $user, context: ['reason' => $data['reason']]);

                    Notification::make()->title(__('Two-factor removed. They enrol again at next sign-in.'))->warning()->send();
                    $this->refreshFormData(['two_factor']);
                }),

            Action::make('suspend')
                ->label(__('Suspend'))
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn (): bool => $notSelf() && ! $this->getRecord()->isSuspended())
                ->modalDescription(__('Ends their sessions now and refuses sign-in until reinstated. The reason is recorded.'))
                ->schema([
                    Textarea::make('reason')->label(__('Reason'))->required()->rows(3),
                ])
                ->action(function (array $data): void {
                    $user = $this->getRecord();
                    $user->suspend((string) $data['reason']);
                    Sessions::revokeAll($user);

                    app(AuditLogger::class)->record('user.suspended', sprintf('Suspended %s: %s', $user->email, $data['reason']), subject: $user);

                    Notification::make()->title(__('Suspended and signed out.'))->warning()->send();
                    $this->refreshFormData(['is_active']);
                }),

            Action::make('reinstate')
                ->label(__('Reinstate'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->isSuspended())
                ->requiresConfirmation()
                ->action(function (): void {
                    $user = $this->getRecord();
                    $user->reinstate();

                    app(AuditLogger::class)->record('user.reinstated', sprintf('Reinstated %s.', $user->email), subject: $user);

                    Notification::make()->title(__('Reinstated.'))->success()->send();
                    $this->refreshFormData(['is_active']);
                }),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('Saved.');
    }
}
