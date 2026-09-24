# Changelog

All notable changes to this project are recorded here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions are phase-based until launch, then [SemVer](https://semver.org/).

---

## [Unreleased]

### Live chat, courier deliveries, and the chrome as settings — 2026-09-21

#### Added — live chat

- **A live chat between visitors and the office**, held on this server —
  no chat vendor sees a word. `chat_conversations` and `chat_messages`; the
  visitor's conversation is guarded by a token stored hashed like a
  password (`X-Chat-Token`, a wrong one is a 404). The widget
  (`resources/views/components/site/chat-widget.blade.php`, `chat.js`) is
  plain script — the public site's CSP has no `unsafe-eval` — and polls
  every four seconds while open, twenty while closed; four JSON endpoints
  under `/chat`, rate-limited. **Inbox → Live chat** in the admin: the
  list (oldest unanswered first, a bell and a badge for waiting chats) and
  a live thread page with Enter-to-send, *Take this chat*, *Close*,
  *Reopen*. Being on either page is what makes the site say "We are
  online"; otherwise "We are away" and the away message, honestly. The
  office is emailed when a chat starts (`chat.new_conversation`), the
  visitor gets the transcript when it ends (`chat.transcript`, a setting).
  `chat.view` / `chat.reply` / `chat.manage`; Support, Admin and Super
  Admin. `FEATURE_LIVE_CHAT` plus **Settings → Live chat**, where every
  word of the widget lives. Retained twelve months from the last line
  (`chat_conversation` in `config/compliance.php`), then deleted with its
  messages. Manual chapter 12. `LiveChatTest`

#### Added — courier deliveries

- **Deliveries by the foundation's own riders and agents.** `deliveries`:
  one row per order in a courier's hands — assigned, picked up, out for
  delivery, delivered (to whom, a note, a photograph, where the phone was)
  or failed (why, how many attempts). `CourierService` moves the order
  through `Order::transitionTo()` at each step, so the tracking page,
  emails and texts are the ones the office already sends. **Assign a
  courier** on the order page (emails the rider, `delivery.assigned`);
  **Shop → Deliveries** for the overview, reassigning and cancelling, with
  a badge for failed attempts (`delivery.failed` goes to the shop address).
  The rider's portal at **/courier** — plain pages and big buttons, no
  script needed; the phone's position and camera when it allows — shows
  only their own deliveries; anybody else's is a 404. A **Courier** is a
  public account holding the Courier role (created under Staff accounts;
  Courier alone makes it public), signs in at the site's own form, lands
  on their deliveries, and cannot open the admin. The proof photograph is
  on the private disk behind `deliveries/{delivery}/proof`, for the rider
  and staff with `deliveries.view`. `deliveries.view` / `.assign` /
  `.courier`; Shop Manager assigns. **Settings → Courier portal** holds
  every word, the failure reasons and whether a photograph is required.
  Manual chapter 13. `CourierDeliveriesTest`
- The Staff accounts list shows couriers beside staff

#### Added — the header and footer as settings

- **Every word in the header and footer chrome is a setting**: the account
  control (now "Account") and the items in its menu (`header.account_menu`,
  a list of {route, label}), sign in/out, the icon labels, the theme
  names, back to top, add to your phone, the currency picker, the
  newsletter button and placeholder, the registration labels, the
  copyright prefix, a heading for the social links

#### Fixed

- **The footer's policy strip drew an empty band** between two rules
  wherever the policy pages were still drafts (staging). It draws only
  groups with links in them, and the cookie-preferences control is a
  button when the policy page has nowhere to link to — never a link to `#`
- `activate.sh` seeds the message templates on every deploy (wording only
  on first creation), so a template a release introduces exists before the
  code that sends it runs

### The visual template and the logo — 2026-09-20

The site was built on its own layout while the foundation's chosen
template (`Sample-Web-App-Template.jpg`, the KidHope design) and logo pack
(`SCGHF Logo.png`, `SCGHF Logo 2.3.png`) sat unused at the repository root.
Both are now used.

#### Added — the logo

- **`resources/brand/`** — the logo pack in the repository: the square icon,
  the lockup as uploaded (for light backgrounds), a **dark-background
  lockup** derived from it (the dark-green wordmark set in white; the
  orange word and the mark unchanged), and a **1200×630 social card**
  (the lockup on white with an orange rule) for links shared on WhatsApp
  and Facebook. Derived files carry a provenance note on their library row
- **`scghf:brand-assets`** — imports the four files into the media library
  (folder *Brand*, licence *own*, `custom_properties.brand_asset`) and
  fills **Header → Logo (light)**, **Logo (dark)**, the new **Logo — square
  icon** and **Search engines → Social image** wherever they are empty.
  Never overwrites a logo the foundation has since chosen; `--check`
  reports without importing. `activate.sh` runs it on every deploy
- Setting **`header.logo_icon`**; `Pwa::logo()` prefers it to the lockup,
  so the home-screen icon is the mark rather than a wide lockup squashed
  into a tile
- The header renders the logo at 40/48px with `sizes="220px"`, so the
  browser fetches the 320px conversion rather than the 1600px one (the
  image component gained a `sizes` prop for exactly this)

#### Added — the header's two menus

- **The theme control is an icon that opens a menu.** The button shows
  the icon of the theme in force (sun, moon, a screen for *Match my
  device*, a swatch for the third palette — chosen by CSS from the
  `data-theme` the server puts on `<html>`, so it is right before any
  script runs) and opens a menu of the four with the current one ticked
  (`menuitemradio`, so a screen reader hears "Dark, checked"). It sits
  after the donate button, last in the row. A `<details>` like the
  navigation dropdowns — Escape and click-away close it — but not opened
  on hover: `data-no-hover` keeps hover-intent to the navigation
- **"Your account" is one menu.** Overview, Your impact, Receipts,
  Regular giving, Profile, Security, then Sign out set apart under a rule
  — still a POST. In the phone panel the same links are a plain list
  (`layout="list"`). Two top-level items ("Your account", "Sign out")
  spent header width on the rarest action
- `<x-ui.icon>` draws any Heroicons outline name and nothing for one the
  set lacks; the curated `Icons::OPTIONS` list is only what the card
  forms offer editors

#### Added — the template

- **Typography**: page and section titles in **Fraunces**, a serif
  display face self-hosted like Inter and Plus Jakarta Sans (`font-display`
  token, new; two variable WOFF2 subsets, SIL OFL, `public/fonts/README.md`),
  card titles and everything else in the sans as before
- **The header**: the top bar on by default (`header.show_top_bar` → `1`
  on new installs), on the accent colour with the address, phone, email
  and hours from *Contact* — it renders only once one of those is filled;
  a search icon when site search is on; the Donate button as a green pill
- **The hero**: the headline with its first word underlined in the accent,
  pill calls to action, a gradient rather than a flat overlay, and the next
  section's white panel rising into the foot of the photograph with
  rounded corners
- **Eyebrows**: every block with a heading gained an `eyebrow` field — the
  short uppercase line with the orange rule above the title; the section
  wrapper renders it, in the band's ink on a brand or inverse band
- **Feature cards** carry a line icon in a tinted disc, chosen per card
  from a short list (`App\Support\Icons`, drawn by `<x-ui.icon>`, Heroicons
  via the package Filament already ships); with none chosen the card
  shows its initial. Division, appeal and project cards: larger radius,
  shadow, 4:3 pictures, an orange pill on each appeal
- **Text and image** sets the photograph slightly askew on a tinted card
  and straightens it on hover; **impact numbers** put the figure large in
  the display face with the unit under it (`ImpactMetric::formatFigure()`)
  and hairlines between the four; the **donation widget**, **FAQ**,
  **testimonials**, **newsletter** and **core values** take the same radii
  and pills
- **The CTA band's `background = image`** now draws the photograph band
  it had offered since the block was defined — the picture edge to edge,
  darkened, the title centred — instead of silently dropping the picture.
  The **FAQ block's `image`** field is likewise resolved and rendered now
- `.btn`, `.btn-brand`, `.btn-accent`, `.btn-outline`, `.btn-sm` and
  `.eyebrow` in `app.css`, so every call to action is one shape
- **The home page arrangement** in `LaunchContentSeeder`: eyebrows on
  every section, the impact numbers and the new **donation band** on the
  brand colour, appeals with "Donate now", a **FAQ with its side picture**
  before the closing band. On a page already seeded, `refreshLayout()`
  fills in only what is missing — an eyebrow, an icon, a band's settings,
  a block the page has none of — and never a word an editor changed
- A card's photograph credit goes into the image's `title` attribute
  (`credit="title"`) rather than an italic line under every thumbnail;
  page-level pictures keep the visible caption
- Tests: `SiteTemplateTest` — the import, its idempotence, the
  never-overwrite rule, the header's two logo files, the PWA icon following
  the square icon, the home page's template markup and block order, the
  layout refresh keeping an editor's heading, the photograph band and the
  FAQ picture, and the credit placement

#### Fixed — the deploy

- **No uploaded image was ever visible on a real server.** `public/.htaccess`
  has blocked `^(vendor|node_modules|storage|bootstrap)` since the first
  commit — a rule meant for the Laravel root, in the file that lives in
  `public/`, where `storage/` is the media library's public link. Apache
  answered 403 for every picture, the logo included; local development
  (`artisan serve`, no Apache) never saw it, and staging had not been
  looked at with pictures on it until today. `storage` is out of the rule;
  `PublicHtaccessTest` keeps it out
- **Staging served a release from the morning all day.** PHP-FPM on this
  host runs OPcache with `opcache.revalidate_path=0`: a script is cached
  under the path it was requested by (`…/current/public/index.php`),
  resolved once, and the timestamp check re-reads that first resolution —
  the previous release's file, unchanged — so moving `current` changed
  nothing the workers could see. The deploy's own reset route lives in the
  new release, which the workers did not have, hence the 405s. Now:
  `activate.sh` writes `public/.user.ini` with `opcache.revalidate_path=1`
  (FPM reads it; the symlink is resolved per request), and when the reset
  route is unreachable it resets through a one-off file under
  `public/deploy/` — a script the workers have never seen — and removes it
- The release tree lets `resources/brand/` through the image exclusion, as
  it already did the manual
- `activate.sh` runs `SettingsSeeder` and `ThemeSettingsSeeder` (both
  create-only), so a setting or token a release introduces reaches the
  panel with its default

#### Open

- **GitHub Actions minutes.** Every run from `12603b9` was refused with
  *"recent account payments have failed or your spending limit needs to be
  increased"* — which on a free plan means the private repository's
  2,000 minutes a month were spent: ~100 runs in four days, five jobs of
  15–20 minutes per push (three in CI through the always-open release PR,
  two in the deploy). Two releases reached staging by hand
  (`docs/DEPLOYMENT.md` §3a). The repository was made **public for 24
  hours** from 2026-09-20 13:53 UTC at the owner's request (public repos
  are unmetered); the profile document, template preview and cPanel
  screenshots were moved out of the tree first (`../SCGHF-source-documents/`).
  To stay inside the allowance afterwards, CI now skips draft pull
  requests, runs browser tests and coverage only for pull requests into
  `main`, weekly and on demand, and the release PR is kept a draft between
  releases — one 20-minute gate per push to `develop` (`docs/DEPLOYMENT.md` §2a)

### Hosting — the foundation's own cPanel account and domain — 2026-09-19

The project left the shared `presti98` account on 2026-09-02 and parked
the server-side runbook steps until a host in the foundation's own name
existed. It now does: InMotion cPanel account **`n789825`**
(`secure381.inmotionhosting.com`, dedicated IP `192.145.232.80`) with
**`greaterhopefoundations.org`** as its primary domain. The pipeline is
unchanged; the values were re-pointed.

#### Changed

- Every deploy-side value — `.env.example` (cPanel legend, `APP_URL`,
  database names `n789825_scghf_*`, mail addresses), `deploy/scripts/*`,
  `deploy/cpanel/*`, the deploy workflow's comments, the runbook, the
  deliverability and infrastructure docs, the README's branch table and
  the analytics setting's help text — now names the `.org` domain and the
  `n789825` account. `docs/ENVIRONMENT.md` regenerated
- **`bootstrap-server.sh`** wires the production docroot to
  `~/public_html`: the `.org` is the account's *primary* domain, so cPanel
  serves it from `public_html`, not from a directory named after the domain
  as it did for the addon `.com`. The URL it reports for `APP_URL` comes
  from a host variable rather than the docroot's basename
- **`activate.sh`** writes the `Sitemap:` line of the production
  `robots.txt` from `APP_URL` in `shared/.env` instead of a literal domain,
  so the file cannot drift from the environment it is generated for
- The `.cpanel.yml` fallback's paths follow the same change
- `PHASE-2-RUNBOOK.md`: the 2026-09-02 "hosting deferred" note is closed;
  the header table, step 5 (import the key), step 6 (the concrete secret
  values) and a new **step 7.0 — DNS** (the domain is registered at
  Namecheap and still resolves to its parking page; hand the zone to
  InMotion's nameservers or add A records for `@`, `www`, `staging`) are
  written against the new account

#### GitHub

- Both environments (`production`, `staging`) now hold `SSH_HOST`,
  `SSH_PORT`, `SSH_USER`, `SSH_KNOWN_HOSTS`, `SSH_PRIVATE_KEY`,
  `DEPLOY_PATH`, `PHP_BIN` and the variable `APP_URL` for the new host.
  Nothing else changed: the deploy key is the pair generated on
  2026-09-02; Paystack, database and mail credentials still live only in
  `shared/.env` on the server

#### Fixed

- The deploy key generated on 2026-09-02 was passphrase-protected by
  accident — PowerShell passed `-N '""'` as a literal two-character
  passphrase — so the server accepted the key and the client could not
  sign. Passphrase stripped (public half unchanged), the GitHub secret
  re-uploaded, runbook step 5 rewritten for Git Bash with a verification
  command. The key was tested against `n789825` on 2026-09-19: PHP 8.4.24,
  every required extension, `mysqldump`/`rsync`/`flock` present, MariaDB
  10.6 client

#### Fixed — CI had been red since 17 September

- Every `composer install` in both workflows ran before `.env` existed.
  Composer's `post-autoload-dump` hook runs `php artisan package:discover`,
  which boots the application; with no `.env`, `APP_ENV` is `production`,
  and `PaymentServiceProvider` refuses — correctly — to boot production on
  the fake payment driver. The workflows now copy `.env.example` to `.env`
  **before** Composer runs (five places). The file never reaches the
  server: the release tree excludes it
- The Deploy workflow's quality gate ran the suite with **no MySQL
  service** — every database-backed test failed with *Connection refused*
  once Composer got past the step above. It now runs the same `mysql:8.4`
  service as `ci.yml`, with a 45-minute budget instead of 15
- The test jobs logged in to that service as the cPanel database user from
  `.env.example` (`phpunit.xml` overrides the database name, not the
  credentials). `DB_USERNAME=root` / empty password are now job-level
  environment variables in all four test jobs
- The test jobs ran before (or without) `npm run build`; every layout
  calls `@vite`, so with no `public/build/manifest.json` every page was a
  500 and the accessibility sweep failed for a reason unrelated to the
  code. Assets are now built before the tests in the CI quality and
  coverage jobs and in the Deploy quality gate
- With all of that in place the suite passed on the runner (2030 tests,
  22 minutes) and the gate's secret scan then tripped on the launch-check
  test's fake live key, `sk_live_abcdefghijklmnopqrstuvwxyz` — long enough
  to match the scanner's pattern. The fixture is now `sk_live_fixture`;
  the code only reads the prefix
- `DEPLOY_PATH` and `PHP_BIN` had been stored mangled — Git Bash's MSYS
  layer rewrote the POSIX paths to `C:/Program Files/Git/…` before
  `gh.exe` saw them — so the deploy's first server-side check reported
  `shared/.env` missing. Re-set by piping the values; the runbook warns
- `activate.sh` now **stops** a production deploy on `PAYMENT_DRIVER=fake`
  instead of warning; the application would have refused to boot at the
  migrate step a moment later with a less helpful stack trace

#### Fixed — the server's default storage engine is MyISAM

- The first staging migration failed on `sessions` with *max key length is
  1000 bytes* — the MyISAM limit. InMotion's MariaDB has
  `default_storage_engine=MyISAM`, and both MySQL-family connections had
  `'engine' => null` ("whatever the server defaults to"), so `users` and
  `password_reset_tokens` had already been created **without transactions
  or foreign keys**. `config/database.php` now pins `InnoDB` on both
  connections, and a new launch-check row, **Every table on InnoDB**
  (`X6a` in `docs/LAUNCH.md`), fails on any table that is not — for the
  case the pin cannot cover, a table made by hand in phpMyAdmin

#### Fixed — MariaDB's legacy TIMESTAMP rules

- With InnoDB pinned, 41 migrations ran and the 42nd failed:
  `error_reports.last_seen_at timestamp not null` — *Invalid default
  value*. MariaDB 10.6 has `explicit_defaults_for_timestamp=OFF`: the
  first NOT NULL timestamp of every table silently gains `DEFAULT
  CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` (a `first_seen_at` that
  changes on every update) and the second gets a zero date that strict
  mode rejects. Seventeen columns across the migrations are written this
  way, for MySQL 8 where the setting is on. Both MySQL-family connections
  now send `SET SESSION explicit_defaults_for_timestamp = 1` as the PDO
  init command, and a launch-check row, **No silent ON UPDATE timestamps**
  (`X6b`), fails on any column that carries it. Staging's database was
  recreated from empty, since its first 41 tables had been made under the
  old rule

#### Fixed — the first release that activated, and what it taught

- **Web requests ran PHP 8.3.** cPanel pins a domain's PHP by writing an
  `AddHandler application/x-httpd-ea-php84` block into the docroot's
  `.htaccess`; our docroot is a symlink to each release's `public/`, whose
  `.htaccess` comes from the repository without it. `activate.sh` now
  appends the block to every release, derived from `PHP_BIN` (a non-cPanel
  host gets nothing). PHP-FPM, which would have made the version part of
  the vhost, is not offered on this plan
- **A dangling docroot broke two other things**: cPanel refused the PHP
  change for *every* domain in the call because production's
  `public_html/.htaccess` did not exist, and AutoSSL's HTTP validation
  could not create `.well-known/`. `bootstrap-server.sh` now creates a
  holding release ("Coming soon", noindex) and points `current/` at it, so
  the docroot always resolves
- **The smoke test got 406** — InMotion's edge rejects curl's default
  User-Agent; the check now sends a browser-shaped one
- **`rollback.sh` rolled back onto a release that had never been live.**
  With no `previous_release` recorded it guessed "the newest other
  directory", which was the release whose migrations had failed. It now
  refuses when nothing is recorded, as `docs/DEPLOYMENT.md` always said
- The smoke test's security sweep accepts 406 (ModSecurity refusing `/.env`
  before Apache) alongside 401/403/404/410 as "not served"; a 200 is
  still the failure
- Let's Encrypt certificates are issued for the domain, `www` and
  `staging`; staging answers `/up` with 200 over verified TLS on PHP
  8.4.24 with all 140 tables on InnoDB

#### Fixed — what the first hour of clicking around staging found

Four errors in staging's log from one person's first session, none of
which the 2,030-test suite could have caught, because they only appear
with **more than one row** (strict Eloquent's lazy-load guard ignores a
model that came from a collection of one) or with a code path no test
exercised.

- **Projects, Appeals and News list pages returned 500** — each title
  column's `description()` reads a relation (`primaryLocation()`,
  `project`, `category`) that the query did not eager-load. Each table
  now `modifyQueryUsing` with the relation
- **Assigning a contact message** queried `users.user_type`; the column
  is `type`. Both the form and the table action now use the `staff()`
  scope every other picker uses
- **Redeeming a two-factor recovery code returned 500** — *This password
  does not use the Bcrypt algorithm*. Filament stores recovery codes
  hashed and `Hash::check`s them; `docs/tools/demo-totp.php` stored the
  demo code in plain text, and `UserFactory::withTwoFactor()` did the
  same. Both hash now
- **The deploy's smoke test got 500 through the staging gate** while
  anonymous probes got 401: Apache (not PHP) reads `shared/htpasswd`, as
  its own user, only when credentials are presented, and the file was
  640. `activate.sh` sets it 644 — it holds a hash and nothing else
- New **`AdminWithDataTest`**: seeds the base and demo datasets, then
  opens every admin list page, every custom page, and the first record of
  every view/edit page as a Super Admin. Three of the four bugs above fail
  it; it is the guard the suite was missing

#### Added — a third palette, hover menus, and the switches for them

- **Vibrant**, a third palette beside light and dark: warm cream ground,
  the teal and the coral at full strength, a violet focus ring. Every
  colour token has a value for it (`ThemeSettingsSeeder::VIBRANT`), each
  checked against the same AA contrast obligations as the other two by
  the existing test, and all of it editable under Appearance → Theme
  colours like the rest. `ThemeTokens` emits it as `.vibrant{…}`;
  `ThemePreference`, the no-flash script and `theme.js` accept it; the
  browser's theme-colour now follows the palette's own `--bg`. *System*
  stays what it was — follow the device — which is why it looked the same
  as Dark on a dark PC; the colourful look is a choice a visitor makes, or
  the default the settings set
- Settings → Site & footer: **Default theme** offers Vibrant; **Name of the
  third theme** is what the control calls it; **Open menus on hover**
  (on) — with a mouse a header drop-down opens when the pointer rests on
  it and closes 220 ms after it leaves; touch and keyboard keep the
  click-to-open disclosure, and the switch is read by `navigation.js`
  from the nav element; **Footer policy groups** — the list of
  `{label, slugs}` the footer groups the legal links by, with the seeded
  three as the fallback for a broken edit
- The footer's policy links highlight on hover again (an `!important`
  colour had beaten the hover colour)
- `SiteChromeSettingsTest` covers the four switches; `ThemeSettingsTest`
  the third palette

#### Changed — the footer's bottom strip reads as groups

- Ten policy links, a currency form and two utilities shared one flex
  row and wrapped into a ragged pair of lines. The strip is now two
  rows: the policies grouped by what they govern — **Legal** (privacy,
  terms, cookies, cookie preferences), **Giving** (donation policy,
  refunds), **Conduct** (safeguarding, accessibility, raising a concern,
  anti-fraud) — under small labels, decided by each link's page so an
  editor's addition lands in the right group; then the copyright and
  registration line with the currency picker, *Back to top* and *Add to
  your phone* aligned on the right. The currency label no longer wraps;
  its "approximate" note takes its own line on phones. The
  cookie-preferences link is drawn only when the cookie policy is live —
  the accessibility sweep caught an `href="#"` otherwise
- `scghf:opcache-reset` retries on a timeout as well as on the previous
  release's 404/405, with a 40 s request timeout; the first real run
  showed the workers taking two attempts to reach the new release

#### Fixed — the first deploy of the launch content served pages without their pictures

- InMotion's PHP runs under **PHP-FPM after all** (the API had said no),
  and FPM keeps compiled bytecode across deploys; nothing restarts the
  pool when the release symlink moves. Every artisan command the deploy
  runs is a fresh process and saw the new code; the web workers served
  pages with no pictures until `opcache_reset()` ran inside a web
  request. New: `POST /deploy/opcache-reset` (CSRF-exempt, rate-limited,
  guarded by a one-time token the deploy mints into the shared cache) and
  `scghf:opcache-reset`, which `activate.sh` calls after the flip,
  non-fatally. The staging gate leaves `/deploy/` open for it
- `BlockDataResolver` reports the exceptions it swallows instead of
  hiding them

### Launch content — the site as it goes live — 2026-09-20

#### Added

- **`LaunchContentSeeder`** — the foundation's own words from its
  profile (vision, mission, purpose, the seven values, the four
  divisions and their focus areas, the beneficiary groups, the founder)
  on the home, about, story, vision, values, leadership, how-we-work,
  transparency, contact and FAQ pages; and placeholder programmes (two
  per division), appeals (one per division), news, events, impact goals,
  FAQs, a gallery and two testimonials, written in the foundation's
  voice. Every invented record carries `[Placeholder — replace with the
  real thing]` in a field staff see. Idempotent; never overwrites a page
  that has sections; publishes the pages it wrote and leaves the legal
  pages as drafts. Placeholder testimonials are seeded **unpublished** —
  the model refuses to publish a beneficiary's words without a consent,
  and an invented voice has nobody to consent
- **`scghf:launch-images`** and `database/seeders/launch-images.json` —
  47 licensed Unsplash photographs (27 photographers, mostly Ghanaian),
  each with the slot it fills, alt text, photographer and source page.
  Fetched through `MediaLibrary::add()` (policy, metadata stripping,
  conversions) into a *Launch photography* folder, tagged by slot,
  idempotent; `activate.sh` runs it on every deploy, non-fatally. The
  files never enter git
- **Media licence** — `media.licence` (`own` | `stock`) and
  `licence_url`, with a *Licence* section on the media form. The
  photo-consent gate applies to the foundation's own photographs; a
  licensed picture's permission is the licence. Manual chapter 4 says
  what that means and what it must not be used for
- Launch-check row **Launch placeholders replaced** (`X6c`): fails while
  any placeholder record remains
- The divisions block shows each division's picture
- `DemoDataSeeder` on a database that has the launch content keeps its
  accounts and transactions but skips its invented programmes, appeals
  and posts, and gives to the real appeals
- `LaunchContentTest`: the licence gate, the command (faked Unsplash),
  the seeder twice, the seeder without pictures, the launch-check row,
  the demo seeder's deference

#### Fixed — the custom admin pages rendered as unstyled text

- Shop reports, Giving reports, Accounting export, Site health,
  Analytics, The door, Help and Settings are written with Tailwind
  utilities, and Filament's compiled stylesheet carries only Filament's
  own classes — so every one of them rendered as a column of raw text
  (the Phase 16 notes knew this and worked around it with inline styles
  in places). The panel now has a proper **Vite-compiled theme**
  (`resources/css/filament/admin/theme.css`, `->viteTheme()`), which
  is Filament's CSS plus whatever `app/Filament` and
  `resources/views/filament` use. Built by the same `npm run build` the
  deploy already runs; the deploy verifies it is in the Vite manifest.
  The *Group by* select on the report pages matches the height of the
  date inputs beside it
- **Public form fields with a hint were misaligned** beside fields
  without one: `<x-site.field>` put the hint between the label and the
  box, so the email box on the donate form sat a line lower than the
  name box. The hint now sits below the box; `aria-describedby` still
  ties it to the field

#### Fixed — the demo ledger no longer wakes the nightly reconciliation

- `DemoDataSeeder` recorded 36 completed gifts and acknowledged none,
  on the belief that "a receipt is an email". It is not: the
  acknowledgement is the numbered receipt row (`ReceiptIssuer`), and the
  email is a separate step the seeder never calls. Unacknowledged
  completed gifts are exactly what `scghf:reconcile-payments` reports, so
  staging's scheduler logged an error every night. The seeder now
  `recordAndAcknowledge`s every gift, no email is sent (asserted), and
  reconciliation over the demo ledger reports zero missing receipts
- Acknowledgements refuse to issue without the foundation's TIN, which
  on a fresh staging is still the `{{TIN}}` placeholder. The seeder fills
  it with an obviously fake **`C0000000000`** only when it is unfilled,
  never over a real one, and the launch check's *No demo data* row now
  names "demo TIN in Settings" so it can never pass as real

#### Added — the staging password gate is part of the deploy

- cPanel's Directory Privacy writes its directives into the docroot's
  `.htaccess` — the release's `public/.htaccess`, replaced on every deploy
  — and its folder picker offered the old `.bak` directory, which is what
  got protected. `activate.sh` now writes an HTTP Basic-auth block into
  every non-production release from `shared/htpasswd` (created once with
  `htpasswd -c`), leaving `/up`, `/webhooks/*` and `/.well-known/*` open
  for the health check, Paystack test events and AutoSSL renewals. Never
  on production; a non-production deploy with no password file warns that
  the environment is open
- The smoke test takes an optional `SMOKE_BASIC_AUTH` secret (`user:pass`)
  to check the homepage through the gate; without it a 401 on the
  homepage counts as alive and `/up` proves the boot

#### Fixed — nobody could sign in to the admin panel on the real host

- `public/.htaccess` carried a `Header always setifempty
  Content-Security-Policy` fallback "for files Apache serves without
  PHP". Under cPanel's suPHP the headers PHP sets are not in the table
  `Header always` inspects, so the fallback went out on **every** page
  beside the application's own policy; browsers enforce the
  intersection, which has no nonce and no `unsafe-eval` — inline scripts
  failed on the public site and Livewire could not start in the admin
  panel. Local dev has no Apache, so it could never show. Removed: what
  it covered (static assets, Apache error pages) runs no script. Verified
  by signing in to staging through password and TOTP
- Open: InMotion's ModSecurity answers **406 to `POST /csp-report`**, so
  violation reports from browsers are dropped on this host. Harmless to
  users; ask InMotion to exempt the path, or accept blind report-only
  mode. Recorded in the runbook

#### Changed — `fakerphp/faker` is a runtime dependency

- `docs/DEPLOYMENT.md` §7 has always said to load staging with
  `DemoDataSeeder`; the first attempt on the real host stopped at
  `OrderFactory`: *Call to undefined function fake()*. Faker was a dev
  dependency and the release is built `--no-dev`. Moved to `require`
  (v1.24.1, no other lock changes) — it is small, has no runtime side
  effects, and `DemoDataSeeder` still refuses to run in production

