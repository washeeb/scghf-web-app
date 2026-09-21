/**
 * The public site's JavaScript, in full.
 *
 * ── There is deliberately very little of it ─────────────────────────────────
 *
 * This site is read on low-end Android phones over metered connections, and
 * every kilobyte here is downloaded, parsed and executed before anything a
 * donor came for. Both modules below are ENHANCEMENTS: the theme is applied by
 * an inline script in the head before this file exists, the navigation is
 * built from `<details>` elements that work with no script at all, and the
 * announcement bar's close button is CREATED by its module — so with this file
 * blocked there is no button rather than a button that does nothing.
 *
 * Laravel's `bootstrap.js` — which existed only to put axios on `window` — was
 * removed rather than kept "in case": nothing referenced it, and it was the
 * bulk of what every visitor was downloading. Livewire and Filament bring their
 * own request layer; if this site ever needs to make a request of its own,
 * `fetch` is already in every browser it supports.
 */

import { initAnalytics } from './analytics';
import { initAnnouncement } from './announcement';
import { initChat } from './chat';
import { initCookieConsent } from './cookie-consent';
import { initCurrencyPicker } from './currency';
import { initNavigation } from './navigation';
import { initNewsletterPopup } from './newsletter-popup';
import { initPwa } from './pwa';
import { initScreen } from './screen';
import { initTheme } from './theme';

initTheme();
initNavigation();
initAnnouncement();
initNewsletterPopup();
initCookieConsent();
initCurrencyPicker();
initAnalytics();
initPwa();
initScreen();
initChat();
