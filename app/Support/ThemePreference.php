<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;

/**
 * Which theme this request renders in, and the script that stops it flashing.
 *
 * ── Three states, not two ───────────────────────────────────────────────────
 *
 * `light`, `dark`, `system`. "System" is a real choice and not the same as
 * "nothing recorded": somebody who explicitly picked system wants the page to
 * follow their OS when it flips at sunset, and somebody who picked dark does
 * not want it flipping back at sunrise. Collapsing the two loses that.
 *
 * ── Why a cookie as well as localStorage ────────────────────────────────────
 *
 * This is the part that actually prevents the flash, and it is worth being
 * precise about.
 *
 * `localStorage` is only readable by JavaScript, which by definition runs after
 * the HTML has arrived. So with localStorage alone the server always sends the
 * default theme, the browser paints it, and the script then corrects it — and
 * that correction IS the flash. Moving the script earlier reduces it to a
 * flicker; it cannot remove it.
 *
 * The cookie is sent with the request, so the server can put `class="dark"` on
 * `<html>` in the bytes it emits. Nothing to correct, nothing to flash.
 *
 * Both are kept because they fail differently: a cookie is cleared by privacy
 * settings that leave localStorage alone, and localStorage survives a
 * cookie-consent sweep. Whichever is present wins; the script keeps them in
 * step.
 *
 * ── Not a consent matter ────────────────────────────────────────────────────
 *
 * The cookie holds one of three words about how somebody likes their screen. It
 * is strictly necessary for the appearance they asked for, carries no
 * identifier, and is not shared — so it sits outside the consent banner, which
 * is where a preference cookie belongs.
 */
class ThemePreference
{
    public const COOKIE = 'scghf_theme';

    public const LIGHT = 'light';

    public const DARK = 'dark';

    public const SYSTEM = 'system';

    /** A year. A preference is not a session. */
    public const COOKIE_MINUTES = 525_600;

    /**
     * The class to put on `<html>` for this request.
     *
     * Returns `dark` or an empty string. When the preference is `system` the
     * server cannot know the answer — the OS setting is not sent with the
     * request — so it emits nothing and lets the inline script decide before
     * first paint. That is the one case where the script does the work, and it
     * runs before any pixels, so there is still nothing to flash.
     */
    public function htmlClass(Request $request): string
    {
        return $this->resolve($request) === self::DARK ? 'dark' : '';
    }

    /**
     * The stored preference: `light`, `dark` or `system`.
     *
     * Falls back to the CMS default (`site.default_theme`) so the foundation
     * can decide what a first-time visitor sees, then to `system`, which
     * respects whatever the visitor's device already asked for.
     */
    public function resolve(Request $request): string
    {
        $cookie = (string) $request->cookie(self::COOKIE, '');

        if ($this->isValid($cookie)) {
            return $cookie;
        }

        $default = (string) (setting('site.default_theme') ?? self::SYSTEM);

        return $this->isValid($default) ? $default : self::SYSTEM;
    }

    public function isValid(string $theme): bool
    {
        return in_array($theme, [self::LIGHT, self::DARK, self::SYSTEM], true);
    }

    /**
     * The script that runs before the first paint.
     *
     * ⚠ It must be INLINE and it must NOT be deferred.
     *
     * A `<script src>` — even one marked `defer` — is fetched and executed
     * after the document is parsed, by which point the browser has already
     * painted. Inlining is not a shortcut here; it is the entire mechanism.
     * It is a few hundred bytes and blocks for well under a millisecond.
     *
     * What it does, in order:
     *   1. read localStorage, falling back to the cookie the server sent
     *   2. resolve `system` against `prefers-color-scheme`
     *   3. add or remove `.dark` on `<html>` BEFORE anything renders
     *   4. keep the two stores in step, so a cleared cookie is repopulated
     *
     * Wrapped in try/catch because localStorage throws outright in a Safari
     * private window rather than returning null — and a theme script that
     * throws takes every later inline script on the page with it.
     */
    public function inlineScript(): HtmlString
    {
        $cookie = self::COOKIE;
        $days = (int) round(self::COOKIE_MINUTES / 1440);

        $js = <<<JS
        (function(){
          try{
            var k='{$cookie}';
            var stored=null;
            try{stored=localStorage.getItem(k);}catch(e){}
            if(!stored){
              var m=document.cookie.match(/(?:^|;\\s*){$cookie}=([^;]*)/);
              if(m){stored=decodeURIComponent(m[1]);}
            }
            if(stored!=='light'&&stored!=='dark'&&stored!=='system'){stored='system';}
            var dark=stored==='dark'||(stored==='system'&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark',dark);
            document.documentElement.dataset.theme=stored;
            try{localStorage.setItem(k,stored);}catch(e){}
            document.cookie=k+'='+stored+';path=/;max-age='+({$days}*86400)+';SameSite=Lax';
          }catch(e){}
        })();
        JS;

        // Whitespace collapsed: this ships on every page render, and the
        // savings are not nothing on a slow connection.
        return new HtmlString((string) preg_replace('/\n\s*/', '', $js));
    }
}