#### Changed — the database is MariaDB

- The server is **MariaDB 10.6.28**, so `shared/.env` uses
  `DB_CONNECTION=mariadb` and `.env.example` says so. `config/backup.php`
  dumped the connection literally named `mysql` and `scghf:restore-test`
  read `database.connections.mysql`; both now follow the active
  connection, otherwise the backup and the restore test would have looked
  at a connection the application was not using

#### Server state after 2026-09-19 (details in the runbook's status table)

Bootstrapped both environments; `shared/.env` pre-filled with the
non-secret values and a server-generated `APP_KEY`; PHP 8.4 pinned on both
vhosts; staging subdomain and the three databases created through `uapi`;
cron installed. **Yours:** database users and passwords, `DB_PASSWORD` in
both `.env` files, PHP-FPM and INI values, Directory Privacy on staging,
AutoSSL once DNS lands, mailboxes and SPF/DKIM/DMARC, cPanel 2FA.

#### Still to do on the server (runbook steps 7–9)

DNS at Namecheap · MultiPHP 8.4 +
FPM + INI values · two databases · staging subdomain with Directory
Privacy · AutoSSL · mailboxes and SPF/DKIM/DMARC · cPanel 2FA ·
`bootstrap-server.sh` for each environment · fill `shared/.env` · cron.
The first push after that deploys staging.

### Wave 2 — W2.5 WhatsApp as a fifth channel — 2026-09-19

Built, tested, and **off by default** (`FEATURE_WHATSAPP=false`): the one
dependency this application cannot supply is Meta's verification of the
foundation's business and approval of its templates, which take weeks.
With the flag off the opt-in box is not shown and nothing is sent; with
it on, `scghf:launch-check` names anything still missing.

#### Added

- **`whatsapp_templates`** (`2026_09_19_000004`) — our key, Meta's
  template name and language, our variable names in the order of Meta's
  `{{1}}`, `{{2}}`, a readable copy of the wording for the log, and
  `is_approved`. `WhatsappTemplate::forKey()` refuses an unapproved or
  unnamed template, so nothing that could not be sent is ever queued.
  Seeded: `donation.receipt` (utility, five parameters) and
  `cause.update` (marketing, four); the seeder never overwrites the Meta
  name, language or approval once set
- **`sms_logs.channel`** (`sms` | `whatsapp`) — a WhatsApp message is a
  phone-addressed, provider-charged, delivery-reported message and the
  SMS log already has every column; the delivery-log screen gets a
  channel badge and filter. `consent_whatsapp` on donors and donations
- **`WhatsappGateway`** contract; **`WhatsappCloudGateway`** (Meta's
  Cloud API directly: one POST with the template name, language and
  body parameters, the `wamid` kept for the status webhook, Meta's
  refusal recorded in its own words); **`LogWhatsappGateway`** (records
  and costs, sends none — the default)
- **`MessageDispatcher::queueWhatsapp()` / `sendWhatsappNow()`** — the
  same refusals as SMS in the same order, each a log row: the feature
  flag, a **WhatsApp-specific suppression** (`Suppression::CHANNEL_WHATSAPP`
  — "stop texting me" and "stop WhatsApping me" are different requests),
  quiet hours for marketing
- **The opt-in**: a checkbox on the donate form beside SMS, shown only
  while the channel is on and ignored otherwise; carried on the donation
  and the donor
- **The receipt on WhatsApp** (`DonationNotifier::whatsapp()`, for a
  donor who ticked, after the SMS) and **appeal updates on WhatsApp**
  (`CauseUpdateNotifier`, to consenting, non-anonymous donors, one per
  number)
- **Meta's webhook**: `GET /webhooks/delivery/meta` answers the
  subscription handshake only with `WHATSAPP_WEBHOOK_VERIFY_TOKEN`;
  `POST` is verified with the app secret over the raw body
  (`X-Hub-Signature-256: sha256=…`, the prefix stripped by config);
  statuses `delivered`/`read` mark the row delivered, `failed` marks it
  undelivered with Meta's reason, `sent` and inbound messages are stored
  and not acted on. Each status is its own idempotent event
- **Communications → WhatsApp templates** (`WhatsappTemplateResource`,
  behind `templates.sms.manage`): the Meta name, the language, the
  placeholders in order, the approval tick, and whether the channel is on
- **`scghf:launch-check` row `whatsapp`**: OK when off; when on, names
  every missing credential, the driver, and the absence of an approved
  template
- `.env.example`: `FEATURE_WHATSAPP`, `WHATSAPP_DRIVER`,
  `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`,
  `WHATSAPP_APP_SECRET`, `WHATSAPP_WEBHOOK_VERIFY_TOKEN`,
  `WHATSAPP_COST_PER_MESSAGE_MINOR`, `WHATSAPP_BASE_URL`,
  `WHATSAPP_API_VERSION` — every one read by `config/communications.php`
- Manual: "WhatsApp" under *Newsletters and SMS*; a quick-reference row;
  `docs/LAUNCH.md` row X7a
- `tests/Feature/WhatsappChannelTest.php` — 11 tests: seeded unapproved
  and refused until approved, parameters in Meta's order with newlines
  removed, the log driver and the flag, the separate suppression, the
  Cloud API request shape and a refusal, the opt-in through a real
  donation (queued only with the tick and the flag), the box hidden and
  the tick ignored with the flag off, appeal updates to the right numbers,
  the handshake, signed and unsigned statuses, the launch-check row, the
  panel

#### Not built (and said so)

- **Two-way enquiries** (inbound WhatsApp into *Inbox → Messages* with a
  reply from the panel) — the roadmap's "second step"; inbound messages
  are stored as webhook events and not acted on

### Wave 2 — W2.4 Grant management, and the payouts screen — 2026-09-19

#### Added

- **Four tables and one column** (`2026_09_19_000003`): `funders`,
  `grants` (pipeline status, amounts asked and awarded in pesewas,
  deadline, project, restricted flag, owner), `grant_obligations` (what
  the funder is owed, by when, done when, reminded when),
  `grant_documents`, and `payouts.grant_id` — a payout charged to a grant
- **`Grant`** with `submit()`, `award(Money, …)` (refuses a zero award),
  `decline()`, `close()`; **spend is read from the ledger**: `spent()`
  (paid payouts charged to it), `committed()` (approved, unpaid),
  `remaining()`. `Funder`, `GrantObligation` (`complete()`,
  `isOverdue()`, `needingReminder` scope), `GrantDocument`;
  `Payout::grant()`, `Payout::cause()`
- **Finance → Grants** (`GrantResource`): list with the nearest deadline
  first and the count of obligations due, the pipeline as actions on the
  grant's page (start drafting, mark submitted, awarded with the figure,
  declined, close), the money section with spent/committed/remaining,
  relation managers for **obligations** (add, mark done),
  **documents** (private disk, day-long signed link through
  `GrantDocumentController`) and **spend against the grant**; export.
  **Finance → Funders**: kind, contact, notes, an optional link to the
  public partner row; never published
- **`scghf:grant-reminders --execute`**, daily at 07:30: emails the
  grant's owner (`grants.obligation_due` template) for every obligation
  not done and due within a fortnight, and again weekly until it is
- **Finance → Payouts** (`PayoutResource`) — **the expenditure screen the
  ledger never had.** `Payout`, `payouts.request/approve/mark_paid` and
  the two-person rule have existed since Phase 7 with no way to use them
  but a terminal. Now: raise a draft (attributed to a division, project
  or appeal as the model insists; optionally a grant and an approved
  beneficiary case), submit, approve (the model refuses the requester and
  says why), reject with a reason, **mark paid with the evidence file**
  (through the media library onto the private disk), cancel with a
  reason; no edit after the draft, no delete. `PayoutPolicy` maps
  `create` to `payouts.request` and `update` to the step the status is at
- Permissions `grants.view` / `grants.manage` in the fundraising group;
  Finance Officer holds both, Programme Officer and Auditor read
- Demo data: two funders, three grants across the pipeline, an award with
  three obligations and a paid payout charged to it
- Manual: "Payouts — money going out" and "Grants — where the larger
  money comes from" under *Donations*; two quick-reference rows
- `tests/Feature/GrantsTest.php` — 8 tests: the pipeline and the zero-award
  refusal, spend read from the ledger and shown on the page, the actions
  by permission, the reminder cadence (a fortnight out, weekly, never
  for a done obligation), marking done from the page, the signed
  document link, raising and approving a payout with the requester
  refused, and the model's own refusal of an unattributed payout

### Wave 2 — W2.3 Multi-currency display — 2026-09-19

Display only. Every gift is still charged in GHS, receipted in GHS and
ledgered in GHS; the roadmap's "charging in other currencies" is not
this and is not built.

#### Added

- **`App\Support\ExchangeRates`** — cedis per US dollar, pound and euro
  from a keyless daily feed (`open.er-api.com`), fetched by
  **`scghf:refresh-rates`** at 05:30 and kept in the cache **as scaled
  integers** (one ten-thousandth of a cedi) forever, so a feed that is
  down leaves yesterday's figure rather than none; manual rates under
  Settings → Currency as the fallback, or as the source outright (the
  Bank of Ghana's rate, typed in). A refresh empties the page cache
- **`App\Support\CurrencyDisplay`** — the visitor's second currency (the
  `scghf_currency` cookie, unencrypted like the theme cookie because the
  page cache keys on every `scghf_*` cookie) or the foundation's default
  (`currency.display_default`); conversion by integer arithmetic on
  pesewas; "≈ $ 12.00", whole units above 100
- **`<x-site.money>`** — the cedi amount first and always, the
  approximate figure as small print; used on the progress bar (raised and
  goal), the giving levels, product cards and product prices
- **The footer picker** (`<x-site.currency-picker>`, `POST /currency`):
  a plain form; `resources/js/currency.js` submits it on change with no
  inline handler (the CSP has none); the return URL is checked to be on
  this host. Shown only when there is a rate to show
- Settings → Currency: `display_default`, `rate_source`, `rate_usd`,
  `rate_gbp`, `rate_eur`; manual section under *Donations* and a
  quick-reference row
- `tests/Feature/CurrencyDisplayTest.php` — 8 tests: the feed parsed to
  scaled integers, a failed feed keeping the last rates, conversion and
  rounding, manual source and fallback, nothing shown without a rate, the
  default and the cookie on a real page, the picker's cookie and refused
  off-site redirect, the console output

### Wave 2 — W2.2 Accounting export — 2026-09-19

#### Added

- **`App\Finance\JournalExport`** — a month of the ledger as balanced
  double-entry journal lines: a gateway gift as gross income into
  Paystack clearing with the fee out of it; an offline gift into cash,
  bank or MoMo with no fee; an order as sales plus shipping recovered
  plus the fee; a processed refund reversing the income it came from; a
  paid payout to its category's expense account from the account it was
  paid from. Amounts are Money to the last step; `totals()` proves debits
  equal credits and the page and the command refuse an unbalanced month.
  Settlements are deliberately absent — the bank statement is their
  evidence and the clearing balance is what reconciliation checks
- **Chart of accounts in Settings → Accounting** (`accounting.*_code`,
  `*_name` for seventeen accounts, and `accounting.package`), so the
  codes and names in the file are the accountant's, set once
- **Finance → Accounting export** (`AccountingExportPage`, behind
  `donations.export`): the month, the lines, the totals and the proof of
  balance, the chart in use, **Download CSV** in the column shape of the
  chosen package (QuickBooks, Xero — signed amounts and tracking —, Zoho
  Books, or generic); recorded as `report.generated` with the month and
  the line count
- **`scghf:journal-export {month?} {--package=} {--store}`** — the same
  file from the terminal, or filed under `storage/app/journals/`
- `Payout::cause()`; manual: "The accounts: the monthly journal" under
  *Donations*, a quick-reference row
- `tests/Feature/AccountingExportTest.php` — 9 tests: each entry type's
  lines and balance, anonymity in the contact column, settings-driven
  codes, per-package columns and date formats, the page download and its
  audit, the console command and its refusals

### Wave 2 — W2.1 Beneficiary case management — 2026-09-19

Built from `docs/DESIGN-BENEFICIARY-CASES.md` under its own assumptions
(the note's status block says which); every visibility decision is data
in one file and can be changed without touching a screen.

#### Added

- **Encryption at rest for the case record** (migration
  `2026_09_19_000001`): `phone`, `email`, `ghana_card_number`, `address`,
  `bank_account`, `momo_number`, `next_of_kin_*`, `household_details`,
  `school_or_employer`, `religion`, `medical_notes`,
  `application_narrative`, `case_notes` are `encrypted` casts; the columns
  widened to TEXT. Name, gender, geography, birth date, amounts and dates
  stay in clear (the list and the anonymous projection need them; the
  migration says why). `scghf:encrypt-at-rest` sweeps existing rows and
  **now runs on every deploy** with `RoleAndPermissionSeeder`
  (`activate.sh`, `.cpanel.yml`)
- **A blind index for the Ghana Card number** — `ghana_card_index`, an
  HMAC-SHA256 of the normalised number under APP_KEY, kept in step by the
  model's `saving` hook; `Beneficiary::withGhanaCard()` answers "has this
  person applied before?" without the number ever being in a query.
  Classified `national_id` so retention destroys it with the number
- **`beneficiary_notes`** — the case log as rows: author, timestamp, kind
  (note / status / consent / document / reveal), encrypted body, **no
  `updated_at`**; `BeneficiaryNote` throws on update and on delete; the
  foreign key cascades so the retention runner's hard delete takes the log
  with the case. `Beneficiary::note()` is the one way in; `submit()`,
  `startReview()`, `approve()`, `decline()`, `withdraw()`, `close()` each
  write one
- **`App\Beneficiaries\FieldMap`** — every field on a case with its
  views (A summary, B working, C sensitive, F financial, U audit), which
  actor kinds may edit it, its section and its type. **`CaseAccess`** —
  who a person is to a case (worker on it, Safeguarding Lead, Super Admin,
  Finance, Auditor, viewer) from the permissions their roles actually
  carry (never the wildcard), and therefore their views, editable fields,
  and every yes/no the screens ask. `BeneficiaryPolicy` delegates to it
- **Roles and permissions**: a **Safeguarding Lead** role (every case in
  full, decides, reassigns, records consent, the only export);
  `beneficiaries.view_sensitive`, `.view_financial`, `.audit`, `.export`;
  Finance gets `view` + `view_financial` (approved cases: name, reference,
  amount, account); the Auditor gets `view` + `audit` (the process, never
  the person); Admin keeps Tier A from `programmes.*` with the four new
  permissions negated; a demo `demo.safeguarding@example.test`
- **The Filament resource** (`Programmes → Beneficiary cases`), generated
  from the map: a Tier-A list (Finance sees only payable cases; no bulk
  actions, no trashed filter); **intake** as a staff form with the signed
  data-processing consent uploaded first — the case does not save without
  it, a child cannot consent for themselves, and a matching ID number
  refuses the save until "I have checked" is ticked; the **case page** in
  sections by tier with the sensitive section collapsed, the ID number
  masked with an audited **Reveal**, and the status machine as actions
  (submit, start review, approve, decline with a reason, withdraw, close
  with an outcome, reassign) — each the model's own method; an **edit**
  page holding only the fields the actor may change, in the form and in
  the Livewire state; relation managers for the **case log**,
  **documents**, **consent** and **money paid**. **No delete action of
  any kind**
- **Documents** — `CaseDocuments::attach()` puts a file through
  `MediaLibrary::add(private: true)` (sniffed, sanitised, on the
  `downloads` disk nothing serves) in a locked "Case files" folder;
  `BeneficiaryDocumentController` serves one only on a **five-minute
  signed link**, after the policy's per-document check (medical and
  identity need view C; financial opens to Finance and the Auditor), and
  records `beneficiary.document_downloaded`
- **Audit**: `beneficiary.viewed` once per person per case per session
  at the highest tier shown; every reveal; every download; the export at
  critical with the row count and filters (Tier A columns only, held by
  `beneficiaries.export` directly — the Super Admin does not have it)
- Demo data: six cases in every status, walked through the model so the
  log reads as a real one would; `scghf:launch-check` counts them as demo
  data
- Manual chapter 11, *Beneficiary cases* — who sees what, a new case, the
  case's life, notes/documents/consent, the export; two quick-reference rows
- `tests/Feature/CaseManagementTest.php` — 23 tests: raw-column
  ciphertext, the blind index in three spellings, the log's immutability,
  **the field matrix walked for seven actors from the map**, Finance
  before and after approval, the Super Admin's read-only Tier C, edits
  limited to the actor's fields whatever the request carries, the view
  audit's once-per-session rule, hidden actions, the status machine and
  the absence of delete, signed downloads (unsigned, expired, wrong
  person), sensitive vs financial documents, intake with consent and the
  duplicate refusal, the export's ownership, and that no public
  controller or view names the model

#### Changed

- `BeneficiaryPolicy::view/update/download` follow the relationship to
  the case; `PolicyMap` covers `BeneficiaryNote`
- `Beneficiary::decline()` writes the reason to the log rather than
  appending to `case_notes`; `Beneficiary::payouts()`, `caseWorker()`,
  `notes()` relations
- `docs/SECURITY-MODEL.md`, `docs/DEPLOYMENT.md`, the design note's status
  block (built, assumptions listed, §2 corrected: Admin holds Tier A)

### Wave 1 — PWA, live thermometer, donor portal, case-management design — 2026-09-18

#### Added — W1.1 Progressive web app (`FEATURE_PWA_OFFLINE`, now **on** by default)

- **`App\Support\Pwa`** — the manifest from the settings (`general.short_name`,
  `general.wordmark`, `seo.default_description`, the theme tokens for
  `theme_color` and `background_color`), 192 and 512 px maskable icons
  **rendered with GD from the uploaded light-background logo on a square of
  the primary brand colour** (a brand-coloured tile until a logo is
  uploaded), cached on the local disk under a hash of the logo, its
  `updated_at` and the colour, so a new logo is a new URL; shortcuts to
  `/donate` and `/give`
- **The service worker** (`resources/js/sw.template.js`, served as `/sw.js`
  with the precache list, the never-cache list and the flag's state
  filled in): navigations network-first with the cached page or `/offline`
  as the fallback; hashed build assets and fonts cache-first; same-origin
  images network-first; **never** anything on `performance.page_cache.except`,
  the admin path, `livewire/*`, `donate`, `search`, `screen/*`. With the
  flag off the worker deletes its caches and unregisters itself on the next
  visit, so switching the flag off is the whole rollback
- **`/offline`** — the foundation's own page when the connection drops, with
  the Mobile Money and bank details from *Offline giving* and the phone
  number, so a lost connection is a delayed gift rather than a lost one
- **`resources/js/pwa.js`** — registers the worker only when `<body
  data-pwa="on">`; captures `beforeinstallprompt` and reveals a hidden
  **"Add to your phone"** link in the footer — no banner, no modal
- Routes `pwa.manifest`, `pwa.sw`, `pwa.icon`, `pwa.offline`; `<link
  rel="manifest">` in the layout when enabled; `sw.js`,
  `manifest.webmanifest`, `offline` and `screen/*` excluded from the page
  cache; `pwa_offline` removed from `LaunchChecks::UNBUILT_FLAGS`
- `tests/Feature/PwaTest.php` — 11 tests: manifest from settings, tile and
  logo icons (pixel-checked) and their cache/version, the worker's precache
  and never list and its `ENABLED` state either way, the offline page with
  and without banking details, 404s and no manifest link with the flag off

#### Added — W1.2 Live thermometer

- **`/screen/{appeal}`** (`ScreenController`) — a standalone dark page for a
  projector (`?theme=light` for a bright room): the appeal's title, the
  total in the largest type on the site, the bar and percentage, the gift
  count, the last five gifts by **first name only** (anonymous gifts as
  "Anonymous", no amounts, the donor-wall switch honoured), and a **QR code**
  (`chillerlan/php-qrcode`, inline SVG) that opens the donate page for the
  appeal with `utm_source=screen`. `noindex`; 404 unless the appeal is live
- **`/screen/{appeal}/feed.json`** — the same figures as JSON, cached for
  five seconds under the site generation (which every completed gift bumps,
  so a gift is on the screen at the next poll); `resources/js/screen.js`
  polls it every five seconds, pauses when the tab is hidden, and keeps the
  last good figures on a failed poll
- **Live screen** header action on the appeal's edit page, shown once the
  appeal is live
- `tests/Feature/ScreenTest.php` — 8 tests including the privacy rules
  (first names, anonymity, no amounts anywhere in the feed), the feed
  updating after a gift, the page-cache exclusion

#### Added — W1.3 Donor portal

- **Your impact** (`/account/impact`, `App\Donors\ImpactTimeline`) — every
  completed gift; every appeal update **published after the donor's first
  gift to that appeal** (an update from before they gave is the appeal's
  history, not their story); and, per project behind those appeals, the
  public impact figures **since the first gift**, through
  `ImpactMetric::publishedTotal()` and therefore the same disclosure control
  as the public page — a signed-in donor sees nothing about beneficiaries
  the public cannot. Worded "since your first gift", never "because of"
- **Receipts** (`/account/receipts`) — every receipt filed by tax year with
  the year's total and, where an appeal held a Section 97 approval, the
  deductible total; each opens as the PDF; gifts completed but not yet
  receipted are counted rather than hidden
- Both tabs in the account shell; both behind `auth` + `verified`
- **Subscription pause/resume/change/cancel inside the account** — already
  built in Phase 9 (*Regular giving* tab, `RegularGivingController`); the
  roadmap listed it as missing. Pinned by a test rather than rebuilt
- `tests/Feature/DonorPortalTest.php` — 10 tests; query budgets for both
  pages against the busiest demo donor, and for the screen, feed, manifest
  and offline pages

#### Added — W1.4 Design note

- **`docs/DESIGN-BENEFICIARY-CASES.md`** — what exists (the whole schema,
  the policy, the retention classes, the audit events, the privacy-element
  map), what does not (**the beneficiary columns are not encrypted at rest**
  — Phase 12 encrypted the volunteer equivalents; `beneficiary.viewed` is
  defined and called by nothing), the data list with visibility per role in
  three tiers, the screens (staff intake only, no public form; no bulk
  actions; no delete; signed five-minute document downloads; export for
  the data-protection lead only), the audit granularity, the access-matrix
  test that must exist before the first real record, and nine questions for
  the safeguarding lead and the trustees. Design only; nothing built

#### Changed

- **`ReceiptController`** — the signed-in owner is now the account that
  made the gift **or** the account that has claimed the gift's donor record,
  so a receipt for a gift made from a phone before the account existed
  opens from the archive (it was in the list and refused to open)
- **`ThemeTokens`** — the palette rows are read once per process and memoised
  (the manifest asked five times); `flush()` clears the memo, and the test
  base class flushes it per test
- `config/features.php` `pwa_offline` default `true`; `.env.example` comment
  rewritten; `LaunchCheckTest` now proves the rule with `p2p_fundraising`
- Manual: the live screen under *An appeal*, "What a donor sees when they
  sign in" under *Donations*, the phone icon and offline page under *Theme
  and appearance*, two quick-reference rows

### Phase 18 — Phase-two roadmap, proposed — 2026-09-18

- **`docs/ROADMAP.md`** — the sixteen items in the brief and seven from
  the phases, each scored for value to this foundation, effort in
  developer-weeks at the existing quality bar, risk (money and
  beneficiaries first), what the code already has, and dependencies.
  Notable: beneficiary case management has a complete schema, encryption,
  retention and audit and **no screen** — the highest-value programme
  item and the most sensitive; the PWA, the live thermometer and the
  donor portal upgrades are a week or two each with nothing to wait for;
  USSD and WhatsApp need partners with months of lead time
- Four waves recommended: wave 0 (the first month: Cloudflare and the
  gates from real numbers, no features), wave 1 (PWA, thermometer, donor
  portal; design case management), wave 2 (case management, WhatsApp,
  grants, accounting export, multi-currency display), wave 3
  (segmentation and the lapsed-donor journey, USSD, matching gifts, AI
  drafting, a board view). Multilingual content, a public API, A/B
  testing and foreign-currency charging on triggers
- Six decisions listed for the trustees; four things deliberately not
  proposed. **Nothing built** — the brief says on approval

### Phase 17 — Launch, completed — 2026-09-18

- **`scghf:launch-check`** (`App\Support\LaunchChecks`) — the go-live
  checklist as far as the application can answer it: the ten legal pages
  published, contact details filled, team and trustees with photographs,
  at least three projects and three appeals plus the General Fund, the
  shop stocked or off, **a live gift taken and refunded** on live keys, a
  signed webhook received, **SPF, DKIM and DMARC looked up in DNS** for
  the sending domain, a live SMS delivered, **the certificate's expiry by
  a TLS handshake**, one canonical host, analytics chosen, **feature
  flags honest**, two-factor enrolled on every active staff account, no
  demo accounts, no demo data. Runs `preflight` first; exit 1 on any
  blocker. Offline it says "could not check" rather than failing.
  `LaunchCheckTest` (8) with the DNS and TLS lookups replaced
- **`docs/LAUNCH.md`** — pre-launch by area (content, Paystack, email,
  SMS, domain and SSL, search, backups and cron, security, performance
  and accessibility, rollback tested) each row with an owner and how it
  is checked; the go/no-go; the launch-day cut-over with timings; the
  smoke test; the monitoring window and its stop conditions; the first
  thirty days; the staff training session; the feedback loop; the
  prioritised backlog of everything deferred
- **Flags made honest:** `FEATURE_PWA_OFFLINE` was on with no manifest
  and no service worker behind it — off, and on the Phase 18 roadmap;
  `FEATURE_SITE_SEARCH` was off while the search page was live and
  ungated — on, and `/search` now honours it. The launch check refuses
  any unbuilt flag that is on

### Phase 16 — Documentation and handover, completed — 2026-09-18

#### Technical docs (`docs/`)

- **`ARCHITECTURE.md`** — the module map with counts, the request
  lifecycle middleware by middleware, the services that matter, and
  fourteen design decisions each with its reason
- **`DATABASE.md`** — 135 tables filed under nine modules with every
  column, key and foreign key, and the ER spine in Mermaid. **Generated**
  from the live schema by `docs/tools/schema_reference.py`
- **`DEPLOYMENT.md`** — the shape, a normal release step by step with
  why the order, hotfix, automatic and manual rollback including the
  database, secrets and variables, cron, staging. Built on the way: a
  **pre-deploy `mysqldump`** in `activate.sh` (the rollback script had
  been telling people to restore a dump nothing made) and a
  **preflight step** before the flip, gated by `PREFLIGHT_GATE=1` once
  production is live
- **`PAYMENTS.md`** — the flow as a diagram, every class, the webhook in
  seven steps, every event handled, the three state machines, and the
  eight-row runbook for "the money was taken but nothing was recorded"
- **`ENVIRONMENT.md`** — every `.env` key by section with its comment,
  **generated** by `docs/tools/env_reference.py` with the cross-check.
  The check found `QUEUE_RETRY_AFTER` documented but not read (the
  config read `DB_QUEUE_RETRY_AFTER`; now both) and seven keys read but
  not documented (`PAYSTACK_FEE_FLAT_PESEWAS`,
  `PAYMENT_WEBHOOK_MAX_ATTEMPTS`, the three reconciliation keys,
  `TURNSTILE_VERIFY_URL`, `VISITOR_STATS_ENABLED`, `APP_PREVIOUS_KEYS`)
- **`SECURITY-MODEL.md`** — what is at stake, who can do what, the
  controls by layer, what is deliberately not done, the incident short
  form. A stale cross-reference in the data-protection doc fixed
- **`TESTING.md`**, **`TROUBLESHOOTING.md`** (twenty failures →
  cause → fix), **`OPERATIONS.md`** (what runs itself from the real
  schedule; the daily/weekly/monthly/quarterly/annual calendar; the
  monitoring checklist; escalation roles; a handover checklist),
  **`LICENCES.md`** (fonts, icons, every non-MIT package, services,
  the obligations), **`CONTRIBUTING.md`**
- README reshaped: the documentation map, common commands, layout

#### The admin manual (`resources/manual/`)

- Ten chapters in plain English — signing in and the authenticator;
  header, footer, menus and the homepage; creating each kind of content;
  images, alt text and consent; donations (viewing, export, an offline
  gift, resending a receipt, two-person refunds); orders; newsletters
  and SMS with what they cost; colours and dark mode; what not to touch
  and who to call — and a one-page quick-reference card
- **33 real screenshots** from the demo data, taken by
  `docs/tools/screenshots.mjs` (Playwright, signs in through the real
  two-factor flow); re-run it after any admin screen changes
- **Help & manual inside the panel** (`HelpPage`, *System → Help &
  manual*): the chapters rendered from the same Markdown, images served
  to signed-in staff only. `HelpPageTest` (6). The manual ships with the
  release
