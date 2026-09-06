<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\RecordAdminActivity;
use App\Models\ThemeSetting;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Throwable;

/**
 * The admin panel the foundation's staff use to run the site.
 *
 * ── The path is configurable, and that is not security theatre ──────────────
 *
 * `/admin` is the first path a scanner tries and `/administrator` is the
 * second. Moving it protects nothing on its own — authentication, staff-only
 * access and mandatory 2FA do that — but it takes this site out of the
 * automated sweeps that go looking for a login form to spray credentials at,
 * and those sweeps are most of the traffic a small site's login page ever sees.
 *
 * ── Two-factor is required, not offered ─────────────────────────────────────
 *
 * An administrator here can read beneficiary case files, export donor records
 * and approve refunds. A password is the credential most likely to have been
 * reused somewhere already breached, and a stolen one on this panel is the
 * worst day this foundation has.
 *
 * Filament v5 ships TOTP, so this needs no new dependency — which matters on a
 * host where "add a package" means a deploy and a prayer.
 *
 * ── Nothing here is hardcoded content ───────────────────────────────────────
 *
 * The panel's name and colours are read from the settings and theme layers at
 * boot, per CLAUDE.md's CMS rule. Renaming the foundation, or changing its
 * brand colour, is an edit in the admin panel — not a deploy.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path((string) config('admin.path', 'admin'))

            ->login()
            ->passwordReset()
            ->emailVerification()
            ->profile(isSimple: false)

            /*
             * TOTP, and required.
             *
             * `isRequired` reads the config rather than being hardcoded true so
             * that a locked-out administrator can be recovered by somebody with
             * server access — but the default is on, and config/admin.php says
             * plainly what switching it off actually removes.
             *
             * Recovery codes are enabled because the alternative to a recovery
             * code is a support conversation that ends in somebody disabling
             * 2FA over the phone, which is the whole control gone.
             */
            ->multiFactorAuthentication(
                AppAuthentication::make()
                    ->recoverable()
                    ->regenerableRecoveryCodes()
                    ->brandName($this->brandName()),
                isRequired: (bool) config('admin.require_two_factor', true),
            )

            /*
             * A CLOSURE, not a string, and the distinction matters.
             *
             * Passing the resolved value would freeze the foundation's name at
             * panel-registration time — which happens once, at boot. Renaming
             * the foundation in the settings screen would then do nothing until
             * somebody redeployed, quietly making CLAUDE.md's CMS rule false
             * for the one piece of content on every admin page.
             *
             * Resolved per request instead. Settings are cached in memory for
             * the request anyway, so this costs nothing.
             */
            ->brandName(fn (): string => $this->brandName())

            /*
             * Colours ARE resolved at boot, and that is deliberate rather than
             * an oversight: Filament compiles them into a CSS custom-property
             * palette, so they cannot vary per request without regenerating
             * that on every page load. The brand palette changes about as often
             * as the logo does, and a `filament:optimize-clear` after editing it
             * is a fair price for not paying that cost on every request.
             */
            ->colors($this->colours())

            /*
             * The dashboard, registered explicitly.
             *
             * Without this line the panel has no dashboard at all and
             * `/scghf-office` redirects to whichever resource happens to be
             * first — so every widget in `app/Filament/Widgets` is discovered,
             * registered, and rendered nowhere. Which was the case until
             * Phase 5.
             */
            ->pages([
                Dashboard::class,
            ])

            /*
             * The order the sidebar groups appear in, and it is the order
             * somebody works rather than alphabetical.
             *
             * Website first because that is what the foundation edits daily.
             * System last because it is where somebody goes when something has
             * gone wrong, which is rarely, and it should not sit above the work.
             */
            ->navigationGroups([
                'Website',
                'Programmes',
                'Content',
                'Inbox',
                'Library',
                'System',
            ])

            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                ConvertEmptyStringsToNull::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                /*
                 * Invalidates the session everywhere when the password changes.
                 * Without it, resetting a password after a suspected compromise
                 * leaves the attacker's existing session logged in — which is
                 * exactly the session the reset was meant to end.
                 */
                AuthenticateSession::class,
                RecordAdminActivity::class,
            ]);
    }

    /**
     * The foundation's short name, from the CMS.
     *
     * Falls back to the app name if the settings table is unreadable — during
     * a migration, or before the seeder has run. A panel that will not boot
     * because a setting is missing is worse than one showing a placeholder.
     */
    private function brandName(): string
    {
        try {
            $key = (string) config('admin.branding.name_setting', 'general.short_name');

            return (string) (setting($key) ?: config('app.name'));
        } catch (Throwable) {
            return (string) config('app.name');
        }
    }

    /**
     * Panel colours, from the theme tokens the trustees approved.
     *
     * Read from `theme_settings` rather than written here, because the brand
     * palette is CMS content and was sampled from the foundation's own logo
     * pack — inventing a hex value in a provider is exactly what CLAUDE.md
     * forbids.
     *
     * The light-theme value is used: Filament derives its own dark shades, and
     * the two-theme contrast work that matters is on the public site, which has
     * its own tokens.
     *
     * @return array<string, array<int, string>|string>
     */
    private function colours(): array
    {
        return [
            'primary' => $this->token('brand-primary', Color::Emerald),
            'danger' => Color::Rose,
            'gray' => Color::Zinc,
            'info' => Color::Blue,
            'success' => Color::Emerald,
            'warning' => Color::Amber,
        ];
    }

    /**
     * One theme token, or a sensible default.
     *
     * Wrapped because this runs at panel-registration time, which happens
     * during `migrate:fresh` and before the table exists. Throwing here would
     * make the database unmigratable — a bootstrapping deadlock that is
     * tedious to diagnose and trivial to avoid.
     *
     * @param  array<int, string>|string  $fallback
     * @return array<int, string>|string
     */
    private function token(string $token, array|string $fallback): array|string
    {
        try {
            $value = ThemeSetting::query()
                ->where('theme', 'light')
                ->where('token', $token)
                ->value('value');

            return is_string($value) && $value !== '' ? Color::hex($value) : $fallback;
        } catch (Throwable) {
            return $fallback;
        }
    }
}
