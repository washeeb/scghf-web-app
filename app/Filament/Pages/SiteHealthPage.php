<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\HealthCheck;
use App\Support\SiteHealth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * "Is the site working?", answered on one screen.
 *
 * ── Written for the person who will actually open it ────────────────────────
 *
 * Not a developer. Somebody at the foundation who has noticed that receipts
 * stopped arriving, or who is checking before a campaign goes out. So every
 * failing check carries a sentence saying what to do — usually which cron line
 * is missing — rather than a status word they would have to take to somebody
 * else.
 *
 * ── Nothing is cached ───────────────────────────────────────────────────────
 *
 * A health page showing a cached green while the queue is down is worse than no
 * health page. The checks are a dozen cheap queries and one outbound request,
 * and this screen is opened rarely and deliberately.
 *
 * ── The badge only appears when something is wrong ──────────────────────────
 *
 * A permanent number in the sidebar is one people stop reading, which is
 * exactly what must not happen to this one.
 */
class SiteHealthPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.site-health';

    // Without this the URL is /site-health-page, from the class name.
    protected static ?string $slug = 'site-health';

    public static function getNavigationLabel(): string
    {
        return __('Site health');
    }

    public function getTitle(): string
    {
        return __('Site health');
    }

    public function getSubheading(): ?string
    {
        $problems = $this->problemCount();

        return $problems === 0
            ? __('Everything that can be checked automatically is fine.')
            : trans_choice(
                '{1}One thing needs attention.|[2,*]:count things need attention.',
                $problems,
                ['count' => $problems],
            );
    }

    /**
     * Only somebody who could act on the answer.
     *
     * This page names the payment mode, the backup state and the hosting
     * configuration. `settings.manage` is the closest existing permission to
     * "runs this installation" — there is no separate one, and inventing an
     * unused permission is the thing this project has a rule against.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $problems = app(SiteHealth::class)->problems()->count();

        return $problems > 0 ? (string) $problems : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return Collection<int, HealthCheck> */
    public function checks(): Collection
    {
        return app(SiteHealth::class)->checks();
    }

    public function problemCount(): int
    {
        return $this->checks()->filter(fn (HealthCheck $check): bool => $check->isProblem())->count();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(__('Check again'))
                ->icon('heroicon-o-arrow-path')
                // Nothing to do: re-rendering the page re-runs every check,
                // because none of them are cached.
                ->action(fn () => null),
        ];
    }
}