- Found on the way and fixed: the **donor's name never showed** on a
  gift's page (a closure parameter named `$s` where Filament injects
  `$state`); the **test-mode band rendered as unstyled text** (utility
  classes Filament's stylesheet does not carry — now inline)

### Phase 15 — Performance and shared-hosting optimisation, completed — 2026-09-18

#### Measured first

- **`QueryBudgetTest`** renders every public page against the demo data
  and holds each to a query budget and to zero repeated statements. The
  layout alone was 20 queries a page: four menus at three each, the
  announcement, three lookups of the cookie-policy page, three `COUNT(*)`
  for the visitor statistics. Home 24, a post 34

#### Caching without Redis

- **`App\Support\SiteCache`** — one generation number that every content
  save bumps (an observer on 36 models, `Settings::flush()`,
  `Cause::recordDonation()`); every fragment and page key carries it, so
  nothing has to know what to forget. Inside a request a second bump is
  skipped while nothing has read the number since the first; a console
  process bumps every time
- Menus, the announcement list and the policy-page links cached as plain
  arrays in the file store and rebuilt with `newFromBuilder()`. Home
  24 → 7 queries, four of which are the statistics upserts
- **`CachePublicPage`** — a full-page cache for anonymous visitors in its
  own file store: public GET pages, no flash, no query string but
  `page=`, never the basket, checkout, account, admin, search or anything
  personal, never a response that sets its own cookie; varies on the
  `scghf_*` cookies; the **CSP nonce and CSRF token are swapped into the
  stored body on every hit**; `X-Page-Cache: hit|miss|skip`.
  `PageCacheTest` (23). Off for the test suite, on by default
- **Found and fixed: the settings cache had stored `Money` objects since
  Phase 2**, which the database store returned as
  `__PHP_Incomplete_Class` (`cache.serializable_classes` is `false`, and
  should stay so) while the array store under test passed them through.
  Settings now cache the stored string and its type and cast per process;
  encrypted settings stay encrypted in the cache table
- `CountVisit` writes its four upserts in `terminate()`, after the
  response has gone; the per-dimension `COUNT` is cached five minutes
- `scghf:cache-clear`, run by the deploy script after the symlink flips
- Site Health: a *Page cache* row and a *File count (inodes)* row (18
  rows now)

#### Queries, memory, the queue

- Related posts in one ordered query; the post and its related load
  their shared relations once
- Maintenance indexes: `activity_log`, `email_logs`, `sms_logs` on
  `created_at`; the webhook tables on `(payload_archived_at, received_at)`
- `ArchiveAuditLog` streams a year through a gzip handle 500 rows at a
  time — the same JSON document, never held whole. `RetentionRunner`
  walks candidates with `lazyById()` and stops one past the ceiling; the
  delivery logs get a ceiling of their own (20,000) so a normal month is
  not an aborted run
- The worker line: `--timeout=50 --memory=128 --sleep=1 --max-jobs=250`
  alongside `flock`, `--stop-when-empty`, `--max-time=55`; explicit
  `$timeout = 45` on both webhook jobs

#### Maintenance and inodes

- **`scghf:db-maintain --execute`** monthly: expired sessions, visitor
  rows over 26 months, stale reset tokens, failed jobs over 30 days, the
  activity log past its window; `OPTIMIZE TABLE` on the churning tables;
  the ten largest tables reported
- **`scghf:archive-webhook-payloads --execute`** monthly: raw bodies of
  processed, year-old webhook events into monthly gzipped JSON-lines files
  with a SHA-256 per body; the rows stay; replay hidden for them
- `DatabaseMaintenanceTest` (4)
- The inode plan and the cleanup order, `docs/PHASE-15-PERFORMANCE.md` §3.6

#### Assets and the report

- The hero's LCP image preloaded from `<head>` for the crop the screen
  will use; everything else in the brief was already built and is
  verified in the doc. Decided against an SVG sprite and inlining the
  stylesheet, with the reasons
- Lighthouse 12 mobile on five pages before and after, the budget, what
  to measure on staging, and the signs and the path for leaving shared
  hosting: `docs/PHASE-15-PERFORMANCE.md`

#### Configuration

`PAGE_CACHE_ENABLED`, `PAGE_CACHE_STORE`, `PAGE_CACHE_TTL`,
`FRAGMENT_CACHE_STORE`, `FRAGMENT_CACHE_TTL` — all read, all documented;
`config/performance.php`; a `pages` store in `config/cache.php`.

#### Tests

New: `QueryBudgetTest` (2), `PageCacheTest` (23),
`DatabaseMaintenanceTest` (4). `AdminExperienceTest` counts 18 health rows.

### Phase 14 — Testing and QA, completed — 2026-09-17

#### Module 1 — the gaps in the automated suite

- **`PaystackClientTest` (25)** — the real Paystack client against a faked
  HTTP layer, for the first time: initialise, verify, chargeAuthorization,
  chargeMobileMoney, submitOtp and refund. What goes on the wire (the
  amount as an integer in pesewas, `currency: GHS` on every call, the
  bearer token, the reference, the callback) and what is read back (the
  amount, fee and channel from Paystack's answer, the allow-listed
  authorisation without the BIN or signature, the scrubbed raw payload, a
  200 with `status: false` as a refusal, one retry, no request without a
  key)
- **`AdminAccessMatrixTest` (17)** — every one of the 51 resources has a
  policy and every custom page its own `canAccess()`; a guest is sent to
  sign in from all 57 URLs; a donor and staff holding only `admin.access`
  get 403 everywhere; a Super Admin gets 200 everywhere; and each of the
  eight staff roles gets exactly what its policies say — Filament is held
  to the policy, URL by URL
- **`SmsGatewayContractTest` (29)** — what the dispatcher relies on from
  any driver: a rejection and never an exception on a dead network, an
  HTML error page or an unrecognised body; the E.164 digits and the whole
  text on the wire; the credential out of the query string; nothing sent
  without a key. **Found:** Twilio marked a 2xx with no message SID as
  sent — now a rejection. **Recorded:** mNotify's API takes the key as a
  query parameter and nothing else (`PHASE-10-SMS-SENDER-ID.md`)
- **`SlugGenerationTest` (29)** and **`App\Support\Slug`** — the 26 models
  with a slug hook shared the same six lines and none of them asked what
  happens when `Str::slug()` returns `''`; a product with an empty slug is
  the shop index. One rule now, and a title that yields no address is
  refused at save. Pages: same slug under different parents allowed, under
  the same parent refused
- Phone normalisation, settings resolution and each SMS driver's own JSON
  were already covered (`MessageTemplatesTest`, `SettingsTest`,
  `SmsGatewaysTest`, `MnotifyGatewayTest`); nothing was added twice

#### Module 2 — a real browser

- **`tests/Browser/CriticalPathsTest` (11)** on `pestphp/pest-plugin-browser`
  (Chromium through Playwright, the app served in-process so the database,
  the fake gateway and the sync queue are the test's own): a donation from
  the form to the thank-you with the ledger agreeing, a declined card,
  a purchase from the product page through the basket and checkout to
  the order page, the theme toggle with persistence across a reload, the
  skip link → main → Donate by keyboard alone, axe on home/donate/shop in
  both themes, and every public form — contact, newsletter, volunteer,
  event — plus a field error tied to its field
- A `Browser` test suite in `phpunit.xml`; the deploy gate and the main CI
  job run `--exclude-testsuite Browser`; a **Browser tests** CI job builds
  the assets, installs Chromium and runs it, keeping screenshots of
  failures

#### Module 3 — demo data and the Paystack run record

- **`DemoDataSeeder`** — nine staff (one per role, password `password`,
  no 2FA yet so the first sign-in enrols), four projects with updates,
  three appeals partly raised, six posts, four products with stock, three
  events, three volunteer roles and four volunteers (not cleared: no
  police check behind them, and the roster says so), trustees,
  testimonials, partners, 36 gifts across six months through the real
  offline-gift service, eight paid orders, twelve subscribers. Idempotent.
  **Refuses production** before touching anything. `DemoDataSeederTest` (3)
- `PHASE-8-PAYMENTS-TEST-PLAN.md` §5 — a run record; no staging run yet,
  and the row says so

#### Module 4 — gates, the plan, triage

- **Larastan** level 5 in CI with a 258-entry baseline
  (`phpstan.neon.dist`, `phpstan-baseline.neon`). Getting there fixed
  what was cheap and honest: `parseModelCastsMethod` so a datetime cast
  is a Carbon to the analyser, the `@return array<string, string>`
  docblock on every `casts()` that hid the keys, `@property` lines for
  the MoneyCast attributes, `self::` for private static helpers,
  `RecordsAuthor`'s hooks typed as `self`, and two `handleRecordUpdate()`
  returns after `halt()`. 905 → 258
- **Coverage** CI job under pcov with a floor (`COVERAGE_MIN`, default
  60 %, to be raised from the first measured run — no driver on the dev
  machine); the HTML report kept as an artifact. The main job runs with
  `coverage: none` now, which it should always have
- `.github/branch-protection.json` requires **Lint, analyse, test**,
  **Coverage** and **Browser tests**
- **`docs/PHASE-14-QA.md`** — what is automated and how to run it; the
  matrix (Chrome/Firefox/Safari/Android/iOS × light/dark × 320–1536 ×
  keyboard × screen reader × 200 % × slow 3G); fifteen journeys as a
  person walks them, each naming its automated twin; the UAT checklist in
  plain English for visitors, staff and the treasurer; load sanity on
  shared hosting (entry processes, not requests per second) with
  `scripts/load/browse.js` and `donate.js` for k6 — the latter refuses
  any environment without the fake gateway; bug triage with four
  severities and what each means for money
- `.github/ISSUE_TEMPLATE/` — bug report and UAT finding forms; blank
  issues off; **`SECURITY.md`** for what must never be an issue
- `DEPENDENCIES.md`: Larastan moved from "under review" to required (the
  brief asked); the browser plugin and Playwright recorded

#### Tests

New: `PaystackClientTest` (25), `AdminAccessMatrixTest` (17),
`SmsGatewayContractTest` (29), `SlugGenerationTest` (29),
`DemoDataSeederTest` (3), browser `CriticalPathsTest` (11).

#### Decided

- **Level 5, not higher**, with a baseline that only shrinks. Level 6+
  is mostly annotating closures Filament passes untyped; the errors worth
  having are all at 5
- **60 % as the first coverage floor** is a floor against drift, not a
  measured figure; the doc says to set it from the first CI run
- **Chromium only** in the automated browser suite; Safari and Firefox
  are the manual matrix's job
- **Demo gifts carry no receipts** — a receipt is an email to an address
  that belongs to nobody
- **Not built:** a `DemoDataSeeder` teardown — the staging database is
  reset by re-seeding, not by deleting demo rows out of a ledger

### Phase 13 — SEO, analytics and accessibility, completed — 2026-09-17

#### Module 1 — search and sharing

- **One "Search & sharing" section on every content type** — pages,
  posts, projects, appeals, products, events, areas of work, volunteer
  roles and both category types (which gain `HasSeo`). Title and
  description with live character counts against what Google shows,
  canonical, robots, Open Graph title/text/image/type, X card. `seo_meta`
  has been polymorphic since Phase 3 and only the Page form reached it,
  with three of eleven columns
- **`PageMeta` carries the overrides it ignored** — `og_title`,
  `canonical_url`, `no_follow`, `twitter_card` — and gained `with()` so a
  controller overrides a slot without rebuilding the object and losing
  the editor's choices on the way (the news page did exactly that)
- **JSON-LD**: Article on posts, Product with an Offer in GHS and stock
  availability, FAQPage, DonateAction on the donate page — alongside the
  NGO, WebSite + SearchAction, BreadcrumbList and Event from Phase 6
- **A sitemap index** with per-type sitemaps, cached per type and
  forgotten by an observer whenever anything listed is saved — so
  "regenerated on publish" is true. Google retired the ping endpoint in
  2023 and nothing pretends otherwise. Extra `robots.txt` lines from a
  setting. A paginated list's canonical keeps `?page=` and drops every
  other parameter
- **Attribution**: first-touch `utm_*` on any page is kept for the visit
  and stamped on the donation or order made later; orders gain
  `source`/`utm` like donations

#### Module 2 — analytics

- A provider as a setting — none (default), Plausible, Umami, or GA4 in
  consent mode — written into the page as `text/plain` and made a script
  only when the visitor allows the Analytics category in Phase 12's
  cookie notice. The CSP admits the chosen provider's origins and no
  other's
- `scghfTrack()`, one shim for whichever provider is loaded, and the nine
  events: donation_started, donation_completed (value in GHS, cause,
  regular), recurring_started, add_to_cart, checkout_started, purchase,
  newsletter_signup, volunteer_application, contact_submitted. The
  server-known ones render on the page that confirms them — from the
  database, never the redirect — and fire once per reference
- **Site analytics** (`visitor_stats.view` — seeded in Phase 3, no screen
  until now): views and visits by day, most-read pages, referrers,
  devices, the conversions as counts from the tables that are them, and
  income by campaign. Nobody outside this database is asked anything

#### Module 3 — accessibility

- axe-core run against 27 public pages in both themes and the open
  dialogs. Found and fixed: a 17 px footer link (WCAG 2.2 target size),
  `h1 → h3` on the projects and appeals indexes (cards take a `level`
  prop), `h1 → h3` on the FAQ page for uncategorised questions, and a
  payment-status reload that announced nothing
- **`AccessibilityTest`** runs the structural rules on 21 public pages,
  the 404 and the account pages on every commit: one `h1`, no skipped
  levels, landmarks, skip link, `lang`, `alt` on every image, a label on
  every control, no positive `tabindex`, no destination-less link, no
  nameless button, a live region, errors tied to fields
- `docs/PHASE-13-ACCESSIBILITY-REPORT.md`; the public statement (draft)
  names the audit and the fixes

#### Module 4 — the plan

- `docs/PHASE-13-SEO-AND-CONTENT.md`: keyword and content plan by intent
  (donation, programme, partner), ten blog topics, NAP consistency,
  Google Business Profile and directories, the Core Web Vitals checklist
  with what is implemented and what is not, and the one-time setup list

#### Tests

`SeoAndAnalyticsTest` (11), `AccessibilityTest` (4). The sitemap tests in
`PublicSiteTest` and `EventPagesTest` updated for the index.

#### Decided

- **Analytics defaults to none.** The built-in dashboard covers the
  director's questions; a third party is a trustee choice, and the doc
  recommends Umami cloud or Plausible over GA4
- **No hreflang** while `FEATURE_MULTILINGUAL` is off
- **No sitemap ping**: Google's is gone; IndexNow is worth adding only
  when the site publishes several times a week
- The admin panel is outside the accessibility audit — Filament's own
- Lighthouse and a screen-reader pass need a person at a browser; the
  report says exactly what to do and what to expect

### Phase 12 — security hardening and compliance, completed — 2026-09-17

An audit of everything built so far, and the hardening it called for.
The findings that mattered most were things that read as done and were
not: a comment saying rich text was sanitised, `users.*` and `consents.*`
permissions protecting nothing, a restore-test method nobody called, a
payment mismatch that was a log line, headers set only on files Apache
served itself.

#### Module 1 — application security

- **`SecurityHeaders` on every response** — the public site and the
  panel's own middleware stack — with a per-request **CSP nonce** on the
  Vite tags and the inline scripts. The public site runs no Alpine and no
  Livewire, so its policy is enforced with no `unsafe-inline` and no
  `unsafe-eval`; the admin panel (Filament) gets the looser policy
  report-only. Origins live once in `config/security.php`; `.htaccess`
  keeps a nonce-less copy behind `setifempty` for files Apache serves
  without PHP. HSTS from `HSTS_MAX_AGE`, when secure. Violations POST to
  `/csp-report` and are logged
- **`@clean` / `App\Support\Html`.** Every rich-text field on the public
  site was printed unescaped because a comment said the editor sanitised
  it. Nothing did. Sanitised on the way out now with Symfony's sanitizer
  (already installed for Filament): an allowlist of tags, no scripts, no
  event handlers, no `javascript:`, images from this origin only
- **Admin sessions:** an absolute timeout as well as the idle one;
  `ADMIN_SINGLE_SESSION` ends every other session on sign-in;
  `ADMIN_IP_ALLOWLIST` (a 404 to anybody else); `TRUSTED_PROXIES` for
  Cloudflare, without which every visitor is Cloudflare
- **Sign out everywhere:** a donor from the security page with their
  password; an administrator for a colleague. Session rows deleted, the
  remember token rotated
- **Staff accounts** as a screen at last. Create (a random password nobody
  knows and a reset email), roles, suspend with a reason, reinstate, and
  reset two-factor with a note of how it was verified — every one audited,
  none on yourself

#### Module 2 — payments

- The webhook already could not be bypassed or replayed. What was missing
  was anybody hearing: **`AnomalyAlerts`** emails the alerts address at
  once for an amount or currency mismatch, and `scghf:payment-anomalies`
  hourly for a run of failed payments or of refunds, one email per window
- `docs/PHASE-12-PCI-DSS-SAQ-A.md`: what is held and where, why both
  Paystack flows are SAQ-A, the annual paperwork, and what would break it

#### Module 3 — data protection

- **Photographs of people.** *Shows a person* / *shows a child* on the
  media record; with either, the image **cannot be published** until a
  valid photo consent is on its Consent tab — for a child, from a named
  parent or guardian with the form attached. Revoking the consent, or
  *Withdraw this image* with a reason, takes it off every page on the next
  request. Nothing deleted; everything audited
- **Your data**, in the account: a copy of everything held (JSON, behind
  the password, third parties left out) and **Delete my account** with the
  statutory carve-out — donations, orders and receipts stay six years
  without the name; sessions ended; newsletter gone; the address suppressed
  so no form puts it back. `scghf:export-data` for an address with no
  account
- **Encryption at rest** for disclosed convictions, next of kin, referees,
  police-clearance numbers and safeguarding concerns; `scghf:encrypt-at-rest`
  re-encrypts rows written before, idempotently
- **The cookie notice**, with a preferences dialog. Essential only by
  default; any later analytics or embed is written as `text/plain` and
  activated only when its category is allowed; the decision timestamped in
  `scghf_consent`. Off by a setting; text from the CMS
- `docs/PHASE-12-DATA-PROTECTION.md`: Act 843 obligations mapped to the
  code, **registration with the Data Protection Commission** (the one
  action item that is an offence to leave), lawful bases, GDPR for donors
  abroad, the retention schedule per table, the DSR procedure, the data
  inventory

#### Module 4 — infrastructure

- **`scghf:restore-test`**: restores the newest backup into a scratch
  database (never the live one — it refuses), counts what came back against
  the live tables, records the test with the verifier's name, wipes the
  scratch. `BackupLogEntry::recordRestoreTest()` was written in Phase 3 and
  called by nobody; Site Health now has a *Restore test* row that goes
  amber after 90 days
- **Sentry** (`sentry/sentry-laravel`, pure PHP) behind `SENTRY_LARAVEL_DSN`,
  errors only, no personal data; the in-app error reports stay. Site
  Health *Error monitoring* row
- **CI fails on a known vulnerability**: `composer audit` and `npm audit
  --audit-level=high`
- `docs/PHASE-12-INFRASTRUCTURE.md`: off-server backups (Backblaze B2 /
  R2), the restore procedure, Cloudflare (DNS, Full-strict TLS, cache
  bypass for anything carrying a session, WAF and rate-limit rules,
  origin restriction), UptimeRobot on `/up`, the incident runbook (site
  down, gateway down, breach, defacement, mail blacklisting), the monthly
  patch routine
- `docs/PHASE-12-SECURITY.md`: the OWASP Top 10 review with findings,
  the headers as sent, the session controls, secrets rotation, and the
  Paystack-key-leak playbook

#### Fixed

- The theme and consent cookies are written by the browser in clear;
  `EncryptCookies` read them as absent, so the server-side theme had been
  falling back to "system" for every real visitor since Phase 4
- `Sessions::revokeAll()` and the single-session mode work whatever the
  session driver, because the table is what decides

#### Tests

`SecurityHardeningTest` (22): headers and CSP, CSP reports, absolute
timeout, sign-out-everywhere, single session, IP allowlist, staff
accounts, the sanitiser, anomaly alerts, photo consent and withdrawal,
export, erasure with the carve-out, encryption and re-encryption, the
cookie notice, `scghf:export-data`, the restore test end to end, the
health rows. Existing suites updated for the encrypted columns and the
unencrypted theme cookie.

#### Decided

- **Virus scanning is not built.** ClamAV is not available on InMotion
  shared hosting. Uploads are staff-only or strictly typed (image/PDF); a
  scanner needs a VPS or a paid scanning API — a trustee decision, recorded
- The admin panel's CSP stays report-only: Filament needs `unsafe-eval`
- `APP_KEY` is never rotated; the encrypted columns and 2FA secrets depend
  on it. The rotation table says so and why
- HSTS ships at `0` until every subdomain is confirmed https; the doc
  gives the two-step ramp
- The cookie notice is honest rather than performative: the site sets
  essential cookies only, and the banner says so. The gate exists for what
  comes later

### Phase 11 — engagement, completed — 2026-09-17

Volunteers, events, the newsletter, contact and partnerships were all
built in Phase 6. This phase is the gap between what was built and what
the brief asks for — and, as in every phase, the things seeded earlier
with nothing behind them.

#### Module 1 — volunteers, after the application

- **Two referees on the application** (name, relationship, phone or
  email), required for a vulnerable-contact role. Two of the safeguarding
  checks are "reference taken up"; nobody could take one up because the
  form never asked who the referees were. Skills offered on the
  application; skills needed as tags on the role, shown on its page
- **Shortlisted** and **interviewed** as stages between review and
  approval — the brief's `new → shortlisted → interviewed → approved` —
  each an action on the application and a message to the applicant
  (`volunteer.shortlisted`, `volunteer.interview` with the date and place,
  email and SMS). Approve and decline work from any open stage
- **Volunteers** as a screen. The `Volunteer` model has existed since
  Phase 3 with no way to see one. Profile, clearance re-check (re-reads
  the checks; nobody ticks "cleared"), concern raise and close, inactive
  and active, and **leaving**, which closes the record and sends
  `volunteer.thank_you` with the verified hours they gave
- **Hours** on the volunteer: `volunteers.log_hours` — seeded in Phase 3
  protecting nothing — records an entry; `volunteers.manage` verifies it,
  and never the person who recorded it. Only verified hours reach any
  total
- **Shifts**: a volunteer, a start, an end, a place. Completing one writes
  the hours entry once, recorded by whoever planned it and verified by
  whoever confirmed it. `scghf:shift-reminders` at 17:00 sends
  `volunteer.shift_reminder` (email and SMS) the evening before — the
  reminder Phase 10 deferred here. A suspended or lapsed volunteer is
  neither rostered nor reminded
- Verified volunteer hours and active volunteers on the public impact
  page, by the same rule as the admin total
- `docs/PHASE-11-SAFEGUARDING.md`: what the code enforces before anybody
  works with children, and the five decisions it leaves to the trustees
- The site's form field component understands array names
  (`referees[0][name]`), so errors and old input land on the right box

#### Module 2 — events

- **`scghf:event-reminders`** at 17:10: the day before, email and SMS to
  everybody registered who agreed to be contacted about the event,
  expiring at the event start so a backlog cannot deliver it afterwards.
  `event.reminder` was seeded in Phase 3 and nothing sent it; the email
  version is new
- **QR-coded tickets.** A ticket is a page now — signed, no expiry, one
  code names one ticket — with a QR that opens the door screen for
  exactly that code, the code printed large under it, and the same square
  as an SVG. Every ticket in `order.tickets` links to its page.
  `chillerlan/php-qrcode` was already installed for two-factor
- **The door**: one admin page, one box, one button. A ticket's QR opens
  it with the code from a steward's phone camera; a code read out is
  typed; a free event's registration reference works at the same door.
  `events.view_registrations`, like the door list
- **The archive**: a past event shows a headcount from the day, what came
  of it, and a gallery from the media library — whose consent flag governs
  whether faces appear

#### Module 3 — lead capture and contact

- **The newsletter popup**, as a `<dialog>` so the browser traps focus and
  handles Escape and the backdrop. Exit intent on a laptop (the cursor
  leaves through the top); on a phone, after a delay and half a page of
  scrolling. Once per `frequency_days` (localStorage), never after
  subscribing (a one-bit cookie set by the subscribe handler), never
  rendered on the donation, basket, checkout, account or sign-in pages.
  **Off by default** — heading, text, delay and frequency are settings
- Subscribers record their source (footer, block, popup)
- **`scghf:contact-sla`**, hourly: an enquiry past its department's reply
  target emails whoever owns it, else the department mailbox, once.
  `ContactMessage::isOverdue()` has driven a badge since Phase 5 and told
  nobody
- **Offices**: every place with a door — address, Ghana Post GPS, hours
  as text per day ("by appointment" is a real answer), phone, WhatsApp
  deep link, directions — listed on the contact page, edited under
  `settings.manage`. The first one is seeded from the contact settings,
  once; the settings stay the header's and footer's source

#### Module 4 — partnerships

Already built in Phase 6 and checked against the brief: the partner,
corporate, in-kind and community-fundraising enquiry forms as page blocks
on the seeded "Partner with us" page, filed to the right department; the
partner logo wall from the CMS. Nothing to add.

#### Fixed

- **The in-content newsletter block was dropping every signup.** It
  posted to the honeypot-protected subscribe route without the honeypot
  fields, so the spam middleware rejected each one silently. The footer
  form had the fields; the block did not
- `VITE_APP_NAME` removed from `.env.example`: read by nothing

#### Tests

`VolunteersTest` (10), `EventEngagementTest` (6), `LeadsAndContactTest`
(5). Existing volunteer and event suites updated for referees.

#### Decided

- Venue "map" stays a directions link, per Phase 6: a map widget is a
  third-party script and a few hundred kilobytes on 3G for something
  people tap once
- Paid tickets stay behind `FEATURE_EVENT_TICKETING` (off). Ticket
  types, quantity limits, promo codes (shop coupons), QR tickets, the
  door and reminders are all built and tested behind it; the flag is the
  foundation's decision to sell tickets
- Shifts are the minimum that makes hours logging concrete and gives the
  reminder something to remind about — not a rota, no self-service
  sign-up. A volunteer portal is post-launch (Phase 18)
- Volunteer recognition is the hours on the impact page, the summary on
  the record, and the thank-you on leaving. Certificates and badges are
  not built: a letter on request is what the thank-you offers
- The offices table does not replace the contact settings. Two sources
  for the primary office's address is a known cost; making the header
  and footer read the table instead is a small Phase 12 change if the
  foundation wants it
- The popup is off. Whether a charity's site should ever put something
  in a visitor's way is the trustees' call, and the setting is theirs

### Phase 10 — email and SMS, completed — 2026-09-13

Phase 3 built the engine: templates, an outbox with quiet hours and a
throttle, a suppression list, a delivery webhook, mNotify. Nothing reached
it from a screen and no cron line drove it. This phase is the screens, the
cron lines, the providers, and the decision about where mail leaves from.

#### Module 1 — the templates

- Email and SMS templates editable in the admin (`templates.edit`), with a
  menu of the variables each template declares, validation that refuses a
  placeholder nobody provides, a **preview inside the real mail layout**
  with sample values (`SampleVariables`), and a **send-a-test** that goes
  through `MessageDispatcher::sendEmailNow` — the real path, not a shortcut.
  SMS templates have a live meter (encoding, characters, segments, the
  characters forcing UCS-2) and are refused past the segment budget.
  Templates are never created from the screen; the seeder owns the set
- The mail layout redrawn from the theme tokens: logo from
  `header.logo_light`, light and dark palettes, a `prefers-color-scheme`
  block, the unsubscribe and preferences links. The plain-text layout gets
  the preferences line

#### Module 2 — the newsletter

- Campaigns composed from six email-safe blocks (heading, paragraph,
  button, image, divider, **appeal** — drawn live from the appeal's progress
  at send time), compiled by `CampaignComposer` into table-based HTML and a
  plain-text twin on save. Preview, send-a-test, build the audience,
  approve (a second person, `newsletter.send`), schedule, pause, resume,
  cancel — each a gate, each audited
- `scghf:send-campaigns` every five minutes, in batches sized by the mail
  throttle, marking a campaign failed **with the reason** when its gate
  fails rather than leaving it "sending" for ever
- Subscribers as a screen with their consent evidence (when, from where,
  which IP), resend confirmation, unsubscribe, and **Erase**, which
  suppresses the address as an erasure and audits `erasure.completed`
- A preference centre on the unsubscribe token — choose topics, stop
  everything, come back — with no account
- Open and click tracking: **off** by default (`communications.tracking.*`),
  marketing mail only, never a receipt; the click redirect is signed
- **Fixed:** `NewsletterPolicy` looked for `newsletter.create`, a
  permission that does not exist, so nobody but Super Admin could have
  opened the composer. It now maps to `newsletter.draft`

#### Module 3 — the SMS providers, the broadcast, the alerts

- **Arkesel, Hubtel and Twilio** behind the same `SmsGateway` contract as
  mNotify, each reporting acceptance (not delivery — the webhook does
  that), Arkesel and Twilio reporting a balance through the new
  `ReportsBalance` contract in **their own unit** (credits or money), so
  the health page and the low-credit alert say "1,240 credits" or
  "USD 12.40" rather than pretending both are pesewas. Chosen in *Settings →
  Email & SMS*, with `SMS_DRIVER` as the default
- `scghf:sms-balance` at 07:05 daily emails `sms.low_credit` to the alert
  address, once a day at most
- **SMS broadcast**: a text to every donor who ticked SMS updates, or to
  pasted numbers (normalised, deduplicated, suppression applied). The
  screen shows segments × recipients × rate **before** anybody presses
  send; the drafter cannot approve; approval queues one outbox message per
  number with an idempotency key, so the ordinary throttle, quiet hours and
  suppression list apply to every one of them. `sms.broadcast_sent` audited
- **Suppressions** as a screen: the do-not-contact list with the provider's
  reason, add by hand, release only with `suppressions.release` and a
  reason
- The three admin alerts the brief named and Phase 3 seeded templates for
  with nothing sending them: `contact.admin_alert` to the department's
  mailbox on a new enquiry, `admin.new_donation` above a settable amount,
  and `admin.weekly_summary` on Monday at 07:00 (`scghf:weekly-summary`)
- `docs/PHASE-10-SMS-SENDER-ID.md` — registering the sender ID with the
  NCA through the provider, and the provider comparison

#### Module 4 — reliability without a terminal

- **The worker has a pulse.** `Queue::looping` writes a timestamp on every
  pass; the health page reads it — *alive*, *last seen 40 minutes ago*, or
  *never seen*. An empty queue used to look the same whether the cron line
  existed or not
- **Failed jobs** as a screen (`queue.manage`): the job's class, the first
  line of the exception, retry and discard through Laravel's own
  `queue:retry` / `queue:forget`, audited
- **The outbox** (`messages.view`, `messages.cancel`): every waiting,
  claimed, sent, failed, suppressed or expired message, the last error, the
  hourly allowance in the heading, cancel with a reason
- **Email and SMS logs** (`logs.email.view`, `logs.sms.view`) as lists and
  views. The email body shows only where it was stored; otherwise the
  screen says it was not, rather than showing nothing. The SMS list heads
  with the month's estimated cost

#### Module 5 — where mail leaves from

- **Decision: Resend**, over its API, from `mail.greaterhopefoundations.com`
  with SPF, DKIM and DMARC. `resend/resend-php` installed — the transport
  ships in Laravel but does nothing without it. Reasons, the comparison
  with Postmark, Brevo, Mailgun and cPanel SMTP, the DNS records, warm-up,
  and the setup in order: `docs/PHASE-10-EMAIL-DELIVERABILITY.md`
- **The Resend webhook verified the way Resend signs it.** Phase 3's
  verifier computed a hex HMAC over the body; Resend uses Svix — a
  `whsec_` base64 secret, `{id}.{timestamp}.{body}` as the signed string,
  several `v1,…` signatures during rotation, and a five-minute tolerance
  that stops a genuine old bounce being replayed to re-suppress a released
  address. `DeliveryEventProcessor` now reads Resend's shape too (`type`,
  `data.to[]`, `data.email_id`, `data.bounce.type` — Permanent suppresses,
  Transient does not)
- **Email sending** row on Site Health: *Resend* / *Resend chosen, no key*
  (critical) / *Not sending (log)* (critical on production) / *cPanel SMTP*
  (warning: the shared IP) / *SMTP relay*
- **The `.env.example` sweep** — every documented key is now read by
  something, or gone. Gone: `POSTMARK_TOKEN` (the config reads
  `POSTMARK_API_KEY`), `BREVO_API_KEY`, `MAILGUN_DOMAIN`, `MAILGUN_SECRET`,
  `MAIL_ENCRYPTION` (Laravel 11+ reads `MAIL_SCHEME`), `ALLOW_SEARCH_INDEXING`
  and `VITE_ALLOW_INDEXING` (indexing has been the `seo.allow_indexing`
  setting since Phase 6), `SITE_ANALYTICS_*` (nothing built — recorded as an
  open question), `SITEMAP_ENABLED`, `CURRENCY_CODE`/`CURRENCY_SYMBOL` (GHS
  is fixed in `Money`), `S3_ENABLED` (the switch is the disk name). Wired:
  `MAIL_REPLY_TO_ADDRESS` now the default Reply-To on every message
  (`config/mail.php` `reply_to`), `APP_RELEASE` shown on Site Health →
  Environment and stamped into `shared/.env` by `activate.sh` on every
  deploy. `SMS_DRIVER` lists all five drivers

#### Tests

`CommunicationsAdminTest` (11), `SmsGatewaysTest` (9),
`QueueReliabilityTest` (7), `DeliveryWebhookTest` +4 for Resend.

#### Decided

- Bounce webhooks for Postmark and Mailgun: the endpoints exist from
  Phase 3 but neither provider signs the way the generic verifier expects
  (Postmark: HTTP Basic credentials in the URL; Mailgun: a signature inside
  the JSON body). Neither is the provider in use, so neither verifier was
  written; an event from either is stored and never acted on, which is the
  safe direction. Recorded in the deliverability doc as the work needed if
  the provider ever changes
- Volunteer shift reminders by SMS wait for Phase 11, which owns shifts.
  There is no shift table yet to remind anybody about
- SMS one-time codes for account actions were not built. Every admin
  account already has TOTP; an SMS second factor is weaker than the one in
  place and would spend credits on every sign-in
- Web analytics: no provider chosen, nothing built, and the two `.env`
  keys that implied otherwise are gone. When one is chosen it is a setting
  (the cookie policy already describes the category), not an `.env` key
- Newsletter list cleaning (drop addresses that have not opened in a year)
  is not built: with tracking off by default there is no open data to
  clean by. Bounces and complaints clean the list instead

### Phase 9 — the shop, completed — 2026-09-13

The catalogue, basket, checkout, orders and admin were built in Phase 6.
This phase is what the brief asked for and Phase 6 did not have.

#### Module 1 — the catalogue

- **Four kinds of product, four consequences of paying.** A *physical*
  product is packed. A *download* becomes a token — sixty-four random
  characters, expiring after the product's number of days, refusing after
  its number of uses, counted with the address it went to — emailed as
  `order.download`; the file lives on a new `downloads` disk that nothing
  serves but `DownloadController`, because a paid file one guessed path from
  free is not a paid file. A *gift* ("sponsor a meal") becomes a real
  donation to the product's appeal the moment the order is paid — receipted,
  matched to the donor, counted as giving, and never counted again as shop
  sales. A *ticket* becomes one code per admission (`issued_tickets`), a
  registration found or made for the buyer, and `order.tickets`; a door list
  on the event checks codes in. Each is issued exactly once however often
  settlement is replayed
- Specifications as label/value pairs, dimensions, editor-chosen related
  products (falling back to the category's neighbours), bulk quantity breaks
  and a signed-in price — the customer gets the lower — decided by the basket
  and snapshotted onto the order line, so the order's lines add up to what
  was charged
- `scghf:stock-alerts`, 07:00: one digest to the shop email listing what has
  fallen to the low-stock level, each item once until it is restocked
- Three more order states — packed, out for delivery, completed — and a
  customer sentence for every state

#### Module 2 — the checkout

- The rest of a Ghanaian address: town or district, area, house or
  directions, the nearest landmark, the GhanaPost GPS code (validated as
  `GA-184-3456`), printed as one line a courier can use. Collection points
  carry an address, hours and a phone, shown at checkout, on the order and
  in the confirmation
- **A gift at the last step.** Chips computed on the server — round the
  basket up to the next GH₵ 10, 50 or 100, or add GH₵ 5, 10, 20 — that
  become a donation to the General Fund when the order is paid, receipted
  separately and kept off the invoice, which lists goods. Switchable
- A basket of downloads, tickets or gifts asks for no address and is
  completed the moment it is paid
- **Finding an order.** `/shop/orders/track`: reference plus the email or
  phone it was placed with, refused as one sentence whichever half is wrong.
  Every order link in every email is signed for ninety days; the order page
  refuses a bare URL from anybody but the account or the browser that placed
  it, because a ULID is unguessable and unguessable is not a permission
- An abandoned-checkout reminder, **off** by default, once, and only to
  somebody who has agreed to email from the foundation — a checkout tick is
  consent to hold details for the order, not to be written to afterwards

#### Module 3 — managing orders

- Every change of state after payment sends `order.status` — the frame from
  the CMS, the sentence from the state — and an SMS when a courier is coming
  or the parcel has arrived. Dispatch keeps its own message with the courier
- **The paper.** An invoice PDF, rendered from the frozen invoice row, goods
  only, cached under its number on the private disk, linked (signed) from the
  confirmation and downloadable from the order in the admin. A packing slip —
  address, landmark, GPS code, phone, notes, a box to tick per line, and no
  prices, because a slip in a box that turns out to be a present should not
  say what it cost — for one order or, from the order list, a selection as
  one file with a page each. Both audited as exports
- **Refunds from the order.** Requested on the order (`orders.refund_request`),
  approved by a second person on the Refunds screen, sent to the gateway, and
  when the gateway confirms a full refund the goods go back on the shelf as a
  `return` movement with the order reference — once, whatever the webhook does

#### Module 4 — the numbers

- Shop → Reports: goods sold, delivery, discounts, fees and net proceeds;
  by day, week or month; by product, by category, best sellers by units,
  goods revenue by appeal; the shelf valued at selling price (and saying so);
  refunds on their own line; and **total funds raised** — donations plus net
  shop proceeds — with the two halves shown so nobody adds them again.
  `ShopReports` computes in integer pesewas in SQL and is tested on its own
- A door list on each ticketed event: search by code or name, check in,
  undo within ten minutes

#### Fixed

- **⚠ An order with two lines could not commit its stock.** `holdStock()`,
  `commitStock()` and `releaseStock()` read each line's variant lazily, which
  strict mode refuses for a collection — and every test had ordered one
  thing. Loaded up front
- The invoice listed gifts as goods and the confirmation said no receipt
  would be issued for any of it; both now say goods, and a gift made through
  the shop is receipted separately

#### Decided

- Product types are the four in the brief. "Configurable statuses" is the
  fixed set with a CMS-editable message per state, not user-defined states:
  stock and refunds depend on what the states mean
- Net shop proceeds are goods plus delivery less discounts and gateway fees.
  There is no cost price on merchandise, so this is what reached the bank,
  not a profit; the report says so
- A member price is for any signed-in customer. There is no membership
  scheme to be stricter about
- Tickets stay behind `FEATURE_EVENT_TICKETING` (off). The type, the codes,
  the door list and the email exist and are tested; the flag is the
  foundation's decision to sell tickets, which nobody has taken yet

### Phase 8 Module 2 addendum — popup checkout and Turnstile — 2026-09-12

Two decisions taken by the foundation at the Module 2 close-out.

#### Added

- **The popup checkout**, configurable. Settings → Donations → *Card
  checkout* chooses between Paystack's page (the default, unchanged) and a
  window over our own. In popup mode the transaction is still initialised
  server-side with our amount, currency and reference; the new
  `/donate/{ulid}/pay` page resumes it from the access code with Paystack
  Inline v2, so nothing the browser can edit decides what is charged. Success
  goes through the same verifying callback as the redirect flow; closing the
  window leaves the gift pending with a button to open it again. A GET, so a
  refresh cannot start a second payment; settled gifts redirect to the
  thank-you. Without JavaScript, or with the fake driver, the page is a
  summary and one button to the gateway's own page
- **Cloudflare Turnstile on the donation form** — the one form that is a
  card-testing target — behind the honeypot and the throttle. Drawn only when
  both `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` are set, in managed
  mode (invisible to most people), following the site's theme. A token
  Cloudflare rejects is refused; a verification that cannot complete because
  Cloudflare is unreachable is allowed through with a warning in the log,
  because an outage in their network must not stop a gift in Tamale. Not on
  the contact form or the newsletter, deliberately

#### Decided

- Regular giving stays on stored authorisations charged by our own daily
  command, not Paystack plans; `subscription.*` and `invoice.*` webhooks are
  stored as evidence and not acted on. Confirmed by the foundation

### Phase 8 Module 2 — the finance admin — 2026-09-11

Nothing here edits money. A gift's amount is written once, by the gateway,
and never by a form; the screens request, approve, reconcile, resend, replay
and report, and each of those is a row in the audit trail.

#### Added

**Finance, a new navigation group**
- **Donations** — searchable, filterable by status, appeal, channel, method
  and date, exportable (donor columns only for `donations.view_pii`). One
  gift shows the money as the gateway reported it (asked, settled, fee,
  net), the donor (contact details behind `view_pii`), the receipt, the
  transaction, every webhook received for it, the refunds, the activity
  trail, and the gateway's response with the authorisation code and anything
  card-shaped removed. Actions: check with the gateway, resend the receipt
  (a fresh idempotency key, so the outbox does not refuse it as a
  duplicate), mark reconciled, request a refund, add a dated and signed note
- **Record an offline gift** — cash, cheque, bank transfer, goods — through
  `OfflineDonationService`, never `Donation::create`, so it is allocated,
  snapshotted for deductibility, given an `offline` transaction row that
  reconciliation knows not to ask the gateway about, and receipted. A cheque
  needs its number; nothing can have arrived in the future
- **Donors** — lifetime value, first and last gift, average, frequency, what
  was sent to them and whether it arrived, and consent per channel. Editing
  a consent **on** here is not consent and the form says so; **off** is
  honoured at once. **Merge a duplicate** (`donors.merge`, seeded since
  Phase 3 with nothing behind it): gifts, regular gifts, pledges and
  sponsorships move to the survivor, blanks fill from the duplicate, filled
  fields are never overwritten, consent comes with its evidence or not at
  all, totals are recounted from the donations table, and the duplicate is
  removed with a note saying where it went. **Tags** — "church network",
  "gala 2026", "major donor" — through the `taggables` table posts already
  use; a column, a filter and therefore an export on the donor list, and
  they travel with a merge
- **Regular gifts** — status, next charge, charge history, failures;
  pause and stop with a reason, resume, charge now for a missed date.
  Changing the amount is deliberately absent: it is the donor's, from
  their signed link or their account
- **Refunds** — the queue awaiting approval, **Approve and send** for a
  different person than the requester (the model refuses; the screen shows
  the refusal), cancel. `refund.processed` and `refund.failed` are now
  audited when the gateway answers, which the definitions in
  `config/system.php` had promised since Module 1
- **Webhook events** — every delivery with its signature verdict, processed
  state and error; **Replay** re-runs the handler synchronously for a valid
  event (`payments.replay_webhook`) and counts nothing twice; an invalid
  signature has no button
- **Reports** — one window, every cut: raised, net of fees, average gift,
  refunded; by day, week or month; by appeal, channel, region of the work
  (through the appeal's project) and source; new against returning donors;
  regular giving's monthly value, starts, stops and retention; and what has
  settled but not been reconciled against a payout. `GivingReports` computes
  in integer pesewas in SQL and is tested on its own; the page only draws
  it. **Run reconciliation now** runs the 06:30 job on demand
- **Test mode versus live mode, on every admin page.** An amber band for
  Paystack test keys, a dark band for the fake gateway, nothing for live —
  so a permanent "all is well" strip never trains anybody to stop reading
  strips, and a trustee cannot report sandbox gifts as income. `PaymentMode`
  reads the driver and the key prefix exactly as Site Health does

**Access to personal data is now recorded**
- `donor.pii_viewed` and `volunteer.pii_viewed` were defined in the audit
  vocabulary since Phase 3 and written by nothing. Opening a donor record,
  a gift with the donor's contact details shown, or a volunteer application
  with the applicant's details shown now writes one — only when the details
  were actually shown, so somebody who may see a gift but not the donor has
  not accessed them

**The tribute is told**
- `tribute_notify_email` has been collected by the form since Phase 3, with
  the promise "we will let them know a gift was made — never how much", and
  read by nothing. `donation.tribute`, new, goes to the person named once the
  money is in, saying who gave (or "Someone", for an anonymous gift), in
  memory or in honour of whom, and towards what. The amount is not a
  variable the template can reach

**The donation widget is a form**
- The `donation-widget` block had been a heading and a link since Phase 5.
  It is now the first step — amount chips, another amount, once or monthly,
  optionally pinned to an appeal — as a plain GET to the donation page,
  which arrives with those choices made. No card fields anywhere but the
  gateway

**Documentation**
- `docs/PHASE-8-PAYMENTS-TEST-PLAN.md`: the fake gateway's rules, Paystack
  test cards and mobile money, forging a webhook, duplicate and out-of-order
  delivery, replay, every failure simulation, reconciliation, and the one
  live cedi

#### Fixed

- **⚠ A chosen preset amount was blanked by the empty "another amount" box.**
  Both inputs were named `amount`; a browser sends both and the later, empty
  one wins, so a donor who picked GH₵ 50 and typed nothing was told they had
  entered no amount. The box is `amount_other` and overrides only when
  something is typed. The automated tests posted arrays directly and never
  met the browser's behaviour
- `PaymentPolicy` looked for `payments.view`, which does not exist; the
  permission is `payments.view_transactions`. Without the override the
  Refunds and Webhook screens would have been open to Super Admin alone
- The gateway's raw response was shown to anybody who could open a gift,
  authorisation code and customer email included, under a heading that said
  "scrubbed". `PayloadScrubber::forDisplay()` removes the code and the
  signature always and the contact fields unless the viewer may see them;
  the same goes for the refund and webhook screens
- The page state Filament serialises into the HTML carried the donor's email
  and phone even when the entries showing them were hidden. Hidden is not
  withheld; they are stripped before the state leaves the server
- `Donor::mayBeEmailed()` had `recalculateTotals()`'s docblock and vice versa
- **⚠ The deploy never published Filament's own CSS and JS.** They come from
  `php artisan filament:assets`, not Vite, and `public/css`, `public/js` are
  ignored by git; nothing in `deploy.yml` or Composer ran it, so the first
  production deploy would have served an admin panel with no stylesheet and
  no script — every button dead, every page returning 200. Composer's
  `post-autoload-dump` now runs `filament:upgrade`, the deploy verifies the
  files exist before assembling the release, and `public/fonts/filament/` is
  ignored alongside the rest

### Phase 8 Module 1 — the giving flow — 2026-09-11

Most of the Phase 8 engine has existed since Phase 3: one transaction table,
one webhook handler, HMAC-SHA512 over the raw body, the raw event stored
before processing, idempotent settlement, amount re-checked against what was
expected. This module is what the donor and the ledger were still missing.

#### Added

**On the form**
- Frequency — once, weekly, monthly, quarterly, yearly — as chips, replacing
  the monthly tick box. The subscription is still established only when the
  first payment is confirmed, and it inherits the interval the donor chose.
  Weekly is new to the subscription model, because it is how a market trader
  budgets
- **Direct Mobile Money.** "Prompt to my phone" charges the wallet through
  `POST /charge` and leaves the donor on our page, which says what to do in
  the network's own words — approve the prompt, or type the voucher code
  Telecel texts — and asks the server every few seconds whether the money
  landed. Without a script the page still works: every load verifies the
  transaction with the gateway. The fake gateway reaches every state: a wallet
  ending 00 wants a code, one ending 99 is declined, and the sandbox page
  stands in for the handset
- A public message for the donor wall, an optional postal address (filled
  onto the donor record, never overwriting one), and a summary line — "GH₵ 50
  → School kits, every month" — drawn by a few lines of script and absent
  rather than wrong without one
- Phone numbers normalised to `+233…`, so `024…` and `+233 24…` are one donor
  and one SMS destination
- Attribution: `?source=` and `utm_*` on the link that brought the donor are
  carried through the form and stored on the gift and in the gateway's
  metadata, with the donation, cause and donor ids, so a Paystack export can
  be joined to ours and the foundation can tell whether the radio advert or
  the church notice raised more

**After paying**
- **The receipt as a branded PDF** (DomPDF — pure PHP, so it runs on the
  shared host), rendered from the frozen receipt row and never from the live
  donation, kept on the private disk and served only through a signed link
  that expires — in the receipt email and on the thank-you page — or to the
  signed-in donor it belongs to. Every download is audited as an export
- The thank-you page offers the receipt, a WhatsApp share of the appeal (not
  of the donor's page), and two soft asks: make it monthly, and create an
  account with the email already filled in
- The callback verifies with the gateway before landing, so a donor sees
  "thank you" rather than "confirming" for the minute the cron queue takes —
  through exactly the path the webhook uses, and a second settlement is a
  no-op
- `donation.failed` — seeded since Phase 3 and never sent — goes out on a
  decline with a link back to the form, amount and appeal filled in.
  `donation.abandoned`, new, follows up somebody who reached the payment page
  and left; **off** unless `donations.abandoned_followup` is switched on, and
  only to a donor who consented to email

**Regular giving**
- `/account/giving` and a **signed management link** in every recurring email,
  valid sixty days and needing no account: pause, resume, change the amount
  (down as well as up), stop. Nothing changes on a GET; every change posts to a
  signed URL of its own
- Dunning in the tone of a thank-you: `recurring.failed` (email and SMS) when
  a charge fails and will be retried, `recurring.paused` after
  `RECURRING_MAX_FAILURES` in a row — "we have stopped trying; start again when
  you are ready" — and `recurring.established` when it is set up

**Refunds**
- `RefundService`: requested by one person, approved by another (the model
  refuses the requester as approver), sent to the gateway, and the ledger
  moves only when the gateway confirms — by API answer or by the
  `refund.processed` webhook, whichever arrives. A full refund marks the gift
  refunded; either kind recomputes the appeal's total and the donor's lifetime
  figures from the donations table rather than decrementing them

#### Fixed

- `PAYSTACK_WEBHOOK_IPS` has been read into config since Phase 3 and consulted
  by nothing. When set, a delivery from any other address is stored as
  evidence and never processed
- `refund.processed` and `refund.failed` webhooks were stored and ignored

### Phase 6 Module 8 — the responsive and performance pass — 2026-09-11

Every new page checked at 320, 375, 768, 1024 and 1440px, in both themes.

#### Fixed

- **⚠ Rich text had no typography.** `prose-scghf` was referenced by seven
  views from Module 1 onward and defined nowhere, and the `prose` class the
  rich-text block used belongs to a Tailwind plugin that is not installed.
  Tailwind's preflight strips bullets, heading sizes and paragraph margins from
  everything, so every news post, project description, appeal description and
  policy page rendered as a run of same-sized text with no bullets. Nothing
  failed; it read like a telegram. `prose-scghf` now exists, in plain CSS on
  the theme tokens, with a table style for the cookie list that scrolls inside
  its own box on a phone
- The desktop navigation now appears from `lg`, not `md`: at 768px eight
  items, a theme control, a basket and a donate pill wrapped onto two lines
- On a phone the checkout shows the order summary and the delivery charges
  **before** the form rather than after it, and the policy links read as a
  sentence however many of the policies are published; product grids are two
  columns on a phone rather than one full-width card per product

#### Confirmed against the budget

- Homepage JavaScript is 1.1 KB gzipped against a 100 KB budget; the
  stylesheet is 10 KB gzipped. Every image on the site carries width and
  height, lazy-loads below the fold and is `fetchpriority="high"` above it;
  every form and every navigation works with JavaScript off; every animation
  respects `prefers-reduced-motion`; the sticky header keeps the Donate button
  reachable at every width, which is the floating mobile button the brief asked
  for without a second control

#### The brand typefaces, self-hosted — added after the close-out

- Inter (400–700) and Plus Jakarta Sans (600–800) as variable WOFF2 files in
  `public/fonts/`, latin and latin-ext subsets, SIL OFL. The theme tokens
  `--font-body` and `--font-heading` have named them since Phase 4 and nothing
  applied them; `body` and the headings now read the tokens, so the foundation
  can change the typeface in the theme editor. `unicode-range` keeps the
  latin-ext file to pages that need it — ɛ, ɔ and GH₵ are all in it — and
  `font-display: swap` puts the words before the font on a slow connection.
  The body face is preloaded; nothing else is
- Dynamically generated share images for appeals now have a font to draw with
  and move to Phase 13 (SEO)

### Phase 6 Module 7 — the legal pages — 2026-09-11

#### Added

- A first draft of every policy page — privacy, terms, donation policy,
  refunds, delivery, cookies, safeguarding, accessibility, raising a concern —
  and the **anti-fraud statement** the brief asked for, which had not been
  seeded at all. Each is written from what the application actually does: the
  cookie policy lists the cookies the code sets and nothing else; the privacy
  policy names Paystack, the retention periods in `config/compliance.php`, and
  the safeguarding checks the software enforces; the refund policy exists
  before the live payment keys do, because the merchant profile links to it
- The drafts live in `database/seeders/content/legal/*.html`, where the
  trustees can read and mark them up without touching PHP, and are seeded by
  `PageContentSeeder` only into a page with no sections. **Every one stays a
  draft** and opens with a notice saying so, as part of the content, so it
  cannot be published without somebody deleting the notice on purpose. A
  policy is the trustees' undertaking; publishing it is their decision
- The anti-fraud statement is added to the seeded legal footer

#### Fixed

- **A CMS page that did not open with a hero or page-header block had no h1
  and no breadcrumb.** A policy that is one rich-text block rendered with no
  heading at all — the easiest accessibility mistake to make when a page is
  assembled from parts, and the one a screen-reader user hits first. Such a
  page now gets the standard header the code-backed pages use: the trail, the
  title, the excerpt as a lead, and on a locked policy page the date it was
  last changed

### Phase 6 Module 6 — getting involved — 2026-09-11

Volunteering has had a table, a safeguarding-check ledger and an approval
workflow since Phase 3, with no admin screen, no application form, and a
permission — `volunteers.view_pii` — that gated nothing.

#### Added

**Volunteering**
- `/volunteer`: the open roles, and a general application that is always
  offered — somebody who wants to help and finds no role listed is not sent
  away. The role page names the checks **before** the form: an applicant for a
  role with contact with children is told that a police clearance, two
  references and an interview come first, here rather than in an email three
  weeks later
- The application form asks for a date of birth only for a role that needs a
  police clearance, which is applied for against it; asks about convictions as
  free text, because a yes/no invites a no; and stores the declaration
  verbatim with when and where it was agreed, because "they ticked a box" is
  not evidence of what they were asked
- `VolunteerOpportunityResource` and `VolunteerApplicationResource` in the
  Community group. The application view is read-only; the personal details —
  date of birth, address, next of kin, disclosed convictions — sit behind
  `volunteers.view_pii`. The safeguarding checks are a tab where each outcome
  is a decision with a name on it: **passed** needs a reference, **waived**
  needs a reason and an authoriser, **failed** ends the application. Approve
  refuses out loud while any check is outstanding, naming what is missing;
  decline writes a reason for the file and a message for the applicant,
  written each time
- `VolunteerNotifier` sends `volunteer.application_received` (seeded since
  Phase 3, never sent), the `volunteer.approved` SMS (likewise), and two new
  templates, `volunteer.approved` and `volunteer.declined`

**The structured enquiries**
- An `enquiry-form` block — partner with us, corporate giving, donate goods,
  fundraise for us — so the get-involved pages stay CMS-composed. The editor
  writes the page and drops the right form into it. Each posts to the contact
  inbox routed to its department, with its answers written under headings so
  what arrives is actionable rather than "I have some things". The fields come
  from one list (`EnquiryKinds`) that both the form and the validation read
- `EnquiryRecorder`, shared with the contact form, so one place decides what is
  stored with a message and queues the acknowledgement
- A `select` field type for blocks, with options from an array or a class
- `PageContentSeeder`: a first draft of the get-involved hub and its four
  pages, seeded only into a page with no sections and left as **drafts**. Two
  pages were missing and are now seeded: corporate giving and fundraise for us.
  Peer-to-peer fundraising remains behind its flag, off; the fundraise page
  registers an offline effort and gives it a reference

### Phase 6 Module 5 — events — 2026-09-11

Events and their registrations have existed since Phase 3 with no admin
screen and no page. The confirmation email was seeded and never sent;
`Event::cancel()` promised in its own docblock that everybody registered would
be told, and nothing told them.

#### Added

- `/events`: what is coming, then what happened. Past events are an archive,
  not a deletion — "what have you actually done" is answered by the events that
  took place. An event page carries schema.org `Event` data, a directions link
  (a maps search, not an embedded map: a third-party script and a few hundred
  kilobytes on 3G for a widget people tap once), and **never** the join link
  for an online event, which goes to the people who register
- Registration as a plain form. Over capacity is a waiting list, not a refusal;
  a second registration from the same address updates rather than duplicates;
  and **photography consent is asked as a yes/no question**, because the column
  is nullable so that "we never asked" is distinguishable from "no". Three
  consents, three questions: holding the details, contact about this event, the
  newsletter — never pre-ticked, never conflated
- `EventResource` in a new "Community" group, with accessibility described
  rather than ticked, a headcount that is derived and not editable, and a
  registrations tab gated on `events.view_registrations` — seeded since Phase 3
  and protecting nothing — with check-in, and a door list export that carries
  what the door and the kitchen need and not the email, phone or consent
  evidence
- Cancelling is an action that asks why and tells everybody who was coming
  (`event.cancelled`, new). "Cancelled" on its own is not an explanation
- `EventNotifier` sends `event.registration_confirmed` at last, saying whether
  the place is confirmed or waitlisted
- `feature:events`. Paid tickets stay behind `FEATURE_EVENT_TICKETING`, off,
  and the model refuses to save a ticketed event while the flag is down

#### Fixed

- The sitemap listed pages and posts and nothing else. The areas of work,
  projects, appeals, products and events — the pages a donor is most likely to
  search for — are now listed, along with their index pages, the donation page
  and the impact page, each only when it has something on it and its feature is
  on

### Phase 6 Module 4 — the shop — 2026-09-11

The catalogue, the stock ledger, carts, coupons, delivery zones, orders and
invoices have all existed since Phase 3 — built and tested — with no page that
showed a product, no admin screen that could create one, and `FEATURE_SHOP=true`
in front of none of it.

#### Added

**The public shop**
- `/shop`, category pages (a parent lists its children's products), product
  pages with variants as real radio buttons, `/basket`, `/checkout`, and an
  order page reached from the gateway callback. Every write is a plain form
  POST; the basket works with JavaScript off, because that is the phone most of
  these customers have
- A sold-out product is still listed, marked — a shelf that hides its gaps
  looks like a shop with three products. A product tied to an appeal says so on
  the card and on its page: it is the reason to buy here rather than at a
  market stall
- The basket lives in a thirty-day cookie keyed on the cart's own token, not
  the session; a guest who signs in keeps what they added. Nothing is created
  until something is added, so crawlers leave no rows behind
- Checkout collects a Ghanaian address — region, area, a landmark-style line —
  and the phone number a courier actually uses. The delivery charge for **this
  basket** is listed region by region beside the form, with free-above and
  weight bands applied, and the gateway shows the exact total before taking
  anything
- **Nothing reads the query string about the outcome.** The order page reports
  what the database says — paid, being confirmed, not completed, under review —
  exactly as the donation page does
- `feature:` middleware. `FEATURE_SHOP` has been in `.env.example` since Phase 2
  and until now nothing on the request path read it; turning it off now makes
  every shop route a 404, indistinguishable from a shop that was never built
- `scghf:sweep-shop`, hourly: stock held by a checkout somebody walked away from
  goes back on the shelf an hour later rather than at tomorrow's reconciliation
  — twelve mugs and eleven abandoned checkouts must not read as sold out all
  day — after one last verify with the gateway, and expired baskets are deleted
  in the same pass
- The header shows the basket with its count once there is something in it

**The admin, in a new "Shop" group**
- Products, with variants (prices typed in cedis, stored in pesewas, one
  conversion in `MoneyField`), images, publishing, and two actions: **Adjust
  stock**, which writes an inventory movement with a reason rather than a
  number typed over a number, and **Record regulatory review**, which is the
  only way a product that trips the FDA keyword screen goes back on sale
- Categories with the trustees' approved-goods taxonomy visible; delivery zones
  with rates and a heading naming any region no active zone serves; discount
  codes typed the way they read
- Orders: a read-only view — an order is a snapshot and is never edited — with
  recorded transitions (being prepared, dispatched, delivered, collected), an
  unpaid cancel that releases stock, and a resend. **Dispatched** asks for the
  courier and sends `order.shipped` by email and SMS, both of which existed
  since Phase 3 with nothing that sent them

#### Fixed

- **⚠ Settlement told nobody.** `order.confirmation` and `donation.receipt` were
  seeded in Phase 3 with full variable sets and no caller: an order marked paid
  by the webhook produced no invoice and no email, and a gift confirmed by the
  webhook produced no receipt row and no acknowledgement — while the thank-you
  page said one was on its way. `OrderNotifier` now issues the invoice and
  queues the confirmation; `DonationNotifier` issues the receipt and queues the
  acknowledgement email and the `donation.received` SMS. Both are idempotent on
  the outbox key and neither can throw into the webhook path
- **⚠ Every HTML variable in every email was escaped.** A newsletter campaign's
  `{{content}}`, an appeal update's `{{body}}` and the acknowledgement paragraphs
  on a receipt would all have gone out as a page of visible `&lt;p&gt;` tags.
  `TemplateRenderer` now prints an `Htmlable` unescaped in the HTML body and as
  plain text in the text body; a plain string that happens to contain tags is
  still escaped, because that is where stored XSS would land. The outbox
  preserves the distinction through `json_encode`
- **The seeded navigation named routes that were never built.** `donate.index`,
  `impact.index` and `divisions.index` were the seeder's names; the routes were
  built as `donate`, `impact` and `focus-areas.index`. `isRenderable()` did
  exactly what it should with a name that resolves to nothing — dropped the
  item — so a fresh install had no Donate pill, no Impact link and no Divisions
  menu, silently, with every test passing. The seeder now names the real routes
  and repoints an install seeded with the old ones
- Every code-backed page shipped with a doubled `<title>` — eighteen
  controllers appended the suffix and `PageMeta::site()` appended it again. The
  page shell stripped every occurrence before the h1, which is why nobody saw
  it on the page, only in the tab, the search result and the share card
- The sandbox checkout always returned to the donation callback; it now returns
  to whichever journey started the payment

### Phase 7 — Projects, causes and impact — 2026-09-06

The programmatic heart of the site, on top of the pages Phase 6 built. Every
open decision in the brief was taken here rather than deferred; each one is
recorded below with the reasoning, and in the code beside the thing it governs.

#### Decisions taken

- **Goal-reached behaviour defaults to KEEP ACCEPTING**, settable per appeal to
  close or redirect. A foundation that hits its target and then refuses money is
  leaving gifts on the table, and a donor who has already decided to give is not
  somebody to turn away at the last step. What must not happen is taking money
  *silently* against a met goal — so the appeal page says the target has been
  reached whichever behaviour is chosen. Closing is for a specific, funded,
  finite thing; redirecting is for when there is somewhere better for it to go,
  and a redirect into a second full appeal is refused rather than bouncing a
  donor between two pages that both decline their gift
- **The per-cause expenditure log is aggregated by category, never itemised.** A
  payout record carries a payee name and frequently the beneficiary it was spent
  on; publishing the rows would publish who received school fees or a medical
  payment. Categories with too few payments are folded into "Other", because a
  single medical payment beside a known beneficiary is an identification
- **Peer-to-peer fundraising stays behind its flag, off.** The brief said "build
  if I confirmed I want it; otherwise scaffold the tables and hide the UI behind
  a feature flag". It was not confirmed. The tables and `donations.fundraiser_id`
  already exist from Phase 3; `FEATURE_P2P_FUNDRAISING` remains false, which is
  this project's definition of a genuine deferral rather than a gap
- **Cause updates are categorised as marketing, not transactional.** They are
  news, not receipts, so they honour the consent a donor gave or withheld and
  carry an unsubscribe link. Slipping campaign mail through the transactional
  channel is the fastest way to have the receipts themselves stop arriving

#### Added

**Giving levels**
- "GH₵ 50 provides a school kit for one child", per appeal, shown on the appeal
  page and offered on the donation form in place of the site presets. The single
  highest-value field in this phase: it answers the question a hesitant donor is
  actually asking — not "how much should I give?" but "what does my money do?"
- A level link carries its amount to the form, and a malformed level is dropped
  rather than thrown on — this is JSON edited through a form, and one bad row
  must not take down the page the foundation raises money on

**More on an appeal**
- An urgency flag, deliberately blunt: an appeal marked urgent that is not is
  the fastest way to make every future one ignored
- A per-appeal minimum, above the site floor. The two mean different things —
  the site floor is commercial, an appeal's own is editorial
- A fund code for the ledger, never rendered publicly. Restricted funds have to
  be reported separately

**Impact**
- `ImpactMetricResource`: define what is measured, how it aggregates, and record
  a value per period rather than overwriting a running total — "1,400 people
  served" is a number nobody can check, and a series survives somebody mistyping
  this quarter
- **"Counts people" is a safeguarding switch, not a label.** A metric marked so
  is withheld from the public page when the figure falls below the minimum group
  size, because "3 widows supported in Bongo" identifies those three women to
  anybody who lives there. Getting it wrong cautiously costs a number on a web
  page; getting it wrong the other way cannot be taken back
- The admin table shows the real total *and* what a visitor would see, so
  nobody has to guess whether a missing figure is zero or withheld. The export
  carries the **published** figure — disclosure control has to travel with data
  that leaves the application
- `/impact`: raised beside **paid out**, supporters, projects, published metrics
  and projects by region. Publishing "raised" alone is the number every charity
  publishes and it answers nothing a sceptical donor is asking; what went out is
  the claim that can be checked. The page says plainly why the two do not match

**Updates that reach the people who funded the work**
- `CauseUpdateResource` and `ProjectUpdateResource`, and a "tell the donors"
  action that emails an appeal's update to the people who gave to *that* appeal
  and consented to updates — one email each however many times they gave
- Publishing does not send. Sending is a deliberate button with a confirmation
  naming how many people it reaches, because an email to four hundred donors is
  not something to trigger by ticking a box while fixing a typo
- Offered only for a published update: emailing a link to something a visitor
  cannot see is the one mistake here a donor would definitely notice

#### Added — Phase 6 Module 3, the donation page

- `/donate` with presets, a custom amount, appeal selection, fee cover,
  anonymity, tribute giving and monthly giving; `/donate/callback` and a
  thank-you page
- **Nothing reads the query string about the outcome.** A donor returning from
  Paystack proves only that a browser followed a link; the money is confirmed by
  the signed webhook, and these pages report what the database says. A pending
  gift gets an honest "we are confirming this" rather than a thank-you that may
  be false
- Monthly giving is established **when the webhook confirms the money arrived**,
  not in the browser. A standing order set up from a payment that was later
  declined is a monthly charge against a card that never worked

#### Fixed

- **⚠ The sandbox checkout had no route.** `FakeGateway` — the DEFAULT payment
  driver — has returned `/payments/fake/{reference}` as its authorization URL
  since Phase 3, and that route did not exist. On every developer machine, in CI
  and on any staging deployment without live keys, starting a donation sent the
  donor to a 404: the donation engine was fully tested and the donation JOURNEY
  could not be walked once, by anybody. It now exists, is refused in production
  by two independent guards, and delivers a real signed webhook through the real
  handler — signature check, raw event store, idempotency and queued processing
- **⚠ So did the callback.** `PAYSTACK_CALLBACK_URL` has pointed at
  `/donate/callback` in `.env.example` since Phase 2. A real payment would have
  returned the donor to a 404 immediately after taking their money
- `Cause::allow_recurring` was a toggle in the admin with nothing reading it.
  Monthly giving is now offered on the form when an appeal allows it


### Phase 6 — Projects, areas of work and appeals — 2026-09-06

Module 2 of the public site. The programmatic pages, the admin screens to
publish them, and the seven settings that had described how to give since
Phase 3 and appeared on no page.

#### Added

**Ways to give**
- `/give` shows the Mobile Money merchant details and the bank account, from
  `banking.*` — seven settings seeded in Phase 3 and read by **nothing** until
  now. For a Ghanaian foundation that is not a minor omission: mobile money is
  how a very large share of giving actually happens, and a supporter who cannot
  find the merchant number gives nothing rather than reaching for a card
- Numbers are set in a monospaced run so digits line up and a transposed pair is
  visible — somebody is typing this into a banking app with the page open behind
  it
- Half a set of bank details is never shown. A block reading "Account number:"
  with nothing after it is worse than no block, on the one page where a visitor
  most needs to trust what they are looking at
- Bank transfer and Mobile Money need no gateway and cost the foundation less
  per gift, so this is a page in its own right rather than a footnote under a
  card form that has not been built yet

**Areas of work, projects and appeals**
- `/what-we-do` and its detail pages, `/projects` with filters, `/appeals` and
  the appeal page with its progress bar
- **The project page is the transparency page.** Budget, status, dates,
  locations, public milestones, partners, documents and updates together are
  what let somebody *check* a claim rather than take it
- **Milestones are published only when marked public.** An internal target the
  team missed is not a promise the foundation made to anybody — and a
  transparency page that publishes every slip is one a team stops recording
  honestly
- **Every filter is a link, not a script.** Each combination is a real URL:
  shareable, bookmarkable, crawlable, and working before any JavaScript has
  loaded. The options come from the data, so the regions offered are the regions
  work is actually in — a dropdown of all sixteen Ghanaian regions on a site
  with work in three is thirteen dead ends
- A project counts for a year if it was **running** in it, not only if it
  started in it. Filtering a three-year programme out of years two and three
  would make the foundation look like it stopped
- The progress bar writes the amounts out as text above the bar and carries a
  real `aria-valuetext`, because "68 percent" without saying of what has told
  somebody almost nothing. It is **not capped**: an appeal that raised 140% says
  so, which is the best news the page has
- **A closed appeal keeps its page.** `isLive()` decides whether the page
  exists; `acceptsDonations()` decides whether it takes money. That page is the
  record of what was raised, and deleting it turns every link anybody shared
  into a 404 — so the page says plainly that the appeal has closed instead

**The donor wall**
- Names only, never amounts. `publicDonorName()` already returned "Anonymous"
  for a gift marked so, but the amount is a separate question: "Anonymous — GH₵
  5,000" beside a list of named gifts identifies the anonymous donor to anybody
  who knows what they gave, which is exactly the person they were hiding it from
- `site.show_donor_wall` switches the whole feature off, and is honoured. A
  donor who assumed their gift was private is not somebody to surprise
- Tax relief wording appears only when `TaxDeductibility` says the foundation
  holds a current GRA approval. `is_tax_deductible` says the trustees consider
  the cause qualifying; reading that column directly would be the shortcut that
  puts an unsupported claim in front of a donor

**The admin screens**
- `FocusAreaResource`, `ProjectResource` and `CauseResource`, under a new
  Programmes group. Public pages with no way to publish anything onto them would
  have been permanently empty — Phase 7 adds impact metrics, cause updates that
  notify donors, and giving levels on top of these
- Project locations are **rows, not a text field**: a single "Location" box
  would make "Upper East" and "Upper East Region" two different regions in the
  public filter, and nobody would ever find out why one of them returns nothing
- Amounts are entered in pesewas with a live cedi conversion, and converted
  explicitly on the way in and out — `MoneyCast` accepts a Money or an integer
  of minor units and throws on a string, which is what a form field submits

#### Added — models

- `Project::causes()`, the inverse of `Cause::project()`, which had existed
  since Phase 3 with no way to walk it the other way. A project page had no way
  to ask what somebody could give to
- `FocusArea` now uses `HasSeo`, so an area of work gets a real title,
  description and share card like every other public entity

#### Fixed

- `project_locations.name` is NOT NULL, and the admin form offered it as
  optional — a save that failed at the database with no field to point at
- The areas-of-work table reached through to the division once per row. Invisible
  on a screen with four areas and a real cost on one with forty


### Phase 6 — The public content pages — 2026-09-06

Module 1 of the public site. Eleven pages over content the CMS already managed,
and the four things that turned out to be missing underneath them.

#### Added

**The SEO layer, connected**
- `PageMeta` and `<x-site.meta>`: title, description, canonical, Open Graph and
  the Twitter card, resolved from each record's own `HasSeo` values
- **This is why it matters.** `seoOpenGraph()` was written in Phase 3 and read
  by nothing, so until now every link to this site shared on WhatsApp — which is
  how most of this foundation's supporters share anything — rendered as a bare
  blue URL with no title, no summary and no picture. A donation appeal shared as
  a bare link is an appeal nobody taps
- A canonical on every page, because the same page is reachable with a trailing
  slash, with a `?utm_source` from the newsletter, and through a redirect —
  without one a search engine treats those as competing pages and the
  foundation's own campaign link outranks the page it points at
- A share image is refused if it is not publishable. An OG image is fetched by
  Facebook, WhatsApp and every scraper that sees the link, so an unsanitised
  photograph shared as one hands its GPS coordinates to all of them
- `StructuredData`: `NGO` and `WebSite` on every page, `BreadcrumbList` on inner
  ones, built entirely from the settings layer so it cannot drift from the
  footer, the receipts and the emails that read the same rows. An unfilled
  `{{PLACEHOLDER}}` is omitted rather than published — the absence is invisible,
  the token is a claim that the site is unfinished
- The safeguarding address is deliberately absent from the contact points: a
  confidential reporting route published as structured data is one that is in
  every scraper's index by Friday

**Crawlers**
- `/sitemap.xml`, from the database rather than a crawl. `spatie/laravel-sitemap`
  is a dependency and its crawler fetches every page over HTTP — on shared
  hosting that is the site crawling itself through the same PHP workers serving
  donors
- `/robots.txt` as a route, refusing everything while indexing is off and
  pointing at the sitemap once it is on
- `SetRobotsHeader` adds `X-Robots-Tag` when indexing is off, because the meta
  tag is read only by a crawler that parses HTML — and one fetching an uploaded
  PDF, an annual report or an image never does

**Eleven pages**
- News index, category and post, with scheduled posts held by the query rather
  than by a job — so a post scheduled for 6am appears at 6am without the cron
  having had to run
- FAQs as `<details>`, with anchor ids search results link straight to
- Galleries and albums, documents, team, partners, testimonials, and a search
  results page
- `<x-site.page-shell>` frames all of them, so eleven pages cannot each invent
  their own spacing and their own idea of where the breadcrumb goes
- Breadcrumbs with `aria-current` on the last item and the separator hidden from
  screen readers — otherwise every inner page reads as "Home, greater-than,
  About, greater-than, Leadership"

**The contact form**
- The missing half of a feature finished on the admin side in Phase 5. Consent
  is required and its exact wording snapshotted onto the record, because consent
  to a privacy notice that has since been rewritten is not evidence of anything
- **A confidential department is never offered and never accepted.** A
  safeguarding route in a dropdown beside "Shop enquiries" is one that gets used
  for shop enquiries — and whose existence and address are published to every
  scraper. The department is re-checked server-side, not trusted from the form
- The enquiry is stored first and the acknowledgement attempted afterwards. The
  other order loses the message and tells the sender it failed, so they give up
  — and on shared hosting the mail host is periodically down

**The newsletter**
- Double opt-in: subscribe, confirm from the inbox, one-click unsubscribe.
  `Subscriber::recordConsent()`, `confirm()` and `unsubscribe()` were written in
  Phase 3 with the confirmation template seeded beside them, and nothing called
  any of it
- **The same answer whether or not the address is already on the list.** "You
  are already subscribed" is an address-existence oracle: anybody can type an
  email in and learn whether that person supports this foundation, which on a
  charity working with vulnerable people is not a neutral fact
- A suppressed address is never re-enrolled. Mailing one that hard-bounced or
  reported a message as spam is the single fastest route to a blocked sending
  domain — and expired suppressions are honoured as expired, so a soft bounce
  from six months ago does not refuse somebody asking to hear from us
- Unsubscribe is one click with no sign-in and no confirmation step. A
  friction-filled unsubscribe is how a recipient reports the message as spam
  instead, and a complaint damages delivery for every message the foundation
  sends, receipts included

#### Fixed

- **⚠ `robots.txt` said the opposite of the setting.** A static file in
  `public/` allowing every crawler in, while `.env.example` had claimed since
  Phase 2 that the indexing switch drove it. A staging deployment was indexable
  regardless — and a staging site indexed beside the real one splits its search
  ranking and shows donors a test site. The file is gone; the route decides
- **`show_in_sitemap` was a switch that did nothing.** A column since Phase 3, a
  toggle in the page builder since Phase 5, and no sitemap for it to affect
- **The footer's newsletter form has rendered nothing on every page since
  Phase 4.** Its `Route::has('newsletter.subscribe')` guard was correct and
  doing its job — the route did not exist. What was missing was everything
  behind it
- **`<x-site.field>` promised `type="textarea"` in its own docblock and only
  ever rendered an `<input>`**, so the contact form's message box was a
  single-line field. The value now goes between the tags rather than into a
  `value` attribute, which is the specific way a rejected form loses somebody
  three paragraphs of typing
- Error pages, the account area and the auth screens are noindexed. An indexed
  404 is a search result that takes somebody to a dead end on the foundation's
  own domain
- The site search escapes `%` and `_` before they reach a `LIKE`. Unescaped, a
  visitor searching for "100%" matched every row on the site — bindings stop
  injection, they do not stop this

#### Added — settings

- `compliance.contact_consent_text` and `compliance.newsletter_consent_text`, so
  the sentence somebody ticks can be corrected by the foundation's own lawyer
  without a deploy, and so the form and the stored evidence read the same row


### Phase 5 — The admin experience — 2026-09-05

Module 5 of 5, and the end of the CMS phase. A dashboard, global search, CSV
export, and a Site Health page — plus three things this module could only be
built by first making true.

#### Added

**Site Health**
- One screen answering "is this site actually working?", over the things that
  fail *silently* on shared hosting: a missing cron line, a queue nothing is
  working, unwritable storage, a backup that stopped three weeks ago, test
  Paystack keys on a live site, pre-paid SMS credits running out. None of those
  produce a log line; all of them are one query
- **An empty queue reports "cannot tell", not green.** An empty queue is exactly
  what a working worker and an absent cron line both look like, and a health
  page that reports fine because it could not find a problem is worse than no
  health page at all
- The scheduler check reads a **heartbeat the scheduler writes itself**, so a
  missing cron line shows as a stale timestamp rather than as silence
- Every check carries a sentence saying what to do — usually which cron line to
  add. A foundation administrator told "Queue: warning" and nothing else has
  been told nothing
- Every check is wrapped. This page is opened when something is already wrong,
  and a check that throws would take down the one screen somebody came to for an
  explanation

**`scghf:preflight`**
- The same checks from the command line, exiting non-zero on anything critical
  so a deploy script can refuse to finish — plus the list of settings still
  holding a `{{PLACEHOLDER}}`, which `Settings::unfilled()` has been able to
  produce since Phase 3 and only ever produced for nobody
- A placeholder is a **warning, not a failure**. A registration number arriving
  a week after launch is normal for a Ghanaian non-profit, and a command that
  fails the deploy over it is one somebody adds `|| true` to — after which it
  reports nothing at all, including what should have stopped the deploy

**The dashboard**
- Raised this month with a 30-day sparkline, today's giving, recurring donors,
  and a queue of the things somebody has to act on: donations the webhook could
  not reconcile, unanswered enquiries, orders to send, low stock
- **Compared to the same point last month**, not to the whole of it. On the 3rd,
  "down 89%" is true and useless — three days are not thirty
- **Only completed donations are counted.** A pending donation is somebody who
  opened the Paystack page; counting those gives a fundraising figure that goes
  up when nobody pays
- Every widget is gated on the permission for the data it shows. The dashboard
  is the first screen every staff account lands on, so a widget that ignores
  permissions shows the foundation's income to the volunteer coordinator
- Zero is shown in grey rather than hidden: a tile that disappears when empty
  makes "nothing to do" and "the tile is broken" look identical
- A Site Health summary line, because nobody opens a health page — they open the
  dashboard. It names the **worst** problem rather than counting them: "3
  problems" gets deferred, "the scheduler has stopped" gets acted on

**Global search and CSV export**
- Global search across pages, news, FAQs, testimonials, partners, team,
  galleries, documents, announcements, redirects, the inbox and the media
  library, each searching inside its body as well as its title — a search that
  only matches headings is one people stop using
- A shared streaming CSV export on ten tables. It **streams**, which is not an
  optimisation: building a four-thousand-row CSV in memory on a host with a
  40MB limit is a 500 that only appears once the foundation has real data
- It exports **what the filters show**, not the whole table — the commonest way
  an export quietly hands over more than somebody meant to share
- It writes a UTF-8 byte-order mark, without which Excel on Windows turns `GH₵`
  into mojibake and mangles every accented Ghanaian name
- Relations the export reaches through are eager-loaded. Without that, one
  column of `$record->category?->name` is one query per row inside a streaming
  response

**Navigation groups**
- Website, Content, Inbox, Library, System — in the order somebody works rather
  than alphabetically, with System last because it is where you go when
  something is wrong

#### Fixed

- **⚠ Backups had never run, on any environment, since Phase 2.**
  `spatie/laravel-backup` was installed, `RecordBackupOutcome` was registered as
  a listener in Phase 3, and `backup_log` had a model, a policy and a
  `nextRestoreTestDue()` — but `config/backup.php` did not exist and nothing
  scheduled `backup:run`, so the listener waited for events nobody fired and the
  table stayed empty. The config now exists, shaped for shared hosting: local
  disk **outside the web root**, the backup directory excluded from itself (or
  each archive contains every previous one), and a retention chosen against
  cPanel's inode quota rather than disk space
- **⚠ The dashboard had nowhere to render.** The panel never registered a
  Dashboard page, so `/scghf-office` redirected to whichever resource sorted
  first and every widget in `app/Filament/Widgets` was discovered, registered
  and drawn nowhere
- **Six `.env.example` backup keys were documented and read by nothing** —
  `BACKUP_ENABLED`, `BACKUP_DISK`, `BACKUP_NOTIFICATION_EMAIL` and the retention
  trio. The config reads them now rather than inventing a parallel set
- `BACKUP_NOTIFICATION_EMAIL=` is **present and empty** in the shipped
  `.env.example`, so `env('...', $fallback)` returned the empty string and never
  reached the fallback — and the package rejects an empty address by throwing,
  which took down every artisan command including `schedule:run`. An unset key
  and a key set to nothing are not the same thing
- **`AuditLogger::recordExport()` had no callers.** It was written in Phase 3
  for exactly this and reachable from nowhere, so an export left the
  application's protections behind with no record that it happened. Every export
  now records who ran it, how many rows, and which filters were applied — the
  difference between "exported 4,000 rows" and "exported 4,000 rows with no
  filter", which is the difference between a job and an incident
- `MnotifyGateway::balance()` was written in Phase 3 and read by nothing, so
  pre-paid SMS credits could reach zero and the first sign would be a week of
  receipts that never went

#### Changed

- `config/system.php` gains `contact_messages.exported`. The contact inbox holds
  names, phone numbers and whatever somebody chose to write — which for a
  foundation is sometimes a disclosure — so it is a `warning` like the donor
  exports rather than the `info` used for content


### Phase 5 — The content modules — 2026-09-05

Module 4 of 5. Fourteen admin screens over schema Phase 3 had already built,
and a sweep of the things that turned out to be sitting behind it unreached.

#### Added

**The blog**
- `PostResource`: headline, address, summary, body, category, tags, byline and
  featured image, on the same `PageStatus` lifecycle as pages — so "scheduled"
  means the same thing in both places and an editor learns it once
- The slug stops following the title once a post is published. A published post
  has been linked to, shared and indexed; renaming its address because somebody
  fixed a typo in the headline turns every one of those links into a 404 of the
  site's own making
- **The byline is not "whoever is logged in".** Somebody in the office
  frequently types up a piece written by the founder or a field officer, and a
  post bylined to the administrator account is wrong in a way readers can see
- Comment moderation, with approve and mark-spam in bulk. There is no way to
  edit a comment: a moderator who rewords one and leaves it under its author's
  name has published words that person did not write
- The moderation screen is **hidden while `FEATURE_BLOG_COMMENTS` is off**. A
  queue in the sidebar that can only ever say zero is the "on and empty" shape
  this project has a rule against

**The library, and the small content types**
- Galleries with a reorderable list of photographs, documents with a
  publication and a sign-in gate, FAQs and their categories, testimonials,
  partners, team members and their departments
- **Consent gates publication**, on testimonials and on galleries. The models
  have refused a publication without it since Phase 3; the forms now refuse
  first, and the list shows which records are waiting on it — a testimonial
  waiting on consent looks exactly like a published one in every other respect,
  and the difference is the whole legal position
- The testimonial form's exemption matches the model's **exactly**. A partner or
  a staff member quoted professionally speaks for themselves; a beneficiary, a
  volunteer or a donor does not. A form stricter than the model makes a
  legitimate testimonial unpublishable; a looser one produces a save that throws
  with no field to point at
- `MediaPicker`, one implementation of "only publishable files are offered",
  shared by eight screens. Eight copies of a safeguarding gate is eight chances
  for one of them to drift into offering a photograph that still carries the
  coordinates it was taken at
- A team member's **public email is not their account email**. Publishing a
  trustee's private address on a page a scraper reads within the hour is a real
  risk to a real person, and the usual way it happens is a form that helpfully
  pre-fills "their" details from the account they sign in with

**Announcements, banners and popups**
- `AnnouncementResource` over the table that has had dismissal, path targeting,
  three placements and impression counting since Phase 3 with nothing rendering
  it
- The list says **Live, Scheduled, Finished or Off**, and can filter to
  "finished but still switched on" — the notice that has been quietly
  advertising a closed appeal, invisible in a list sorted by anything else
- Clicks are counted through a route that validates the destination first. An
  open redirect is an open redirect whoever typed the URL
- Dismissal writes an http-only cookie for `dismiss_days`, and a dismissed
  notice is filtered out **before** it is rendered — so it is not counted as
  seen again, and the click-through rate is not measured against a number that
  grows for people being shown nothing
- The close button is created by JavaScript rather than by Blade, so it cannot
  exist without the code that makes it work

**The contact inbox**
- `ContactMessageResource`: statuses, assignment, internal notes, and a reply
  action that sends the new `contact.reply` template through `MessageDispatcher`
  — logged, suppression-checked and rate-limited like every other email rather
  than a `Mail::raw` that bypasses all three
- **Oldest first**, backwards from every other list in the panel and on purpose:
  an inbox sorted newest-first buries the enquiry that has been waiting longest
  under the ones that just arrived. Anything unanswered for a week is red
- The enquiry itself is read-only. What somebody sent is a record of what they
  sent, and for a safeguarding report it may be evidence
- **Safeguarding messages are excluded from the list**, not merely from the
  record. `ContactPolicy` refuses an individual confidential message, but a
  table query returns rows without asking a policy about each — so without the
  scope, the sender's name and the subject line of a report about a child would
  appear in the general inbox for anybody with `contact.view`. The subject line
  of a safeguarding report is frequently the whole disclosure

**Redirects and the 404 log**
- `RedirectResource` over one table serving both, because a captured 404 *is* a
  redirect with no destination yet. Filling in the destination and switching it
  on is the entire workflow — no import, no second screen, no copying a path
  from one list into another and mistyping it
- Sorted by hits, defaulting to the work queue. The question is "what is costing
  us visitors?", and the answer is the path hit most, whenever it was recorded
- The last referrer is shown on the record: an old newsletter or a printed flyer
  is usually what tells an editor what the address was meant to be

**Navigation groups**
- Website, Content, Inbox and Library. Eighteen resources in one flat list is
  unusable for the non-technical staff this panel exists for

#### Fixed

- **⚠ The dark theme rendered black text on a dark background.** The views
  referenced `var(--text)` and `var(--focus)` in 114 places; the seeded palette
  calls those tokens `text-primary` and `focus-ring`. With a real palette in the
  database the custom properties resolved to nothing, the declarations fell back
  to the initial colour, and every page of the public site lost its text colour
  in dark mode. It survived two phases because of where the names *did* match:
  `ThemeTokens::FALLBACK`, the emergency palette used when `theme_settings`
  cannot be read — which is the path tests that do not seed the palette take. The
  whole suite was green while the product was broken. An undefined custom
  property is silent: no console error, no build failure, no missing file.
  `ThemeTokenCoverageTest` now compares the views, the fallback and the seeded
  palette against each other
- **`created_by` and `updated_by` were written by nothing, on thirty tables.**
  The columns existed, the foreign keys constrained, the relationships resolved,
  and every answer was null — the worst shape an audit field can take, because a
  present column that always says "nobody" gets believed. `RecordsAuthor` stamps
  them on the model, for a real person only: a seeder has no user, and
  attributing its work to whoever last logged in would be a lie
- **Nothing served a redirect.** `Redirect::resolve()`, `record404()` and
  `unresolved404s()` were written in Phase 3 and called from nowhere, so the
  foundation could have entered fifty redirects before launch and every one of
  them would have 404ed — the failure that looks least like a bug, because the
  page was already missing. `HandleRedirects` runs on the way out and only on a
  404, so the table is never queried for a request that resolved
- **There were two announcement bars.** Module 3 seeded an `announcement.*`
  settings group and rendered a bar from it while this table sat unused, so the
  settings version looked like the only one there was. The foundation would have
  edited one and wondered why the site showed the other. The table wins — it
  does everything the settings did and five things they could not — and a data
  migration removes the orphaned rows
- `contact_departments.is_confidential` had been read by nothing since Phase 3,
  so the routing decision it encodes had no effect on who could see what

#### Removed

- `App\Support\Announcement` and the `announcement.*` settings group, superseded
  by the `announcements` table. `settings_history` keeps what they used to say —
  deleting a setting does not un-say it


### Phase 5 — Menus, theme and the settings screen — 2026-09-05

Module 3 of 5. The parts of the site that are not pages: the navigation, the
palette, and the settings table that has been driving the layout since Phase 3
with nothing in front of it.

#### Added

**The menu builder**
- `MenuResource`, with items and their children as nested repeaters — two
  levels, because `Menu::max_depth` is one and the model enforces it on save. A
  generic tree widget would let somebody build a third level the layout cannot
  draw, and the model would then refuse the save with an error they could not
  have anticipated
- **No create action.** The templates ask for a menu by key. One nobody draws is
  a menu nothing shows; one that goes missing is a part of every page rendering
  empty. The layout decides which menus exist
- A locked menu's key is not editable, for the same reason
- **A link is a relationship.** Choosing a page stores `page_id`, so the item
  follows that page when its address changes. The route picker offers only GET
  routes with no parameters — a menu item cannot supply a `{slug}`, and offering
  `causes.show` would produce a link that cannot be built
- Menus are addressed in the panel by their key, so the URL reads
  `/menus/footer_legal/edit` rather than `/menus/4/edit`

**The theme editor**
- `ThemeSettingResource`, with the contrast ratio as a **column**.
  `ThemeSetting::contrastRatio()` and `meetsContrast()` have existed since Phase
  3 with nothing showing them, which meant a palette could fail WCAG AA and the
  panel would never say so. Every failure it reports is text somebody cannot
  read
- Both themes in one grouped table. The commonest way a themed site fails
  accessibility is a palette checked in light and never in dark, and splitting
  them across two screens makes that the default outcome
- A **navigation badge** counting failing tokens, and no badge at all while the
  palette is legible — a permanent badge is one people stop reading
- "Fix contrast" suggests black or white on the failing background, changing the
  **foreground**: the background is usually a brand colour somebody chose
  deliberately, and the text on it is the part with no opinion of its own
- "Reset to brand defaults", because the palette is the one part of the CMS
  where an afternoon of small adjustments leaves something nobody can unpick —
  nobody remembers what nine hex codes used to be
- The ratio under the colour field is computed from the value being **typed**,
  not the one stored. A checker reporting on the colour you are replacing is
  reporting the one number that is certainly not useful

**The site settings screen**
- `ManageSettings`: every setting in the table, in tabs matching the groups the
  seeder already uses. The legal name, registration number, phone numbers, bank
  details, donation limits and receipt wording have been read by the layout, the
  footer, every email template and the donation form since Phase 3 — and were
  editable only by somebody with database access, which is the exact opposite of
  CLAUDE.md's CMS rule
- **The field follows the declared type.** A phone number gets a tel keypad and
  the Ghanaian mobile pattern; an amount gets pesewas with the cedi conversion
  spelled out live underneath, because a foundation that types "50" meaning
  fifty cedis and gets fifty pesewas has set its minimum donation to half a cedi
  and will not find out until a donor does
- **A value still holding its seeded `{{PLACEHOLDER}}` is exempt from its own
  rule.** The strict reading would refuse to save the Contact tab until every
  field on it was filled in, including the ones somebody came to the screen to
  avoid. Unfilled is reported by `Settings::unfilled()` and by the preflight
  command, which is where "this is not real yet" belongs
- The subheading says how many settings are still placeholder. That count has
  been computable since Phase 3 and only a console command ever asked
- An encrypted setting is never sent to the browser, and an empty field on save
  means "unchanged" rather than "cleared" — otherwise somebody could wipe a
  credential by saving an unrelated tab

**The announcement bar**
- `App\Support\Announcement`, and a bar above the header driven from the
  settings layer. **Dated on purpose**: an announcement with no end date is one
  somebody has to remember to take down, and nobody ever does — which is how a
  foundation ends up advertising last December's carol service in March
- `ends_at` is inclusive of the day it names. Off by one here is a Christmas
  appeal that vanishes on Christmas morning
- A mistyped date is treated as no bound rather than thrown. The failure mode of
  a bad end date is a bar that stays up too long, which somebody notices; the
  failure mode of throwing is a 500 on every page of the site
- A link needs both a URL and a label. A URL alone is a link with nothing to
  click; a label alone is text pretending to be one

**Header and footer settings that now do something**
- Two logo files, light and dark, rather than one recoloured — a logo that reads
  on white rarely reads on the dark palette, and a CSS filter that inverts it
  produces a colour the brand does not own
- A sticky header, switchable: it keeps the Donate button reachable the whole
  way down a long appeal page, and it also permanently spends a strip of a short
  phone screen, so it is the foundation's call rather than the template's
- An optional top bar carrying the office hours, phone and email
- The footer's newsletter heading, and a back-to-top link pointed at the skip
  link's own target so it **moves focus** as well as scrolling. A JavaScript
  scroll leaves a keyboard user's focus at the bottom of the page they just
  left, which is the usual way this control is built and the usual way it is
  broken

#### Fixed

- **Every email this application sent had a blank line where the foundation's
  name belongs.** Both mail layouts read `organisation.legal_name` falling back
  to `general.site_name`, and neither key existed in the settings table. Now
  `general.legal_name` falling back to `general.short_name`
- **`ThemeSettingsSeeder` overwrote the palette on every run.** It used
  `updateOrCreate`, while `SettingsSeeder` two lines above it in
  `DatabaseSeeder` is explicit that re-running must never undo the foundation's
  work. Nothing runs `db:seed` on deploy today, but the runbook's advice for
  picking up newly added settings is to re-run the seeders — and following it
  would have thrown away every colour the foundation had chosen while leaving
  their address and phone number intact, which is exactly the kind of surprise
  nobody connects to the command they just ran. Metadata is still refreshed;
  only the value is now write-once
- **`updated_by` was written by nothing** on either the settings or the theme
  table, so the column existed, the relationship resolved, and the answer was
  always "nobody" — the worst shape for an audit field, because it reads as a
  fact. Now stamped on the model, for a real person only: a seeder has no user,
  and attributing its work to whoever last logged in would be a lie
- **`Setting::validation` was decoration.** Seeded from
  `SettingType::validationRule()` since Phase 3 and read by nothing. The settings
  screen is its first consumer, and it is what now refuses a mistyped office
  email address — the setting every receipt in the system is sent from
- **`Setting::options` was never written**, leaving `site.default_theme` a
  Select with an empty list: a setting on the screen that nobody could change
- The settings cache is a single array of the whole table, busted only inside
  `Settings::set()`. The admin screen saves models directly, so the flush moved
  onto the model — a settings change that does not appear until a cache expires
  is indistinguishable, to the person who made it, from one that did not save
- `SettingType::Email` validated with `email:rfc,dns`. A DNS lookup inside a
  form submission makes saving depend on the web server's resolver, and shared
  hosting is exactly where that goes wrong: "save the office address" becomes a
  request that times out with no explanation an editor could act on.
  Deliverability is a preflight question
- Fourteen settings the header, announcement bar and footer needed were missing
  entirely, so the footer's own column headings could not be changed without a
  deploy


### Phase 5 — Block rendering and preview — 2026-09-05

Module 2 of 5. The page builder from this morning can now be seen: twenty block
views, a public route, and a preview for drafts.

#### Added

**Twenty block views**
- One for every key in `BlockRegistry`, with a test that fails if the registry
  ever gains a block the site cannot draw — a block an editor can place and
  nothing renders is a CMS that looks broken with no error anywhere
- A shared `x-blocks.section` wrapper owns the framing, so no block builds its
  own background or spacing classes and a change to the scale moves the whole
  site at once
- Every block heading is an `h2`. The page title is the `h1`, and a block
  emitting a second one breaks the outline a screen reader navigates by — the
  easiest accessibility mistake to make in a CMS with twenty block types

**Decisions inside the blocks worth knowing**
- **The hero takes a separate mobile crop.** A 1600px hero scaled down by the
  browser is the largest single cost of a first paint on 3G, which is most of
  what the LCP budget is spent on. `<picture>` chooses before anything downloads
- **The video block does not embed a player.** An embedded iframe pulls roughly
  a megabyte of JavaScript and sets third-party cookies before anybody presses
  play — a real cost imposed on every visitor for a video most will not watch,
  and a consent question the site would then have to answer. The poster is a
  link
- **The testimonials block renders a list, not a carousel.** A carousel needs
  JavaScript to show anything past the first slide, hides its content from
  search engines, and auto-advances past people who read slowly
- **The FAQ block is `<details>`**, like every other disclosure here — readable
  with no JavaScript, and by a search engine
- **Impact figures go through `publishedTotal()`**, never `total()`. That is the
  method carrying the disclosure control, so a metric counting people below the
  minimum group size is withheld rather than published — the foundation's
  categories include health, orphan status and widowhood, and "3 widows
  supported in Bongo" identifies them
- Each figure carries the date it was measured. An unsourced statistic on a
  fundraising site is a trust risk
- **The donation block is a link, not a form.** The real form is Phase 6, and a
  donation form that looks real and does nothing is worse than a link — somebody
  will type their card details into it

**`BlockDataResolver`**
- Every query a block needs, in one file, each limited and eager-loaded. A
  `@foreach (Cause::live()->get())` in a template is untestable, invisible to
  anybody auditing what a page costs, and the usual route to an N+1 that only
  appears once the site has real content
- An editor's `limit` is clamped. A block asking for ten thousand causes is a
  page that times out and an editor with no idea why
- A failure returns empty rather than throwing: a block whose data will not load
  should be an absent block, not a 500 on a donation page

**The public route, and preview**
- `/{path}` resolves by the materialised `path` column — one lookup, no walk
  down the tree — and is **registered last on purpose**, since `.*` would
  otherwise swallow `/login` and everything else. There is a test for that
- A draft is a **404, not a 403**. A 403 confirms something is there, which is
  what an unannounced appeal must not do
- Preview is **signed AND staff-only**. A signed URL is still a string somebody
  can paste into a chat, so the signature proves the link came from the panel
  and the policy check proves the person opening it is entitled to
- The preview banner says plainly whether the page is published, so nobody
  wonders why a campaign nobody could see got no response
- A page with no blocks renders its title rather than a blank response, which
  would look like a server fault to a visitor and a deleted page to its author

#### Fixed

- **A malformed JSON setting took down every page that read it.** `SettingType`
  cast JSON with `JSON_THROW_ON_ERROR`, and `cast()` runs on every setting when
  the repository loads — once per request, on every page. One unparseable value
  anywhere in the table was a site-wide 500, including the donation page, since
  `donations.presets` is JSON. Found the honest way: an unfilled
  `{{PLACEHOLDER}}` is not valid JSON, and `Settings::get()`'s placeholder check
  never got the chance to run because the cast threw first. It now returns null,
  which `get()` turns into the caller's default — while the raw value stays in
  the column for `Settings::unfilled()` to report
- The layout honoured the site-wide noindex switch but not a page's own
  `no_index`. A thank-you page could not be kept out of search results
- The preview route bound `{page}` by `path`, which contains slashes. Now
  `{page:ulid}`

### Phase 5 — The page builder — 2026-09-05

The first of five modules in the CMS phase. Phase 3 had already built the
schema — `pages`, `page_sections`, `page_revisions` and a curated
`BlockRegistry` of twenty block types — so this is the screen the foundation
runs the website from, on top of it.

#### Added

**The builder**
- `PageResource`: title, address, summary, status and scheduling, parent page,
  sitemap and search inclusion, and the SEO fields on their own tab. Three tabs
  in the order somebody works — content first, search last, because search is
  the part nobody opens until launch
- Blocks as a drag-orderable, collapsible, duplicable list mapped to
  `page_sections` rows. Rows rather than a JSON column, because a row has an id
  and an id is what lets a revision restore put the right content back in the
  right place
- **The block fields are generated, not written.** `BlockFieldFactory` turns a
  `BlockDefinition`'s field list into Filament components — the same list that
  already generates the block's validation rules. A block added to the registry
  appears in the panel with no admin work, and the form and the validator cannot
  disagree
- The collapsed label says which block it is. A page of eight rows all reading
  "Section" is a page somebody opens eight times to find anything
- The image picker offers **only publishable media**, with helper text
  explaining why one might be missing. `Media::isPublishable()` refuses anything
  without alt text or with its camera metadata still on it, and the image
  component refuses it too — so blocking it at the point of choosing is the only
  place with room to say why

**Per-block presentation, as a closed vocabulary**
- `settings` on `page_sections`, and `SectionSettings` to read it: background,
  padding, width, alignment, a dark variant, and which screen sizes it shows on
- Every option is a fixed list resolving to a theme token or a spacing step.
  There is no colour picker and no pixel field — a colour picker produces pages
  that fail WCAG contrast the moment somebody chooses a light green, and a pixel
  field produces a site with fourteen different spacings
- **Nothing stored reaches a class attribute.** `SectionSettings` *looks up* the
  stored value in a map and returns the class from the map, so a `settings`
  column edited by hand can only ever produce a class the file already contains
- The admin's options are generated from the same constants the renderer reads,
  so a background offered in the panel is by construction one the renderer knows
  how to draw
- Hiding a block on phones is documented, in the field's own helper text, as
  visual emphasis only — it is `display: none`, still read by screen readers and
  still indexed, and must never be used to give different people different
  information

**Revisions with restore**
- A snapshot is taken **before every save**, not after. One taken after records
  a change that already happened — useful for an audit, useless for undoing
  anything
- Restoring is itself snapshotted, so restoring the wrong version is recoverable
- A "History" action lists the last twenty versions with who saved each and when

**Visible at a glance**
- The navigation badge counts **published pages with no blocks** — a heading
  over white space, the commonest way a CMS goes live looking broken, and
  invisible in a list that only shows a status. There is a filter for them too
- "View" appears only on a page that is actually live, because a view button
  leading to a 404 teaches people the button is broken rather than that the page
  is a draft

#### Fixed

- **`Page::snapshot()` omitted three columns an editor can set** — `settings`,
  `visible_from` and `visible_until`. A snapshot that omits a column is a
  restore that silently clears it: the page comes back looking restored and the
  missing part is noticed only by whoever set it. The presentation settings
  would have been reset to site defaults on the first restore anybody performed
- The block-type selector was disabled after the first save, to stop `data` from
  being left shaped for the wrong block. The reasoning was right and the
  mechanism was wrong: Filament omits disabled fields from the submitted state,
  so **no block could be added from the edit screen at all** — every new row
  arrived with no type and failed on insert. The mismatch is now prevented in
  `PageSection` itself, which resets `data` to the new block's defaults when the
  type changes, so it also holds for a seeder or an import
- `PageResource` resolves records by ULID. `Page::getRouteKeyName()` is `path`,
  so that a public URL is one lookup — and a path contains slashes, which cannot
  be a single admin route segment
- Added `PageFactory`, which did not exist. Draft by default, because that is
  the state a page is really created in — a factory handing out published pages
  lets `isLive()` and everything gated on it rot untested

### Phase 4 — Open questions 16 and 17, answered — 2026-09-05

Both were left open because in each case the convenient version is the dangerous
one. Both are now built.

#### Added

**Changing an email address, in three steps**
- The **current password**, because a session left open on a shared computer is
  not consent
- A confirmation link the **new address** must open — and until it does, `email`
  is untouched. An attacker with a stolen session who gets this far has changed
  nothing
- A warning to the **old address**, sent on the *request* rather than on the
  completion, carrying a cancel link. This is the control rather than a
  courtesy: it is the one moment the account holder can stop a takeover, and it
  reaches the inbox they still control
- Cancelling **ends every session**, because somebody had to be signed in to ask
  — if the owner says it was not them, whoever did it is still there and would
  simply ask again
- Neither link needs a sign-in. The confirmation is opened from a different
  device as often as not; the cancellation is for somebody who may be locked out
  of their own session, which is the entire situation it exists for
- The **giving history is deliberately not re-matched**. `claimDonorRecord()`
  matches an unclaimed donor by email and is not called on a change — otherwise
  moving your account to an address that happens to belong to an existing donor
  record would hand you that person's whole giving history
- `donors.email` is left alone too: it is the address given at the time of a
  gift, attached to financial records with a six-year statutory life. It records
  what happened, not where to write today

**Two-factor authentication for donors — optional, and offered**
- Enrolment on the security page: QR code plus a typed key, because the
  commonest way to do this is on the phone you are reading the page on, and you
  cannot scan your own screen
- **The secret never reaches the database unproved.** It waits in the session
  until a code generated from it verifies, so a populated `two_factor_secret`
  always means a factor the person can actually produce — there is no
  half-enrolled row that would lock an account out of itself
- **The account is never authenticated while the challenge is on screen.** What
  exists is an id in the session saying who is halfway through, re-read from the
  database each time so a suspension mid-challenge is not bypassed. A factor
  somebody can skip by closing the tab is a suggestion
- Rate limited on **account + IP**, the same pair the login form uses. Six digits
  is a million guesses to somebody who already has the password
- Single-use recovery codes, shown exactly once, with the remaining count on the
  security page — nobody notices they are down to their last one until the day
  they need the second
- The current password is required to turn it on *as well as* off. Adding a
  factor to somebody else's account locks them out of it just as effectively as
  removing one lets an attacker in
- `UserType::requiresTwoFactor()` is unchanged: mandatory for staff, optional
  for donors, per Blueprint §7.1

**Four new emails, and two of them nobody asks for**
- `account.email_change_confirm`, `account.email_change_alert`,
  `account.two_factor_enabled`, `account.two_factor_disabled`
- The two **alerts** are the point. A change of address and a second factor
  switched off are exactly what an attacker does once inside, and both are
  silent everywhere else in the system

#### Fixed

- **`LoginOutcome::TwoFactorFailed` had existed since Module 1 with nothing
  writing it.** A new `TwoFactorChallengeFailed` event now records it — Laravel
  has no event for "right password, wrong second factor", and filing it under
  `Failed` would say the credentials did not match, which is a different and far
  less interesting fact
- `auth.two_factor_disabled` had been declared in `config/system.php` since
  Module 8 with nothing recording it. It is recorded now, and
  `auth.two_factor_enabled` is added beside it
- The two-factor throttle was first keyed on the **session id**, which is wrong
  for a reason worth keeping: a session id is not stable, and authentication
  regenerates it deliberately — so a limit keyed on it resets whenever the thing
  it protects makes progress. A control that can be shed by discarding a cookie
  is not a control

### Phase 4 — The navigation, at both widths — 2026-09-05

The mobile menu, and the desktop dropdowns it turned out could not be built
without. Phase 4 is complete.

#### Added

**The mobile menu**
- An **expanding panel below the header, not a full-screen overlay.** A
  slide-over covering the page is a modal, and a modal owes the visitor a focus
  trap, `inert` on everything behind it, a scroll lock and a way out that is not
  the back button — four things to get wrong, each of which strands a keyboard
  or screen-reader user inside a menu. An in-flow disclosure owes none of them.
  With seven top-level items that is not a compromise; it is the simpler thing
  that is also the more correct one
- Built from **`<details>`, so it works with JavaScript switched off**. The
  browser already implements disclosure: click and Enter open it, expanded state
  is announced with no ARIA from us, and no script is needed. That matters
  specifically here — these visitors are on low-end Android phones, sometimes
  behind data-saver proxies that rewrite or drop scripts, and a navigation that
  needs JavaScript is a site they cannot move around
- `navigation.js` adds Escape (returning focus to the control that opened it),
  click-away, focus-away, and closing when the viewport crosses the breakpoint.
  Every one of those is a **convenience that is absent rather than broken** if
  the file never arrives — which is the test to apply to anything added to it
- The donate button stays in the header at every width, never inside the
  hamburger. It is the most important control on the site and hiding it behind a
  tap on a phone is hiding it from most of this foundation's donors
- Signing in moves into the panel below `md`, so the corner a thumb reaches
  first belongs to donating

**Desktop dropdowns**
- Top-level items with children now render as dropdowns, **click-activated, not
  hover**. A hover menu is unusable with a finger, hostile to anybody whose
  hands are not steady, and invisible to a keyboard without a pile of ARIA to
  compensate
- The parent's own page is the first entry inside its dropdown, so a parent that
  is both a page and a group does not become a pure toggle — `/about` exists and
  is published, and it was about to be reachable from nowhere

#### Fixed

- **Ten seeded menu items rendered nowhere, at any width.** The header menu has
  children under "About" and "Get Involved", `max_depth 1`, and a description
  that promises "one level of dropdown" — and the header rendered only the top
  level. Published pages, linked from the navigation table, reachable from
  neither the desktop row nor a phone. This is why the mobile menu could not be
  built on its own: rendering children on a phone and not on a laptop would have
  been worse than rendering them nowhere
- `x-site.menu-link` now merges classes passed to it instead of discarding them,
  which is what let one component serve both renderings

#### Changed

- **axios is gone, and with it 96% of the site's JavaScript.** Nothing
  referenced it — it was Laravel's default scaffolding putting a HTTP client on
  `window` for a site that makes no requests of its own. It was also the bulk of
  what every visitor downloaded before anything they came for. **52.41 kB →
  1.76 kB** (19.87 → 0.72 kB gzipped). Livewire and Filament carry their own
  request layer; `fetch` is in every browser this site supports
- The footer's duplicate account link is removed. It existed because the header
  hid signing-in below `sm` while the mobile menu was outstanding, and the
  comment saying so would now be describing something that is no longer true
- `summary` markers are hidden in `app.css` — two selectors, because
  `list-style` covers Firefox and modern Chrome while
  `::-webkit-details-marker` is what older WebKit still reads, which is Safari
  on the iOS versions this foundation's donors are actually running

### Phase 4 — The media library UI — 2026-09-05

The first Filament resource in the project, sitting on the engine from earlier
today. Phase 4's media library is now complete.

#### Added

- **The library screen**: thumbnail grid, folder filter, search, and a **"Cannot
  be published" filter** — because the job that brings somebody here is "why
  will this image not go on the page", and a library that makes you open forty
  files to find the blocked one is a library people work around
- The navigation badge counts blocked files, not total files. It is the only
  part of the screen visible without opening it, so it carries the thing that
  needs a person. Zero shows nothing — a permanent badge is one people stop
  reading
- **No Create page.** You do not create a media row; you upload a file and a row
  is what happens next. A create form would let somebody produce a `media`
  record pointing at nothing on disk, bypassing the only door in. Uploading is a
  header action that hands files to `MediaLibrary::add()`
- Alt text is **required unless the image is marked decorative**, enforced in
  the form rather than discovered later. `isPublishable()` already refuses a
  file without it, so a form that allowed it would produce files nobody can use
  and the person would find out on a different screen with no explanation
- A read-only status panel: publishable or why not, when metadata was removed,
  whether the file arrived carrying a location, which sizes exist, and how many
  files it costs on disk. None of it editable — whether an image has been
  sanitised is a fact about the bytes, and a form field would invite somebody to
  assert it instead
- **Replace file**, as a first-class action, with the count of affected records
  in the confirmation. Delete-and-re-upload leaves thirty references pointing at
  a row that no longer exists
- Deleting shows what is using the file *before* asking, and turns the model's
  refusal into a sentence rather than a 500 — a red error page would teach
  people the library is broken rather than careful

#### Fixed

- **`media.upload` granted nothing.** `BasePolicy` resolves `create` through
  create → update → manage and `update` through update → manage; the seeded
  permission is `media.upload`, which appears in neither chain. Three roles held
  it, the policy denied every one of them, and the library was unusable by
  everybody while looking correctly permissioned in the seeder. `MediaPolicy`
  now maps both onto `media.upload`
- **`User::hasPermissionTo()` threw on a partially loaded model.** It read
  `is_active` and `suspended_at` directly, so any `User::select('id', 'name')`
  followed by any permission check — a `@can` in a template, a Filament resource
  asking `canViewAny` — was a 500 with strict mode on, and *silently denied* in
  production where strict mode is off and `! null` is true. Two different wrong
  behaviours from one line. It now loads the two columns when they are absent
  rather than guessing in either direction: assuming good standing would let a
  suspended administrator keep their permissions, and assuming the opposite
  would deny a legitimate person with no explanation anybody could find. One
  query, only when needed, never on a real request
- `isInGoodStanding()` is now the single expression of "active and not
  suspended". The `Gate::before` in `AuthServiceProvider` was reading the same
  two columns raw and had the same fragility
- The test suite's memory limit is raised to 512M, with the reason recorded: the
  whole suite runs in one process and accumulates twelve hundred tests before it
  reaches the image conversions, which is not the application's shape. The
  production ceiling is enforced by `UploadPolicy`, which refuses an image whose
  bitmap would need more than 60% of the server's real `memory_limit`
- Conversions skip the optimiser chain when the server cannot launch external
  binaries. With `exec` disabled — common on shared hosting — spatie's
  optimisers read every file into memory, shell out to a binary that cannot
  start, and report success having done nothing

### Phase 4 — The media library engine — 2026-09-05

The upload path, the conversions, and the guard that stops a file being deleted
out from under the thirty things using it. The Filament UI sits on top of this
and lands separately.

#### Added

**Uploads are judged by their bytes**
- `UploadPolicy` sniffs the type with finfo and cross-checks it against the
  extension **in both directions**. A `.jpg` whose contents are PHP is refused;
  so is a genuine JPEG named `.php`, because the extension is what a
  misconfigured server dispatches on
- The browser's `Content-Type` is never consulted anywhere. It is a claim made
  by whatever did the uploading, and whatever did the uploading is not always a
  browser
- **SVG is refused outright.** It is XML that can contain `<script>`, served
  from the same origin as the admin panel — an uploaded one is stored XSS
  holding the session of whoever opens it. Sanitising SVG reliably is a game
  played forever against new parser quirks
- Filenames are transliterated, stripped, and **collapsed to a single dot**:
  `invoice.php.jpg` lands as `invoice-php.jpg`, because mod_mime can dispatch on
  any extension in the chain and this deploys to hosting whose configuration is
  not ours to audit. The extension comes from the sniffed type, never the upload
- Decompression bombs refused: a 64,000² PNG of one flat colour is a few
  kilobytes on disk and 16GB as a bitmap, so it passes a size limit and then
  takes the PHP process down
- `AcceptableUpload` exposes the same policy as a validation rule, so a form
  cannot get a different answer from the library

**Conversions, sized against the layout and the inode quota**
- thumb 320 / card 800 / hero 1600, WebP, queued
- **Three widths and no more.** Each conversion is a *file*, and files are the
  scarce resource here — the inode quota is what a media library exhausts first,
  long before the disk quota. Four files per image; a second output format would
  make it seven
- Never upscaled. A 500px original gets a thumb and nothing else — an 1600px
  "hero" cut from it is a bigger file that looks worse
- Animated GIFs keep their original only, rather than being silently converted
  into a picture of their first frame
- AVIF is **built and off**. Encoding costs seconds rather than milliseconds per
  image on a shared CPU, which on a bulk upload is a request that times out
  halfway through

**An unsanitised image gets no derivatives at all**
- The safeguarding rule, and the reason `HasLibraryMedia` exists. `isPublishable()`
  already refuses the original — but conversions live at derivable paths on a
  public disk, so generating them would put three more copies of a photograph
  still carrying a child's home coordinates where a URL can reach them
- Spatie fires `MediaHasBeenAddedEvent` *before* `createDerivedFiles()`, so in
  the normal case conversions are already cut from a cleaned original. This
  covers the case where the cleaning failed
- `scghf:regenerate-media-conversions` rebuilds them once the cause is fixed;
  dry by default, because rebuilding two thousand images is hours of CPU on an
  account that is sharing it

**Usage tracking, as a refusal rather than a display**
- `MediaUsage` reads the foreign keys pointing at `media` out of
  `information_schema` — **34 of them across 30 tables**, under a dozen column
  names (`media_id`, `image_id`, `photo_id`, `logo_id`, `cover_id`,
  `featured_image_id`, `og_image_id`, `photograph_id`, `evidence_media_id`,
  `pdf_media_id`, `cv_media_id`, `id_document_id`, `signature_id`, `document_id`)
- Discovered rather than declared, and this is the one registry in the project
  where that is the safer choice: **32 of the 34 are ON DELETE SET NULL**, so
  deleting an in-use file today does not fail and does not warn. A donation
  receipt loses its PDF. A beneficiary loses their ID document. A consent record
  loses the evidence it is evidence of — and still reads, to anybody auditing it
  later, as a consent that has evidence
- `Media::deleting()` refuses, in the model, so it holds for a console command
  and a cleanup script as well as for a button. There is deliberately no override
- A failure to answer counts as "in use". A wrong *no* silently detaches a
  receipt from its PDF; a wrong *yes* tells somebody to try again
- Confidential uses (beneficiaries, consents, safeguarding, volunteer
  applications) are counted but not named unless the asker holds
  `beneficiaries.view` — "this file is the evidence for Ama Mensah's consent"
  names a beneficiary to whoever is browsing a photo library

**Replace, keeping the id**
- `MediaLibrary::replace()` swaps the file behind a row. Delete-and-re-upload
  would leave thirty references pointing at the old row, and — those keys being
  SET NULL — some of them pointing at nothing
- Refuses to replace an image with a document, because thirty places expect an
  `<img>`
- Clears `metadata_stripped_at`, so the new file cannot inherit a clean bill of
  health issued for a different image
- Audited as `media.replaced`: nothing else records it, because every reference
  is unchanged while what a consent's evidence *shows* is now different

**Detect and degrade gracefully, for real**
- `ImageToolchain` asks the server rather than reading a belief out of config:
  Imagick or GD, WebP, AVIF, which optimiser binaries exist, and the PHP limits
  that are the actual ceiling
- Catches the quiet one: **`exec()` disabled**, which is common on shared
  hosting and makes spatie's optimisers fail *silently* — the chain runs, every
  binary fails to launch, and the library reports success having optimised
  nothing
- `IMAGE_DRIVER=auto` picks Imagick where the account has it. Imagick does not
  hold the decompressed bitmap inside PHP's `memory_limit` the way GD does,
  which on a 128MB shared account is the difference between a 6000px photograph
  converting and a white screen
- `scghf:media-doctor` reports all of it plus the inode budget, and exits
  non-zero when something needs attention. **Run it on the server** — every
  answer differs from the laptop's

**`x-media.image`**
- Renders `<picture>`-quality output from one tag: srcset across the generated
  widths only, `width`/`height` so the page does not reflow as images land (a
  Core Web Vitals budget this project has committed to), `loading="lazy"` for
  everything but the one image above the fold
- Refuses to render an unpublishable file at all, rather than trusting the page
  template that called it

#### Fixed

- **`MEDIA_MAX_UPLOAD_MB` was documented since Phase 2 and read by nothing.**
  Replaced by `MEDIA_MAX_IMAGE_MB` and `MEDIA_MAX_DOCUMENT_MB`, which are read —
  a photograph and a policy PDF are not the same size

### Phase 4 — Public donor accounts — 2026-09-04

Registration, sign-in, verification and password reset for donors — as full page
form posts through plain controllers rather than Livewire components. This is
the part of the site that has to work on a five-year-old Android phone on a 2G
fallback, and there is nothing here a form post does not do well.

**Nothing on this site requires an account.** A donor can give, get a receipt
and never come back, which is the majority path and the reason `donors` is a
separate table from `users`. An account exists to look at your own giving
afterwards. If registration ever appears between a donor and the payment button,
it is in the wrong place.

#### Added

**The front door**
- Register, sign in, sign out, confirm email, forgotten password, reset password
- An account area: overview with giving totals and recent gifts, editable
  details and communication preferences, and a security page
- `PasswordPolicy` — one policy in one place, twelve characters minimum and a
  Have I Been Pwned breach check by k-anonymity. **No character-class rules**:
  composition requirements produce `Password1!` on every site the person uses,
  and NIST SP 800-63B has preferred length over composition since 2017
- Honeypot on registration. A hidden field and a minimum fill time stops the
  volume bots without putting a CAPTCHA in front of the people least able to get
  past one

**Staff cannot sign in at the public form**
- The security decision in this module. Two-factor is mandatory for staff and it
  is enforced *inside Filament's login flow* — so a public form that
  authenticated a staff account would produce a fully authenticated session
  having shown one factor, and `canAccessPanel()` would then let it walk into
  the admin panel past the check with nothing visibly wrong
- Staff with the right password are redirected to the panel; staff without it
  get the same generic failure as anybody else, because "this is a staff
  account" is information about an address
- `LoginRequest::authenticate()` also constrains the attempt to donor accounts,
  so a change in the controller cannot quietly reopen the path
- Password reset excludes staff for the same reason

**The rate limits config/security.php has described since Phase 2**
- All of them were read by nothing. The registration form had no limit at all,
  and the comment explaining how carefully the numbers were chosen was
  describing something that did not exist
- Login is limited on **two keys at once**: address + IP narrowly, IP alone five
  times looser. On the address alone, anybody could lock a named donor out of
  their own account at no cost — a denial of service that looks exactly like the
  control working. On the IP alone, one shared mobile-network gateway is one
  bucket for thousands of people
- Throttled in `LoginRequest` rather than by middleware, because `throttle`
  cannot fire `Illuminate\Auth\Events\Lockout` — which is what puts the lockout
  in the login history and the audit trail — and applies a one-minute decay
  rather than the fifteen `lockout_seconds` sets

**Every account email goes through MessageDispatcher**
- `User::sendEmailVerificationNotification()` and `sendPasswordResetNotification()`
  are overridden. Laravel's defaults post straight to the mail channel, which
  would be a second way out of this application — one with no suppression check,
  no `email_logs` row and no share of the host's hourly cap
- Four new locked templates: `account.verify_email`, `account.password_reset`,
  `account.password_changed`, `account.new_device`
- They send immediately rather than through the outbox. A person is looking at
  their inbox right now, and a link that arrives ninety seconds later is a donor
  who has closed the tab. The hourly cap is still respected — `SendThrottle`
  counts rows in `email_logs`, and these write one
- **A password change emails the account holder**, whether it came from a reset
  link or from the security page. Nobody asks for that email, and it is the only
  thing that turns a silent account takeover into one the owner finds out about
- **A sign-in from an unrecognised device emails them too** — but never on the
  first sign-in of a new account, when every device is new and the alert would
  mean nothing. An alert that fires when it cannot mean anything is one people
  learn to dismiss, including on the day it matters

**Verification is what earns the giving history**
- `donors` is matched on email address. Attaching the record at registration
  would let anybody who types a known donor's address read what that person has
  given, and to what — so `User::claimDonorRecord()` refuses to run before
  `hasVerifiedEmail()`, and the dashboard is behind `verified`
- A record already claimed by another account is left alone. Two accounts on one
  address is a merge decision for staff, not something to resolve silently
  inside a request
- The security page is deliberately **not** behind `verified`: somebody who
  suspects the account was created by somebody else needs to change the
  password, and that must not require verifying an address they may not control

**Unticking a marketing box does something**
- The profile form writes both the column the campaign builder reads **and** a
  `marketing`-scope suppression, which is what `MessageDispatcher` checks on
  every message. Writing only the column would mean the box shows unticked and
  the next appeal goes out anyway
- Scope `marketing`, never `all` — an unsubscribe stops appeals, not receipts
- Ticking it back on releases **only** an unsubscribe. A hard bounce or a spam
  complaint on the same address stays: releasing a hard bounce because somebody
  ticked a checkbox sends mail to a mailbox that does not exist, and the damage
  lands on everybody else's receipts

**`login_histories` finally has a reader**
- The table has held sign-in records since Module 1 and nothing showed them to
  the person they are about. The account security page lists the last twenty
  attempts including the failures — a run of failures followed by one success is
  the shape of a password that was eventually guessed, and it is invisible if
  only successes are listed

#### Fixed

- **Every seeded email template was signing off "With gratitude," and every
  receipt subject read "Your donation to  — SCGHF-R…".** Three template globals
  in `config/communications.php` pointed at settings keys that do not exist —
  `general.site_name`, `general.site_url` and `organisation.legal_name`. An
  unresolved global collapses to an empty string rather than leaving a visible
  `{{token}}`, so the failure was silent by design and invisible in review
- `ProfileUpdateRequest` no longer clears the phone number when the field is
  absent from a request. A field that was not submitted is not a field that was
  cleared, and for donors who mostly pay by Mobile Money the number is the
  contact detail that matters most

#### Changed

- `bootstrap/app.php` sends already-signed-in visitors to `/account` rather than
  Laravel's default `/` — somebody who clicks a stale "Sign in" link and lands
  on the front page reasonably concludes the click did nothing
- Two new audit events, `auth.account_created` and `auth.password_changed`. Both
  are separate from the events they resemble because they are different facts: a
  reset is somebody who could not get in, a change is somebody who was already
  in, and the second one unexpected is what a takeover looks like from outside
- Header and footer carry an account control. It is a component rather than a
  menu item because it is session state, not content — the menu tables can
  already express guest-only and signed-in-only links if the foundation wants
  its own

### Phase 4 — The layout shell — 2026-09-04

Built against the menus `MenuSeeder` already produces, so the CMS rule holds
from the first render — the content is in the database, and Phase 5 adds the
editing UI on top rather than the shell waiting for it.

#### Added

**The theme system, and no flash of the wrong colours**
- `ThemeTokens` renders both palettes from `theme_settings` as CSS custom
  properties, **inlined in the head rather than compiled into `app.css`** — the
  palette is CMS content sampled from the logo pack, and compiling it would mean
  a deploy to change the brand colour
- Both themes always emitted, so a system flip or a toggle needs no round trip
- **A cookie as well as localStorage, and the cookie is the part that works.**
  localStorage is only readable by JavaScript, which runs after the HTML
  arrives — so with it alone the server always sends light, the browser paints
  it, and the script corrects it. That correction *is* the flash. The cookie
  lets the server put `.dark` on `<html>` in the bytes it emits
- The inline script covers the one case the server cannot know — `system` — and
  is deliberately **not deferred**: a deferred script runs after the paint
- **Three states.** `system` is a real choice, not "nothing recorded": somebody
  who picked dark stays dark when their laptop flips at sunset
- `color-scheme` on both, so scrollbars and autofill match rather than staying
  white on a dark page
- Token names and values are sanitised — they come from a column an
  administrator edits and land in an inline `<style>`, so a value closing the
  block would be stored XSS with the site's own blessing. `url()` is refused
  outright
- A fallback palette for when the table cannot be read. Not the brand colours,
  deliberately — a page rendering black on black because a query failed is worse
  than one rendering in plain greys

**The shell**
- Base layout with a skip link *before* the navigation, named landmarks, and a
  polite live region
- Header and footer rendered from the seeded menus via `Menu::renderable()`,
  which returns an empty collection rather than throwing — a fresh environment
  gets a bare header, not a 500 on the home page
- The donate CTA takes its destination from whichever menu item is highlighted,
  so a campaign can retarget it without a deploy
- Footer carries the registration number, registering authority and TIN — what a
  Ghanaian donor looks for, and whose absence is what a scam site has in common
  with a real one that forgot
- Three-state theme control as a `<select>`, because three states cannot be
  represented honestly by one cycling button
- `prefers-reduced-motion` respected as a blanket rule
- Branded 403/404/419/429/500/503 pages inside the site layout — an unstyled
  framework error on a donation site reads as "this is broken", which is the
  moment a donor abandons a payment

#### Changed

- Tailwind's `dark:` variant redefined against the `.dark` class. Its default is
  the `prefers-color-scheme` media query, which a toggle cannot override — a
  visitor on a dark laptop would have had no way to choose light
- `routes/web.php` serves a named `home` route; the `welcome` stub is gone

### Phase 4 — Policies, and the admin path — 2026-09-04

#### Added

**Policies for every model, enforced mechanically**
- `BasePolicy` maps the standard abilities onto the permission strings that
  already exist, so a concrete policy is usually three lines naming its prefix —
  and the ones that override something are therefore the ones worth reading
- **Deny by default.** An ability with no matching permission resolves to
  `false`, never to "nothing said no, so yes". The likeliest way this breaks is
  a permission renamed in the seeder and not in a policy; failing closed makes
  that a support ticket rather than a breach nobody notices
- A `.manage` fallback, so pages can keep `view`/`create`/`update`/`delete`
  while menus keep a single `menus.manage`, without forcing one shape onto both
- `PolicyCoverageTest` fails on **any model in neither the policy map nor the
  "authorised through its parent" list** — so "policies for every model" is a
  test rather than a matter of discipline. All 109 are accounted for

**Archetypes, where the rule is structural rather than per-resource**
- `AppendOnlyPolicy` — `delete` returns false for donations, payments, payouts,
  orders and inventory movements **whatever permissions somebody holds**. Not
  "no permission grants it": refused, so no future grant can turn it on. It also
  stops Filament rendering a "delete selected" checkbox that would throw a stack
  trace after somebody selected forty rows
- `ReadOnlyPolicy` — delivery logs, the audit trail, activity log, backup
  records, visitor counts. A log somebody can edit is not a log
- `PublishablePolicy` — `publish` as a first-class ability, so a contributor can
  draft and somebody accountable decides what goes out under the foundation's name

**Policies with rules of their own**
- `BeneficiaryPolicy` refuses hard deletion outright — not because the data must
  be kept, but because destruction is the retention runner's job, and it checks
  for a legal hold, writes the audit entry first, projects the anonymous record,
  and stores a one-way digest. A delete button would skip all five
- `ConsentPolicy` refuses **updates**. A consent that can be edited proves
  nothing; getting one wrong means capturing a new one, as it would on paper

**Permissions the policies needed and that did not exist**
- `suppressions.view` / `.release` — putting an address back into circulation
  after a complaint is not the same act as reading the list
- `messages.view` / `.cancel` — cancelling a queued message can stop a campaign
  mid-send
- `sponsorships.view` / `.manage` — its own permissions rather than riding on
  `beneficiaries`, since it is the one feature that deliberately discloses
  information about a child outside the foundation
- `audit.view` — and deliberately **no** `audit.manage`
- `compliance.view` / `.manage`, `api_tokens.manage`, `error_reports.*`,
  `visitor_stats.view`
- Auditor gains `audit.view` and `compliance.view`: the two records an auditor
  actually comes for, both read-only like everything else that role holds

#### Fixed

- **`ADMIN_PATH` moved off `admin`.** The panel is now at `/scghf-office` —
  `manage`, `backend`, `panel`, `console`, `dashboard` and `office` are all in
  the same wordlists `admin` is; a hyphenated organisation name is not
- **An empty `ADMIN_PATH` is refused rather than obeyed.** `ADMIN_PATH=` — a key
  somebody cleared rather than deleted — would have mounted the panel at `/`,
  turning the home page into a login form, silently, on deploy
- Paths that would collide with something already served (`webhooks`, `storage`,
  `livewire`, `up`, `api`) fall back too. Mounting there does not fail loudly; it
  wins or loses a routing race depending on registration order

### Phase 4 — Admin panel front door — 2026-09-04

#### Added

**The Filament panel**
- Path from `config/admin.php`, never hardcoded. `/admin` is the first path a
  scanner tries; moving it protects nothing on its own but takes the site out of
  the sweeps looking for a login form to spray credentials at
- Brand name resolved through a **closure**, so renaming the foundation in the
  settings screen takes effect immediately. Passing the resolved string would
  have frozen it at boot and quietly made the CMS rule false for the one piece
  of content on every admin page
- Colours read from the `theme_settings` tokens sampled from the logo pack —
  resolved at boot, because Filament compiles them into a CSS palette
- Both fall back safely: a panel that will not boot because a setting is missing
  is worse than one showing a placeholder, and `theme_settings` does not exist
  during `migrate:fresh`

**Two-factor authentication, required**
- Filament v5 ships TOTP, so no new dependency — which matters on a host where
  adding a package means a deploy
- Wired to the `two_factor_*` columns Module 1 already built, both already
  encrypted casts and both in `$hidden`
- `two_factor_confirmed_at` moves with the secret, so the two can never disagree
- Recovery codes enabled, because the alternative to a recovery code is a
  support call that ends in somebody disabling 2FA over the phone
- The authenticator entry is labelled with the **email**, not the name — staff
  hold more than one account here, and two entries reading "Ama Mensah" is a
  code typed from the wrong one at the worst moment

**Sign-in records, which had no writer**
- `login_histories` has existed since Module 1 and the `auth.*` audit events
  since Module 8, with nothing emitting into either. Both are written now
- A failed attempt records the address tried **including for addresses with no
  account** — a spraying run is only visible if the misses are recorded, and
  recording only the hits would make the log a list of valid addresses
- A lockout is recorded separately from a plain failure: failures happen to
  everybody, a lockout is an attack or somebody who needs help in five minutes
- New-device detection, used only to say "check it was you", never to deny

**Configuration that had been documented and ignored since Phase 2**
- `config/admin.php` reads `ADMIN_PATH`, `ADMIN_2FA_REQUIRED`,
  `ADMIN_SESSION_TIMEOUT`
- `config/security.php` reads every `RATE_LIMIT_*` key and `FORCE_HTTPS`
- `RecordAdminActivity` middleware ends an idle staff session — measured from
  the last request, so somebody working steadily is never interrupted

#### Fixed

- **`hasTwoFactorEnabled()` threw on any `User` whose column was not loaded** —
  which a freshly created user is. It now treats "not loaded" as "no evidence of
  2FA", which fails towards asking somebody to enrol
- **`isNewDevice()` queried the logging table outside the protected block**, so
  "logging never breaks a sign-in" was true of the insert and false of the
  lookup one line before it
- **`runningInConsole()` was the wrong guard for device detection** — Pest runs
  in console, so it disabled the whole path under test while looking correct in
  production. It asks whether there is a user agent instead

### Phase 3 — Gap sweep — 2026-09-04

A pass over every table named in the ERDs, every seeded permission, every
feature flag and every documented `.env` key, checked against what actually
existed. Seven gaps, all closed.

**The pattern is why `CLAUDE.md` now carries a standing rule about it.** Each
gap read as a *feature* to anybody auditing the code — a permission that grants
nothing, a flag switched on with nothing behind it, an env key annotated "never
optional" that nothing reads. That is worse than an obvious absence, because
somebody has looked at it and believed it.

#### Added

**EXIF and GPS stripping** *(`MEDIA_STRIP_EXIF` had been documented since Phase
2, annotated "never optional", read by nothing)*
- Stripped synchronously on upload, not queued — the cron queue would leave a
  window in which the unsanitised original is on disk and reachable
- `Media::isPublishable()` refuses anything unsanitised; null means "not yet"
- Key names recorded, never values. `had_gps_data` kept as a safeguarding signal
- Turning the switch off makes publishing **impossible**, not easier

**Inbound delivery webhooks** *(`markBounced()` existed with nothing to call it)*
- `inbound_webhook_events`, on the same terms as `payment_webhook_events`
- A provider with no configured secret verifies as **false**, never true
- Postmark's `SubscriptionChange` direction is read, not assumed — the obvious
  mapping would have silently removed people who had just opted back in

**The audit archive** *(open question 16, answered)*
- `audit_archives` + `scghf:archive-audit-log`. Whole closed years to a
  compressed, hash-verified file outside the web root
- The chain continues across the gap; a gap with no **pruned** archive is still
  reported as tampering
- Write and verify before deleting anything

**`pledges` and `payouts`** *(named in §2.4, never built)*
- A pledge is not income, and lives apart so no total can accidentally include it
- Payouts need two people — the requester may not approve — and evidence

**`fundraisers`** *(promised in a Module 4 migration comment, never added)*
**`sponsorships`** *(the flag was **on** with nothing behind it)*
**`event_tickets`, `product_reviews`** *(`reviews.moderate` was seeded over a
table that did not exist)*

#### Fixed

- **The audit verifier accepted the first entry's `previous_hash`
  unconditionally**, so deleting the oldest entries would have passed. It now
  checks that against null, a pruned archive, or an explicit `--from`
- **GD stamps `CREATOR: gd-jpeg` into every JPEG it writes**, so counting that
  as metadata made every sanitised image look unsanitised again — re-encoded on
  every pass, losing quality each time, never converging

### Phase 3 — Module 8, System — 2026-09-04

**Phase 3 complete.** Eight modules, ~150 tables, 973 tests, 2014 assertions.

#### Added

**An audit trail that records reads, not just writes**
- `audit_logs` captures ACTIONS, including the ones that change nothing —
  reading a beneficiary's medical history, exporting donor records, running the
  retention sweep. spatie/laravel-activitylog records model *changes*; none of
  those are changes, so until now none left a trace
- Events are declared in `config/system.php` with a category and a severity, and
  recording an undeclared one **throws** — a new export screen has to be
  classified before it can log
- **Volume escalates severity.** One donor record viewed is somebody doing their
  job; two thousand exported is a question that needs asking the same day
- Append-only and **hash-chained** — an edit or a deletion breaks every hash
  after it, and `scghf:verify-audit-log` names the first break. It makes
  tampering *detectable, not impossible*; the head hash is anchored to the
  application log on every clean run, which is what an auditor can check against
- Impersonation is recorded separately from the person impersonated
- The actor's name is snapshotted, so the trail survives them leaving
- **Auditing never breaks the action it audits** — a failed write goes to the
  log off-database and the caller proceeds

**API tokens**
- Hashed and shown once; a leaked database is not a leaked set of credentials
- **No abilities by default**, and a mistyped ability is refused rather than
  silently granting nothing
- Beneficiary, safeguarding, donor and refund abilities can never be granted
- A **mandatory expiry**, with an expiring-soon list so an integration does not
  stop working on a Saturday with nobody knowing why

**Errors, grouped**
- Keyed by a fingerprint of class, file, line and the message with its variable
  parts normalised, so a failing page does not produce a row per visitor
- **Nothing from the request body, ever.** Route parameter names, never values
- Ordinary traffic — 404s, failed logins, validation — is not an error
- A fault that recurs after being resolved **reopens**

**Backups**
- `backups_log` records **restore tests** as first-class rows. One with no row
  count verified nothing; one with no named verifier is an assertion. Both are
  refused
- `healthWarnings()` says "no backup has ever been restored and verified" —
  which is true, and stays true until somebody does one
- `file_count` beside `size_bytes`, because shared hosting counts inodes

**Visitor statistics**
- Aggregate **by construction**: no IP, no fingerprint, no cross-site
  identifier, no per-visitor row. A test asserts the exact column list
- Counts after the response and swallows every failure — statistics are never
  worth a millisecond on a 3G connection, and never worth an error page
- Path only, never the query string, which is where tokens and email addresses
  live. Referring host, never the full referrer
- **This application cannot report unique visitors**, and that is the trade

**Feature flags**
- The database overrides a flag; it cannot invent one. `config/features.php`
  stays the source of truth for which flags exist
- Every override carries a **reason** — required for switching on as much as
  off — and an **expiry**, which is what stops a temporary measure becoming
  permanent
- A lapsed override is simply not loaded, so nothing has to run to expire one
- `donations` is locked against admin-panel override
- Falls back to config when the table cannot be read

**Settings history**
- Hooked into the `Setting` model, not into Filament, so it holds however the
  value is changed
- Encrypted settings record *that* they changed, with both values redacted
- `valueAt()` answers "what did the receipts we issued in March say?"
- No foreign key to `settings`, so the history survives the setting

#### Notes

- New commands: `scghf:verify-audit-log` (daily, quiet when clean),
  `scghf:sms-delivery-reports` (hourly), plus a daily error-table prune
- Two open questions recorded: who performs the quarterly restore test and
  where, and where the audit trail is archived from year three

### Phase 3 — mNotify — 2026-09-04

#### Added

- **`MnotifyGateway`** — the SMS provider the foundation chose, sending under the
  registered sender ID `GreaterHope`. The only class that talks to mNotify, on
  the same terms as `PaystackService`
- **Accepting is never read as delivering.** mNotify accepting a message says
  only that mNotify accepted it; an unregistered or lapsed sender ID is accepted
  by the provider and dropped by MTN/Telecel/AT with no error returned anywhere
- An **unrecognised response code is a failure**, not a shrug. Treating an
  unknown response as "probably fine" is how a provider changing its API goes
  unnoticed until somebody asks why nobody got their receipt
- Account-level failures — no credit, bad API key, rejected sender ID — are told
  apart from message-level ones and logged `critical`. They break *every*
  message, and the queue would otherwise fail quietly one message at a time
- Remaining SMS credit is read from every send and warned on below a threshold.
  Credits are pre-paid and running out is silent
- **`scghf:sms-delivery-reports`**, hourly. The only way a dead sender ID becomes
  visible: it looks like a total collapse in delivery while sending continues to
  report success. Below 60% delivered over 20+ reported messages it exits
  non-zero, naming the sender ID, so cron emails somebody
- `ReportsDelivery` contract, separate from `SmsGateway` because `log` genuinely
  cannot answer the question

#### Changed

- `SMS_SENDER_ID` default is now `GreaterHope` — eleven characters, exactly the
  GSM maximum, and it must match the registration including casing
- Production refuses to boot with `SMS_DRIVER=mnotify` and no `MNOTIFY_API_KEY`.
  That configuration cannot send a single message, and finding out one failed
  receipt at a time is the expensive way
- `SMS_DRIVER` stays `log` until the key is in the server's `.env`

#### Notes

- Still worth confirming with mNotify before the first live send: that the
  registration covers all three networks, and that the delivery-report response
  shape matches what the gateway parses. The parsing is defensive and degrades
  to "still don't know", but a verified shape beats a defensive one

### Phase 3 — Module 7, Communications — 2026-09-03

#### Added

**The suppression list, as a gate rather than a report**
- `suppressions` — one row per address per channel, and every send checks it.
  Blueprint risk DEL-4: without it, bounces accumulate until the sending domain
  stops being delivered, and the first casualty is the donation receipt
- **Suppression has a scope.** `all` stops everything including receipts;
  `marketing` stops appeals only. An unsubscribe is `marketing` — somebody who
  no longer wants appeals has not asked to stop receiving the record of a gift
  they just made
- Suppression only ever **strengthens** automatically. Coming off the list needs
  a named person and a recorded reason; an address suppressed under an Act 843
  objection cannot be released at all
- Addresses are **normalised** before storage — lowercased email, E.164 phone.
  `Ama@Example.com` and `ama@example.com` are one mailbox, and five spellings of
  a number are five chances to text somebody who asked not to be
- Deliberately **excluded from the retention sweep**. Forgetting that somebody
  objected is how they start receiving mail again after asking not to

**Templates, editable but not everywhere**
- `email_templates` and `sms_templates`, keyed, CMS-editable, with declared
  variables. The seeder refreshes structure on every deploy and **never**
  overwrites wording the foundation has changed
- The GRA acknowledgement paragraphs stay in `config/compliance.php` and reach
  the template as one `{{acknowledgement}}` variable — nobody can reword a
  statement made under s.97 of Act 896 by editing an email in a browser
- Rendering **refuses** on a missing required variable. "Dear ," cannot be
  recalled; a failed job can be fixed in five minutes
- Placeholders are not Blade. A database column rendered as Blade is arbitrary
  PHP execution one compromised admin account away from being somebody else's
- A **locked** template can be reworded but not deactivated or deleted. The
  failure guarded is quiet: somebody tidies the list, receipts stop, nothing
  reports an error
- Subject lines are stripped of newlines — a donor-supplied name containing one
  turns whatever follows into a header of its own

**SMS, costed before a provider exists**
- `SmsSegmenter` measures encoding, septets and segments properly: GSM-7
  extension characters cost two, emoji cost two UCS-2 units, and a multipart
  message loses seven bits per segment to the concatenation header
- **The cedi sign is not in GSM-7.** `CLAUDE.md` mandates "GH₵ 1,234.56" for
  display, so the correct format for the website roughly triples the cost of
  every SMS sent from a template containing it. `SmsTemplate` refuses to save a
  template that blows its segment budget, and names the character responsible
- `PhoneNumber` normalises every way a Ghanaian number gets typed to E.164, and
  **refuses** a nine-digit pre-2010 number rather than guessing — the migration
  inserted a digit, and a guessed number sends a receipt to a stranger
- `SMS_DRIVER=log` writes a complete, segmented, network-attributed, costed row
  and sends nothing, so a month of running the site estimates what SMS will cost
- `sent` is never read as `delivered`. An unregistered sender ID is accepted by
  the provider and dropped by the network with no error anywhere

**Logs, the outbox and the throttle**
- `email_logs`, `sms_logs`, `notification_logs` — every attempt recorded
  **including the refusals**. A suppressed receipt produces a row explaining
  itself so Finance can post it or hand it over
- `scheduled_messages` is the outbox: visible, cancellable, priority-ordered and
  **expirable**. A backlog on this host is measured in days, and a queue that
  delivers "the event is tomorrow" three days late is worse than one that
  delivers nothing and says why. Receipts never expire
- Claimed under `lockForUpdate` with a TTL, because cron starts a worker every
  minute and the previous one may still be running
- `idempotency_key` is unique — a replayed webhook collides instead of producing
  a second receipt
- **The throttle is a `COUNT`, not a counter.** It counts rows in `email_logs`
  with `sent_at` inside the window: atomic without a lock, self-correcting after
  a killed worker, and incapable of drifting from what was actually sent
- `scghf:send-messages`, scheduled every minute, drains what the host's hourly
  cap allows and stops. Exits non-zero when the oldest message has waited six
  hours — the number that actually matters here, not the queue length

**Newsletters** *(deferred out of Module 6; they needed templates and suppressions)*
- `newsletters`, `newsletter_campaigns`, `campaign_recipients`, plus three
  seeded lists so `subscribers.topics` means something concrete
- **Consent is re-checked per message, not per campaign.** At two hundred an
  hour a campaign takes most of a day; somebody who unsubscribes in hour three
  has unsubscribed
- Two gates before a campaign can go: a **test send** must have happened, and an
  **approval** recorded by somebody holding `newsletter.send`. Editing the
  content afterwards withdraws both
- A campaign can be **paused** mid-flight — only meaningful because 1,600
  messages are still waiting
- Every campaign email carries an RFC 8058 one-click unsubscribe, and a
  marketing message with no working unsubscribe link is **refused** rather than
  sent without one
- Skips are recorded with a reason rather than deleted, so "why did I not get
  the newsletter?" has an answer

**Retention and privacy**
- New class `communication_log` — 24 months from send, then deleted. Delivery
  evidence has a shorter life than the financial record it relates to, which
  lives under `financial_record` independently
- Open and click tracking are **off**. Recording that a named person read a
  message is Act 843 processing needing its own lawful basis; the columns exist
  so enabling it is a config change, not a migration

#### Changed

- `bootstrap/providers.php` registers `CommunicationServiceProvider`, which
  refuses to boot production with an over-length SMS sender ID

#### Notes

- 895 tests, 1867 assertions. `config/communications.php` carries the policy;
  three open questions are recorded in `docs/PHASE-3-DATA-ARCHITECTURE.md`
- Only the `log` SMS driver is implemented. A stub that silently succeeded would
  be worse than none, because `log` at least tells the truth about what it did

### Phase 3 — Module 6, Engagement — 2026-09-03

#### Added

**Safeguarding, as a gate rather than a policy**
- `VolunteerApplication::approve()` **refuses** while any required check is
  outstanding, and names which ones. The failure guarded is not malice — it is
  approving a keen volunteer "and doing the police check next week"
- `involves_vulnerable_contact` defaults to **true**; an application with no
  opportunity is treated the same. A default of false would mean every role
  somebody forgot to configure quietly skipped its checks
- Roles with no contact get a lighter set — requiring a police check to hand out
  leaflets turns the requirement into a formality, and a formality is not a
  safeguard
- `safeguarding_checks` is one **row** per check with a reference, a date and a
  named verifier. A pass without either is refused; a waiver needs a stated
  reason *and* an authoriser
- **Clearances go stale.** A police certificate carries an expiry and
  `isCurrentlyCleared()` is computed from the dates, not read from a cached flag
- A concern **suspends immediately** — before investigation, without implying a
  finding. It insists the concern is written down, and insists on a written
  outcome before reinstating

**Volunteers**
- Hours in **minutes**, capped at a day, future-dated entries refused
- Only **verified** hours count towards the figure a funder is shown, and nobody
  can verify their own

**Events**
- Capacity counts **people, not bookings** — three guests take four places
- Over capacity **waitlists** rather than refusing; registration runs under a row
  lock so two people cannot both take the last two places
- `photography_consent` is **nullable with no default**: "never asked" and "said
  no" are different answers, and only the wrong one can be inferred from silence
- Three separate consents — photography, event contact, newsletter. A
  registration is not a mailing list
- Ticketing is feature-flagged off and the model refuses to mark an event
  ticketed while it is

**Prayer requests**
- **Confidential by default.** `is_confidential` true, `consent_to_publish`
  false, and publication needs both cleared. Enforced on every save, so
  `forceFill` cannot walk past it
- Anonymous by default even *with* consent — being happy for a situation to be
  prayed about publicly is not being happy to be named in it
- An anonymous request has its contact details stripped on creation

**Retention**
- New classes: declined volunteer application (12 months), withdrawn (6),
  volunteer record including safeguarding (72, sensitive), event registration
  (24 from the event ending), prayer request (12 from submission, sensitive)
- Accessibility and dietary needs classified as **health data**; a prayer
  request's text likewise

⚠ **The volunteer retention periods are defaults, not advice** — recorded as an
open question rather than presented as settled.

817 tests, 1722 assertions.

### Phase 3 — Module 5, Shop — 2026-09-03

#### Added

**Catalogue and the FDA guard**
- Every product save screens name, summary and description against
  `compliance.shop.prohibited_keywords`. A flagged product cannot go live until
  somebody records a review **with a reference** — "we looked at it" is not a
  review an auditor can follow up
- Screening runs on **every** save, so a published mug edited to mention a
  supplement is caught. An existing review is cleared when new flags appear that
  it did not cover
- A newly flagged save **unpublishes** rather than refusing — refusing would
  discard the editor's work and leave the older text live. An explicit
  `publish()` still throws, naming the keywords and the regulator
- Word-boundary matched: "creamery" does not trip "cream", "drugstore" does trip
  "drug"
- The taxonomy is **seeded from the compliance policy**, not duplicated, so the
  two cannot drift. Categories outside the agreed list are visibly marked

**Stock as an append-only ledger**
- `inventory_movements` with a reason on every row; `stock_on_hand` is a cache
  and `recalculateStock()` rebuilds it. An adjustment without a note is refused
- Holds reserve the shelf at **order time**, not at payment, so two customers
  cannot buy the last mug while both sit on the payment page

**Orders, on the shared payment path**
- `Order` is a `Payable` — same gateway, same verification, same mismatch
  handling. Two payment paths is how a ledger diverges
- `order_items` snapshot name, SKU and unit price; the variant FK is nullable
  with `ON DELETE SET NULL`, so a discontinued product takes no history with it
- Line totals are **derived**, never accepted from the caller
- Totals reconcile — lines to subtotal, and subtotal + shipping − discount to
  total — before the gateway is called
- Status history is append-only and attributed; a system change is marked as
  such rather than shown as nobody
- A paid order cannot be cancelled outright: the money has to go back, and a
  refund is its own record with its own approval

**Invoices — the separation rule, both directions**
- `SCGHF-INV-…`, its own counter table, never the `SCGHF-R-…` donation series
- `InvoiceIssuer` **asserts** before writing that the order could not lawfully
  receive a charitable acknowledgement
- Every invoice states in words that it is not a donation acknowledgement and
  cannot support a section 100 claim

**Shipping, coupons, downloads**
- All sixteen Ghanaian regions covered, seeded **inactive with no rates** — the
  regions are a fact, the prices are a commercial decision
- Collection is a zone with a zero rate, not a branch in the checkout
- Free-delivery thresholds compare against the **subtotal**, not the total
- Coupons in basis points, capped, never larger than the basket; a refused code
  returns a reason
- Digital downloads are long random tokens with an expiry and a use limit

#### Fixed
- **Abandoned checkouts stranded their stock.** Reconciliation marked the
  transaction abandoned but told the payable nothing. `Payable` gains
  `onPaymentAbandoned()`; a donation is marked abandoned, an order puts its
  goods back on the shelf
- `stock_held` is UNSIGNED, so `stock_held - 1` underflowed before
  `GREATEST(0, …)` could clamp it — a repeated release errored instead of being
  a no-op. Cast to SIGNED first

#### Changed
- `App\Models\Order` removed from `TaxDeductibility`'s pending-module list now
  that the class exists — keeping it would silence the warning that should fire
  if the model is ever renamed

746 tests, 1562 assertions.

### Phase 3 — Module 4, Fundraising — 2026-09-03

#### Added

**The gateway boundary**
- One payment path for donations *and* shop orders, via a polymorphic payable.
  Two payment paths is how a ledger diverges from the gateway
- **Webhook is truth.** The redirect only says the donor came back; the
  HMAC-authenticated endpoint says the money arrived. Raw body, `hash_equals()`,
  stored before parsing, queued, 200 returned immediately
- Answers **200 even to a forged request** — Paystack retries anything that is not
  2xx, so a 401 turns a probe into a retry storm. The exception is a failure to
  *store* the event, which returns 500 so it is not lost
- Idempotency lives in the database: `payment_webhook_events.event_id` is UNIQUE,
  so a replayed `charge.success` is a no-op even if the PHP handling it is wrong.
  An event with no id falls back to a hash of its body
- `PaymentTransaction` stores **expected** and **actual** separately. A mismatch
  becomes `mismatch` — not `success` (wrong figure) and not `failed` (the money
  may have been taken). It alerts and waits for a human
- `FeeCalculator` holds the rate in basis points, never a float. Gross-up solves
  `charge − fee(charge) = intended` rather than adding the fee to the original,
  which always leaves the foundation short. Property-tested at 18 amounts
- Card data: allow-listed authorization fields, a refusal to store more than four
  digits, and a scrubber that redacts card-shaped **values** at any depth
- Refunds are their own rows, need a stated reason, cannot exceed what remains,
  and **cannot be approved by the person who requested them**
- `FakeGateway` makes the module buildable before the merchant account exists —
  and verifies signatures with the same HMAC-SHA512, because faking that would
  leave the most security-critical line untested. **Production refuses to boot**
  on the fake driver or a test key

**Donations**
- Append-only, enforced: a completed gift refuses to have its amount, cause,
  reference, currency or deductible subtotal changed, and none can be deleted
- `donation_items` for **every** gift. A GH₵ 500 donation can be GH₵ 300
  deductible and GH₵ 200 not, so the subtotal is a sum over items
- Items are reconciled against the total **before** the gateway is called
- `is_tax_deductible` is snapshotted from `TaxDeductibility` and cannot be
  rewritten. A gift stays deductible after the approval lapses — the
  acknowledgement in the donor's hands must not change meaning
- Settlement is idempotent under a row lock; totals increment atomically in SQL
- Donors are separate from users, matched by email then normalised phone, never
  by name. Their details are snapshotted onto each gift
- Consent captured per channel with its Act 843 evidence

**Acknowledgements**
- Sequential per financial year with **no gaps**, from a counter row under
  `lockForUpdate` — not an AUTO_INCREMENT, which burns a number on a rolled-back
  insert
- Every figure and sentence **snapshotted**, never re-rendered
- The tax wording covers the **deductible subtotal**, not the gross
- Refuses a gift that has not completed, a payable on the never-acknowledge list,
  and a missing Foundation TIN

**Recurring giving**
- ⚠ **The mobile-money caveat is surfaced, not hidden.** Recurring charges need a
  reusable authorization; Paystack issues those readily for cards but not
  reliably for MoMo — which is how most Ghanaian donors pay. The default is
  conservative, blocked subscriptions are **reported** in the run summary and as
  skipped charge rows, and the decision is the foundation's to make
- Two drivers: `gateway` (Paystack owns the schedule) and `managed` (we charge a
  stored authorization from cron). A gateway-driven one is never charged locally
- Overlapping cron runs cannot double-charge — unique on
  `(subscription_id, scheduled_on)`
- One decline sets `failing`; three consecutive failures pause it. Any success
  clears the counter. A cancelled commitment refuses to resume
- Deductibility snapshotted fresh per cycle; every cycle gets its own receipt

**Offline gifts and reconciliation**
- Cash, cheques and bank transfers go into the **same** ledger and number series
- Reconciliation recovers payments the gateway settled that the site never heard
  about, and verifies once more before writing off an abandonment
- Mismatches, unprocessed webhooks and missing acknowledgements are reported,
  never auto-fixed. `--series` finds gaps in a year's receipt numbers
- Both commands registered in the **scheduler**, not as new cron lines. The
  retention sweep is scheduled **dry**

#### Fixed
- **The donation append-only guard did nothing.** It compared
  `getOriginal('status')` — a cast enum — against a string. `getRawOriginal` now
- `received_on` had no date cast; settlement was copying the offline method onto
  the donation's `channel`

646 tests, 1357 assertions.

### Phase 3 — Module 3, Programmes — 2026-09-03

#### Added

**Divisions and projects**
- `divisions` is the spine — the four divisions from the foundation profile, seeded
  and **locked**: projects, causes, donations and years of reporting hang off them,
  so deletion is refused and deactivation offered instead
- Each division names a **theme token** for its colour, never a hex. A hex in a data
  column would bypass `theme_settings` and its AA contrast validation exactly as a hex
  in a Blade template would; a test asserts every division names a token that exists
- `division_id` is nullable everywhere and **null means foundation-wide, not unknown**.
  `forDivision()` therefore includes the shared rows by default. Foreign keys are
  `ON DELETE SET NULL`: content outlives the structure
- The eight CMS tables Module 2 deferred gain `division_id` **with its foreign key**,
  as that migration promised — expand-only, constraint and column together
- `projects`, `project_updates`, `project_milestones`, `project_locations`, and pivots
  to focus areas, partners and documents. Budgets are integer pesewas through
  `MoneyCast`; a float budget is refused rather than silently stored a hundredfold low

**Causes and impact**
- `causes` is the fundraising unit, `projects` the work — separate tables, because a
  cause can fund several projects and a project can run with no appeal behind it
- **General Fund** seeded and locked, so a gift made with no appeal chosen still has a
  destination. `Cause::generalFund()` throws rather than returning null
- Per-division funds seeded as drafts with no copy: the foundation writes its own
  appeal text
- Progress is not clamped at 100% — an appeal that raised 140% should say so
- `impact_metrics` + `impact_metric_values` as a **time series**, not a running total,
  with who verified each figure and when

**Beneficiaries, consent and analytics**
- **Two datasets.** `beneficiaries` is operational and destroyed at retention expiry;
  `beneficiary_impact_records` is anonymous, projected at case closure, and outlives
  it. `source_beneficiary_id` is `ON DELETE SET NULL`, so the linkage is severed in
  the same statement that destroys the case record
- **Closure starts the retention clock; it destroys nothing.** An open case has no
  anchor date, which is what stops a live case being swept up
- `consents` — polymorphic, evaluated from its dates. A story needs story consent; a
  photograph needs photo consent as well; a real name needs name-use consent on top.
  Enforced in the model's saving hook, so `update(['is_published' => true])` cannot
  walk past it. `missingConsents()` says *which* consent is missing
- A minor's consent is refused unless it names the guardian who gave it, and a minor
  cannot consent on their own behalf
- Sensitive documents get 24 months against the case record's 72, and a medical or
  identity document is sensitive whatever the flag says
- `Beneficiary::privacyElements()` classifies every column; a test fails on any column
  that maps to nothing

#### Changed
- **The GRA acknowledgement wording.** The document is an *acknowledgement of
  contribution/donation to a worthwhile cause*, not a "tax-deductible receipt" — the
  Foundation acknowledges the gift, the GRA decides the deduction. Three paragraphs:
  s.97 status (only while a valid approval is held), the acknowledgement itself with
  the amount in figures and words, and the mandatory s.100 disclaimer
- **Approval validity is evaluated against the donation date, not today.** A receipt
  reprinted after an approval lapses still cites what was true when the gift was
  received; a gift received before approval never acquires it retroactively; a revoked
  approval is never cited
- **`causes.is_tax_deductible` is necessary but not sufficient.** Deductibility also
  requires a current written GRA approval; `qualifiesForTaxRelief()` delegates to the
  single gate rather than reading the column
- **The de-identification boundary gained a third disposition.** Destroy / generalise /
  keep, because an exact amount and an exact day beside a division and a district
  identify a person with no name in the row. Community, case reference and payment
  reference moved to destroy
- `AmountInWords` is pure PHP rather than `ext-intl` — intl is not guaranteed on shared
  cPanel hosting, and a legal document whose wording changes when the host upgrades PHP
  is not acceptable

#### Fixed
- **Fixture tables in tests caused a full `migrate:fresh` before every test.** MySQL
  implicitly commits on DDL, ending the transaction `RefreshDatabase` wraps each test
  in; Laravel then resets its migrated flag, re-migrates, drops the fixture table, and
  the test recreates it. Thirteen seconds per test. Fixture tables moved to
  `tests/database/migrations`, loaded only in the testing environment
- **`de-identify` was not idempotent** — a NOT NULL column got a fresh random redaction
  marker on every run. Already-redacted values are now skipped
- **`AGGREGATION_LATEST` returned the earliest figure.** The `values()` relation carries
  an ascending order for display, and appending `orderByDesc` does not override it

468 tests, 982 assertions.

### Phase 3 — Database architecture — 2026-09-02

#### Added

**Money foundation**
- `App\ValueObjects\Money` — integer minor units plus a currency, immutable. No float
  anywhere inside it and no public way to get one out: `percentage()` uses bcmath,
  everything else is integer arithmetic, `toMajorString()` returns a string
- `allocate()` / `allocateEvenly()` distribute the rounding remainder one pesewa at a
  time, so split or designated giving reconciles to the total exactly
- `App\Casts\MoneyCast` — refuses anything that is not a `Money` or an integer of
  minor units, so a stray `50.00` cannot be written as if it were 50 pesewas
- 53 tests including the cases that justify the class: `0.1 + 0.2` summing to exactly
  30 pesewas, and 1,000 additions of GH₵ 0.07 landing on exactly GH₵ 70.00

**Data architecture** — `docs/PHASE-3-DATA-ARCHITECTURE.md`
- ~150 tables across eight modules, with cardinalities, in dependency order
- Conventions: `BIGINT` internal key + ULID public identifier + human reference;
  `BIGINT UNSIGNED` pesewas; `VARCHAR` + PHP enum rather than MySQL `ENUM`; indexed
  string columns sized against the utf8mb4 3072-byte index limit
- Financial tables are append-only — corrections are new rows, never edits
- One payment path for donations *and* shop orders via a polymorphic payable
- Binding migration-safety policy: expand-only within a deploy, because migrations
  run before the release symlink flips and `rollback.sh` does not undo schema

**Module 1 — Core identity & authorisation**
- `users` carries staff and donors; `login_histories` records failed attempts too
- Ghanaian phone numbers normalised to E.164 on write, raw input retained
- `UserType` / `LoginOutcome` enums, `User` and `LoginHistory` models, factories
- `RoleAndPermissionSeeder` — 101 permissions across 10 roles, encoding the
  Blueprint §7.1 capability matrix as data. Idempotent, so it runs on every deploy
- 72 tests, 135 assertions, verified against real MySQL 8.4

#### Fixed
- **A deactivated user kept every permission their role granted.** The suspension
  guard was a `Gate::before`, but spatie/laravel-permission registers its own and
  package providers boot before app providers — so spatie returned true for a held
  permission and short-circuited ours. The guard now lives on the model as a
  `hasPermissionTo()` override, which every path routes through. The
  suspended-super-admin test had been passing for the wrong reason.
- **The Admin role was granted `payments.view_keys`**, contradicting the comment
  beside it: `fundraising.*` sweeps up the whole group. Added a `!permission`
  negation applied after wildcard expansion, with a test asserting each holds.
- Dropped a redundant standalone index on `users.type` — leftmost column of both
  composites, so it cost write time for no read benefit.

#### Local environment
- MySQL 8.4.9 initialised and bound to `127.0.0.1` only. The winget package installs
  binaries but never runs Oracle's configurator, so there was no data directory and
  no service; `scripts/dev-mysql.ps1` starts it without administrator rights
- `phpunit.xml` points the suite at MySQL rather than SQLite, so schema constraints
  SQLite does not enforce are caught locally
- `tests/Pest.php` added — `pest:install` had never been run, so Feature tests were
  not bound to Laravel's `TestCase`

### Phase 2 — Environment, repository & deployment pipeline — 2026-09-02

#### Added

**Repository scaffolding**
- `.editorconfig`, `.gitattributes` (LF everywhere, `export-ignore` so docs and CI never ship to the server)
- `.gitignore` covering secrets, built assets, backups and SQL dumps — donor data must never reach Git
- `.gitmessage` commit template with the `type(scope): subject` convention
- `pint.json` — Laravel preset with project overrides
- `README.md`, `CHANGELOG.md`

**Environment**
- Complete `.env.example`: app, logging, database, session/cache/queue, filesystem and media,
  mail (incl. bulk throttling), SMS (driver-agnostic), Paystack, money policy, security,
  backups, SEO/indexing, 16 feature flags, Vite
- Every key annotated with where its value comes from — cPanel, Paystack, SMS provider, or a project decision

**CI/CD**
- `.github/workflows/ci.yml` — PR gate: Pint, migrations against real MySQL 8, Pest,
  Vite build, **gzipped asset budget (100 KB JS / 50 KB CSS)**, secret scan
- `.github/workflows/deploy.yml` — `main` → production, `develop` → staging.
  Builds `vendor/` and assets on the runner so the server needs neither Composer nor Node.
  rsync over SSH port 2222 → migrate → warm caches → atomic symlink flip → smoke test →
  automatic rollback on failure
- `.github/PULL_REQUEST_TEMPLATE.md` with conditional checklists for money/auth/webhooks,
  UI and dark mode, uploads and beneficiary consent, and migrations
- `.github/dependabot.yml` — weekly grouped updates targeting `develop`; major framework
  bumps excluded as planned work

**Deployment**
- `deploy/scripts/bootstrap-server.sh` — idempotent one-time server setup: directory skeleton,
  shared storage, PHP binary and extension check, deny-all `.htaccess` on the app root,
  docroot symlink with automatic backup of existing content
- `deploy/scripts/activate.sh` — the atomic release switch. Verifies the upload, links shared
  state, **refuses to deploy to production with `APP_DEBUG=true` or a Paystack test key**,
  migrates, warms caches, generates environment-appropriate `robots.txt`, sets permissions,
  flips the symlink, prunes to 5 releases
- `deploy/scripts/rollback.sh` — one symlink move back, with an explicit warning that
  migrations do not roll back with the code
- `deploy/cpanel/cron.txt` — scheduler and `flock`-guarded queue worker for production and staging
- `deploy/cpanel/.cpanel.yml` — documented fallback, with its costs stated

**Server hardening**
- `public/.htaccess` — HTTPS and canonical-host redirects, dotfile blocking (with `.well-known`
  preserved for AutoSSL), sensitive-file denial, `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Permissions-Policy`, cross-origin policies, CSP in report-only mode,
  Deflate/Brotli, immutable caching for fingerprinted assets, WordPress probe blocking.
  HSTS present but commented until SSL is confirmed on every host

**Documentation**
- `docs/PHASE-2-RUNBOOK.md` — 11 steps, every cPanel click and terminal command, with
  expected output and a failure table for each
- `docs/DEPENDENCIES.md` — justification per package, shared-hosting risk, rejected
  alternatives, and three decisions still open

#### Decisions recorded
- Deployment strategy **A** (GitHub Actions → SSH/rsync) confirmed viable; SSH enabled on port 2222
- `robots.txt` generated at deploy time from `APP_ENV` rather than committed — staging can
  never inherit production's file, and a static file survives a 500 where a route would not
- Migrations run **before** the symlink flip, so a failure leaves the live site untouched.
  This makes expand-only migrations a hard requirement
- `PAYMENT_DRIVER=fake` and `SMS_DRIVER=log` are the defaults, so Phases 3–8 proceed
  without Paystack or SMS credentials

#### Local toolchain — resolved
- Herd 1.30.0 installed (winget `BeyondCode.Herd`; the ID is not `Laravel.Herd`). PHP **8.4.25**
  and Composer **2.10.2** confirmed, all 16 required extensions present.
- Composer has no winget package; the winget PHP packages ship with every extension commented
  out. Runbook Step 0 rewritten with both routes and a tested `php.ini` extension-enabling script.
- **`config.platform.php` pinned** so Composer resolves the lockfile for the server, not the
  workstation — see the stack decisions below for the final value.

#### Application installed
- Laravel **13.30.1** skeleton generated and merged over the Phase 2 overlay with
  `robocopy /XC /XN /XO` (copy-if-absent), preserving every Phase 2 file byte-for-byte
- Filament **5.7.8** · Livewire **4.4.3** · Pest **5.1.3** · PHPUnit **13.3.1** ·
  spatie: permission 8.3.0, medialibrary 11.23.6, activitylog 4.12.3, backup 10.3.2,
  honeypot 4.7.2, sluggable 4.0.3, sitemap 8.2.0
- `php artisan test` green (2/2) · `pint --test` green · migrations run

#### Stack decisions — both forced by dependency resolution, not preference
- **PHP 8.3 → 8.4** (approved). On 8.3, Pest is not installable at all:
  `pest-plugin-laravel` v5 needs PHP ^8.4; Pest 4 conflicts with the PHPUnit 12.5 that
  Laravel 13 ships; Pest 5 needs PHPUnit 13, which needs PHP ≥ 8.4.1. Separately,
  `spatie/laravel-sitemap` has no version compatible with both PHP 8.3 and Guzzle 8.
  Platform pinned to `8.4.1`; `require.php` set to `^8.4`; both CI workflows and all
  cron/deploy paths moved to `ea-php84`. `CLAUDE.md` amended.
- **Livewire 3 → 4.** Filament v5 requires Livewire 4, so the two locked entries in
  `CLAUDE.md` could not both hold. Phase 4 onward must use Livewire 4 idioms.
- **Pint config relaxed** to the plain Laravel preset. The `concat_space` and
  `trailing_comma_in_multiline` overrides conflicted with Laravel's own generated code,
  which would have made every `artisan make:` output fail CI until hand-fixed.

#### Hosting — direction changed, 2026-09-02
- **New hosting will be procured, running PHP 8.4. The project will not launch on `presti98`.**
- Closes risks **SH-18, OPS-9, OPS-11 and OPS-12** outright rather than accepting them.
  Blueprint §4.4.1 and decision #29 marked superseded; the §11.6.1 portability rules are
  retained as standing engineering rules.
- Runbook split: **steps 0–6 are host-independent and proceed now**; steps 7–11 (cPanel
  config, server bootstrap, cron, SSL, first deploy) are **deferred** until the host exists.
- The pipeline architecture is unchanged — build on runner, rsync over SSH, atomic release
  symlink, rollback script. Only values change: `SSH_HOST`, `SSH_PORT`, `SSH_USER`,
  `DEPLOY_PATH`, `PHP_BIN`, `APP_URL`, the docroot path, and cron syntax if not cPanel.
- The `ea-php84` patch-version and extension checks are moot for now; they carry over to
  whatever host is chosen.

#### Local database
- Local development stays on SQLite for the remainder of Phase 2, and **moves to MySQL 8.4
  before Phase 3** — runbook step 2.5 added. SQLite tolerates index key-length limits,
  `ENUM`, lax `ALTER TABLE` and unenforced foreign keys in ways MySQL does not, which is
  precisely what Phase 3's schema work needs to surface.
- CI's MySQL service bumped **8.0 → 8.4** (8.0 reached EOL in April 2026).

---

## Phase 1 — Discovery & architecture blueprint — 2026-09-01

### Added
- `PHASE-1-BLUEPRINT.md` v1.3 — brand tokens with every WCAG 2.2 AA ratio computed,
  sitemap, role matrix, eight user journeys, 42 modules, 60-entry risk register,
  environment plan, and the §0 placeholder register

### Confirmed
- cPanel `presti98`, home `/home/presti98`, docroot `/home/presti98/greaterhopefoundations.com`
- PHP 8.3 system default (8.4 available) · SSH port 2222 · 200 GB disk · 2 GB PMEM · 80 entry processes
- Brand: deep green `#0B4D3F` + orange `#FC6302`; logo blue `#0068EC` reassigned to the
  Every Soul Missions division accent

### Accepted risks
- SH-18 / OPS-9 / OPS-11 — the foundation runs as an addon domain on a shared cPanel account
  whose primary domain is an unrelated business. Migration to dedicated hosting deferred;
  portability rules in §11.6.1 keep the eventual move cheap
