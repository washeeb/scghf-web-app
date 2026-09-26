# Licences and credits

What the site is built from, under what terms, and what the terms ask of
the foundation. Nothing here is a paid licence; nothing here restricts
the foundation's use of its own site. The one obligation that recurs is
**attribution in this file**, which is what this file is.

## 1. The application

The code in this repository (`app/`, `resources/`, `database/`, `docs/`,
`tests/`) was written for St. Cecilia's Greater Hope Foundations and
belongs to it. The brand assets — the logo files, the colour palette, the
wordmark, the photographs the foundation uploads — are the foundation's
own; photographs of people are additionally governed by the consent
records the site keeps (`PHASE-12-DATA-PROTECTION.md`).

## 2. Fonts (served from `public/fonts/`)

| Font | Author | Licence | Use here |
|---|---|---|---|
| **Inter** (variable, 400–700) | Rasmus Andersson | SIL Open Font License 1.1 | body text |
| **Plus Jakarta Sans** (variable, 600–800) | Tokotype | SIL Open Font License 1.1 | headings |

The OFL permits bundling, subsetting (both are subset to latin and
latin-ext, which covers ɛ and ɔ for Ghanaian names) and serving from the
site, and forbids selling the fonts on their own. `public/fonts/README.md`
records the versions.

## 3. Icons

**Heroicons** (Tailwind Labs, MIT) — every icon in the admin panel, via
Filament, and the seven inline icons on the public site.

## 4. Frameworks and libraries — PHP (`composer.json`)

211 packages, of which 171 are MIT. The direct dependencies and the ones
whose licence is something other than MIT:

| Package | Licence | What it does here |
|---|---|---|
| laravel/framework, livewire/livewire, filament/filament | MIT | the framework, the reactivity, the admin panel |
| spatie/laravel-permission, -medialibrary, -sluggable, -activitylog, -backup, -sitemap, -honeypot | MIT | roles, uploads, slugs, activity log, backups, sitemaps, spam |
| resend/resend-php, sentry/sentry-laravel | MIT | email transport, error monitoring |
| **dompdf/dompdf** (+ php-font-lib, php-svg-lib) | **LGPL-2.1 / LGPL-3.0** | receipt, invoice and packing-slip PDFs. LGPL permits use as a library unmodified; the library is not modified here |
| chillerlan/php-qrcode | MIT / Apache-2.0 | ticket QR codes |
| league/commonmark, league/config | BSD-3-Clause | the manual's Markdown in the Help page |
| nette/utils, nette/schema, nette/php-generator | BSD-3-Clause (or GPL at the user's choice; BSD taken) | via league/commonmark |
| vlucas/phpdotenv, tijsverkoyen/css-to-inline-styles, scrivo/highlight.php, nikic/php-parser | BSD-3-Clause | environment files, email CSS inlining, framework internals |
| phpoption/phpoption | Apache-2.0 | framework internal |
| pestphp/*, phpunit/*, sebastian/*, mockery/mockery, hamcrest, phar-io, theseer, larastan/larastan, laravel/pint | MIT / BSD-3-Clause | development only; never deployed |

A full, current list with versions: `composer licenses` in the repository.

## 5. Frameworks and libraries — JavaScript (`package.json`)

| Package | Licence | Deployed? |
|---|---|---|
| tailwindcss, @tailwindcss/vite, vite, laravel-vite-plugin, concurrently | MIT | build tools only; the output is the site's own CSS and JS |
| axe-core | MPL-2.0 | development only (the accessibility audit) |
| playwright | Apache-2.0 | development only (the browser tests, the manual's screenshots) |

The public site ships no third-party JavaScript. The optional analytics
script (Plausible, Umami or GA4) is loaded from the provider only after
the visitor consents, under that provider's terms.

## 6. Services

| Service | Terms | What is sent to it |
|---|---|---|
| Paystack | Paystack merchant agreement (the foundation's account) | amount, currency, reference, the donor's email; never card data (the donor enters that on Paystack's page) |
| Resend | Resend terms | outgoing email and its recipients |
| The SMS provider (mNotify / Arkesel / Hubtel / Twilio, as configured) | that provider's terms | phone numbers and message text |
| Sentry (optional) | Sentry terms | error reports without personal data (`SENTRY_SEND_DEFAULT_PII=false`) |
| Cloudflare (optional, recommended) | Cloudflare terms | the site's traffic, as a proxy |
| InMotion Hosting | the hosting agreement | everything, as the host |

Each is named in the privacy notice as a processor
(`PHASE-12-DATA-PROTECTION.md`).

## 7. Obligations, in one list

- Keep this file, and the fonts' `README.md`, in the repository (OFL,
  MIT, BSD, Apache all ask that the notice travels with the code).
- Do not modify dompdf and redistribute it as part of the site without
  publishing the modification (LGPL) — it is not modified.
- Do not sell the fonts on their own (OFL).
- Credit photographers where the foundation agreed to (the *Credit* field
  on each image in the media library; the site shows it in the caption).
