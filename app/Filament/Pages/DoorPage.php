<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\EventRegistration;
use App\Models\IssuedTicket;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use RuntimeException;
use UnitEnum;

/**
 * The door.
 *
 * One box, for a code. A ticket's QR opens this page with the code in the
 * URL, so a steward's phone camera is the scanner; a code read out is
 * typed in the same box; a free event's registration reference works too.
 * Then one button. Nothing else, because the queue is behind the person
 * holding the phone.
 *
 * `events.view_registrations` — the same permission as the door list.
 */
class DoorPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'door';

    protected string $view = 'filament.pages.door';

    public string $code = '';

    public ?IssuedTicket $ticket = null;

    public ?EventRegistration $registration = null;

    public ?string $problem = null;

    public static function getNavigationLabel(): string
    {
        return __('The door');
    }

    public function getTitle(): string
    {
        return __('The door');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('events.view_registrations') ?? false;
    }

    /** `/door` from the menu; `/door/{code}` from a ticket's QR. Same page. */
    public static function getRoutePath(Panel $panel): string
    {
        return '/door/{code?}';
    }

    public function mount(?string $code = null): void
    {
        if (filled($code)) {
            $this->code = (string) $code;
            $this->lookUp();
        }
    }

    public function lookUp(): void
    {
        $this->ticket = null;
        $this->registration = null;
        $this->problem = null;

        $code = strtoupper(trim($this->code));

        if ($code === '') {
            return;
        }

        $this->ticket = IssuedTicket::query()->with(['event', 'ticketType', 'registration'])->where('code', $code)->first();

        if ($this->ticket === null) {
            $this->registration = EventRegistration::query()->with('event')->where('reference', $code)->first();
        }

        if ($this->ticket === null && $this->registration === null) {
            $this->problem = __('Nothing matches :code. Check the letters — there is no O or I in a ticket code.', ['code' => $code]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('admit')
                ->label(__('Admit'))
                ->icon('heroicon-o-check')
                ->color('success')
                ->size('xl')
                ->visible(fn (): bool => ($this->ticket?->isValid() ?? false)
                    || ($this->registration !== null && $this->registration->status === EventRegistration::STATUS_REGISTERED))
                ->action(function (): void {
                    try {
                        if ($this->ticket !== null) {
                            $this->ticket->checkIn(auth()->user());
                            $this->ticket->registration?->checkIn();
                        } else {
                            $this->registration?->checkIn();
                        }
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('In.'))->success()->send();
                    $this->lookUp();
                }),
        ];
    }
}
