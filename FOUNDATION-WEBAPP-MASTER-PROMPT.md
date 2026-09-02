# Foundation Web App — Master Build Prompt Pack

A systematic, phase-by-phase prompt series for building a fully functional, CMS-driven
fundraising and e-commerce web application for a Ghanaian foundation/NGO.

**Stack decided:** Laravel 13 (PHP 8.3+) · MySQL/MariaDB (cPanel) · Livewire 3 + Alpine.js +
Tailwind CSS · Filament v5 admin/CMS · Paystack (GHS) · deployed to InMotion shared hosting
cPanel via GitHub.

---

## How to use this pack

1. **Phase 0 is the master context.** Paste it at the start of every new build session, or
   save it as your project's `CLAUDE.md` / system prompt so it is always in context.
2. **Then run one phase at a time**, in order. Do not skip ahead — later phases depend on
   the database and CMS foundations built earlier.
3. **Each phase ends with the same closing clause** (the "gap-check" clause). Keep it. It is
   what forces a review for anything missing before code is written.
4. After each phase: commit to GitHub, deploy, and verify on the live server before moving on.
5. Where a phase says *"wait for my uploads"*, upload the files before running it.

---

## Files you must upload before Phase 1

| # | Asset | Why it's needed |
|---|-------|-----------------|
| 1 | Foundation profile document (mission, vision, history, board, programmes, contact, socials) | All site copy is generated from this |
| 2 | Logo pack (SVG preferred, plus PNG light/dark variants, favicon source, wordmark) | Branding, theme, favicons, OG images |
| 3 | Brand guide or colour/font preferences (if any) | Design tokens for light + dark theme |
| 4 | Sample web app / HTML template you want used as the visual base | Layout & component reference |
| 5 | cPanel screenshots (home, File Manager, MySQL Databases, PHP Selector/MultiPHP, Git Version Control, Cron Jobs, Email Accounts, SSL/TLS, Terminal or SSH Access page) | So the deployment and DB plan matches your actual environment |
| 6 | Photos of programmes/projects/beneficiaries (with consent) | Real content, not placeholders |
| 7 | Product photos + price list for the shop | Shop seeding |
| 8 | Registration documents / certificate numbers, tax status, bank & Paystack account details | Footer legal block, receipts, payouts |
| 9 | Existing domain name and DNS provider | Deployment, email, SSL |
| 10 | Any existing content (old website export, brochures, annual reports) | Migration |

---

## Decisions to confirm before Phase 1

Answer these; they are referenced throughout the prompts.

- Domain and whether the app lives at root (`example.org`) or a subfolder/subdomain.
- Languages: English only, or English + Twi/Ga/Ewe?
- Do you need **user accounts for donors** (donation history, recurring management), or
  guest-only checkout?
- Shop fulfilment: physical delivery in Ghana, pickup, digital downloads, or all three?
- Do you need **peer-to-peer fundraising** (supporters creating their own fundraising pages)?
- Do you need **volunteer sign-up and event ticketing**?
- SMS provider preference: Arkesel, Hubtel, mNotify, Wigal, or Twilio fallback.
- Email sending: cPanel SMTP, or a transactional provider (Resend / Postmark / Brevo / Mailgun)?
- Does your InMotion plan include **SSH access, Composer, Node, and cron**? (Check the
  cPanel screenshots — this decides the deployment strategy in Phase 2.)

---

# PHASE 0 — Master Context Prompt

> Paste this once per session, before any other phase.

```text
You are my senior full-stack software architect, Laravel/PHP engineer, database architect,
UI/UX designer, DevOps engineer, cybersecurity engineer, QA engineer, SEO specialist,
accessibility specialist, and technical documentation writer.

We are building a production-grade, dynamic, fully functional web application for a
Ghanaian foundation (non-profit). Treat this as a real client project, not a demo.

=== NON-NEGOTIABLE PROJECT CONSTRAINTS ===

1. STACK
   - Laravel 13 (PHP 8.3+ minimum; confirm the PHP version available in my cPanel before
     locking the constraint)
   - MySQL 8 / MariaDB, created through cPanel's "MySQL Databases" tool
   - Blade + Livewire 3 + Alpine.js + Tailwind CSS for the public site
   - Filament v5 for the admin panel and CMS
   - No service that requires root, Docker, Redis, Supervisor, or a Node runtime on the
     server. Everything must run on InMotion shared hosting with cPanel.
   - Vite assets are compiled locally or in CI and the built files are deployed; the server
     never runs `npm run build`.

2. HOSTING & DEPLOYMENT
   - Code lives in a private GitHub repository.
   - Pushing to the `main` branch must result in the change appearing on the live site,
     using either cPanel Git Version Control with a `.cpanel.yml` deployment file, or a
     GitHub Actions workflow that deploys over SSH/FTP. Recommend the best option based on
     the cPanel screenshots I provide.
   - Laravel's `public/` directory is mapped to `public_html`; the application code sits
     OUTSIDE the web root.
   - Scheduler and queue run from cPanel cron jobs, not Supervisor.

3. PAYMENTS
   - Paystack is the payment gateway, for both donations and shop orders.
   - Currency is Ghanaian Cedi (GHS) everywhere: display as `GH₵ 1,234.56`.
   - Amounts are sent to Paystack in pesewas (GHS × 100) and stored in the database as
     integer minor units. Never store money as float.
   - Channels: card, mobile money (MTN, Telecel, AirtelTigo), bank transfer, USSD, QR.
   - Payment truth comes from the webhook (`charge.success`), verified with an HMAC SHA-512
     signature check against the raw request body, plus a server-side call to the verify
     endpoint. The browser redirect is never trusted as proof of payment.

4. CMS REQUIREMENT (critical)
   - I must be able to change EVERYTHING content-related without touching source code:
     header, navigation menus, footer, logos, colours, homepage sections, pages, blog,
     projects, causes, products, banners, contact details, social links, SEO metadata,
     email/SMS templates, legal pages, and site-wide settings.
   - No hardcoded strings, phone numbers, emails, addresses, or images in Blade templates.
     Every one of those comes from the database via a settings/CMS layer with sensible
     seeded defaults.

5. CONTENT SOURCE
   - All copy, imagery, naming, tone, and branding come from the foundation profile and
     logo files I upload. Where information is missing, insert clearly-marked placeholders
     and list them for me — never invent facts about the foundation.

6. DESIGN
   - Full dark and light theme with a toggle, system-preference detection, no flash of
     wrong theme on load, and persistence. Both themes must meet WCAG 2.2 AA contrast.
   - Mobile-first, fast on 3G, works on low-end Android devices (this is the Ghanaian
     market reality).

7. QUALITY BAR
   - WCAG 2.2 AA accessibility, OWASP Top 10 hardening, SEO best practice with structured
     data, automated tests, and written documentation are part of "done" — not extras.

=== HOW YOU MUST WORK ===

- Work in the phases I give you. Complete one phase fully before moving on.
- Before writing code for a phase, restate your understanding in 3–5 bullets and list any
  assumptions you are making.
- Produce complete, runnable files — never fragments with "// rest of the code here".
- Give me the exact terminal commands to run, in order.
- Show migrations, models, controllers/actions, form requests, policies, Livewire
  components, Filament resources, Blade views, routes, tests, and config as separate,
  clearly-labelled files with their full paths.
- Flag anything that will not work on shared hosting and give me the shared-hosting
  alternative.
- Keep a running `CHANGELOG.md` and update `README.md` at the end of each phase.
- Never put secrets in the repository. Everything sensitive goes in `.env`, and
  `.env.example` documents every key with a safe placeholder.

=== STANDARD CLOSING CLAUSE (applies to every phase) ===

At the end of every phase, you must:
  (a) list anything I missed, forgot, or under-specified for this phase;
  (b) list anything you recommend adding to make the feature genuinely production-ready;
  (c) list any decisions you need from me before the next phase;
  (d) state explicitly what is now testable and how I verify it works.

Confirm you understand, then wait for Phase 1.
```

---

# PHASE 1 — Discovery, Asset Intake & Architecture Blueprint

> Upload assets 1–10 from the checklist above **before** running this prompt.

```text
PHASE 1 — DISCOVERY AND ARCHITECTURE BLUEPRINT

I have uploaded: the foundation profile, the logo pack, a sample web app template, my
cPanel screenshots, and supporting content.

Do the following:

1. READ AND EXTRACT
   - From the foundation profile, extract: legal name, short name, tagline, mission, vision,
     core values, history, focus areas/programmes, geography of operation, leadership/board,
     registration details, contact details, social media handles, bank/payment details,
     and any existing partners or donors.
   - From the logo pack, extract: primary and secondary colours (give me hex values), the
     logo variants available, safe-area and minimum-size rules, and which variant belongs
     on a light background versus a dark background.
   - From the sample template, extract: the layout patterns, section types, component
     inventory, and interaction patterns worth reusing. State clearly which parts we will
     reuse, which we will improve, and why. We are inspired by it, not bound to it.
   - From the cPanel screenshots, extract: cPanel version, PHP versions available, whether
     PHP Selector / MultiPHP Manager is present, MySQL/MariaDB version, whether SSH/Terminal
     is available, whether Git Version Control is available, whether Composer is available,
     Node availability, cron job availability, email account and SPF/DKIM tooling, SSL
     tooling (AutoSSL/Let's Encrypt), disk and inode limits, and the account username and
     home directory path convention.

2. PRODUCE THE BLUEPRINT
   - A brand token sheet: colour palette for light AND dark theme (background, surface,
     border, text primary/secondary, brand primary/secondary, success, warning, danger,
     info), type scale, spacing scale, radius scale, shadow scale. Every colour pair must
     be checked for WCAG 2.2 AA contrast and you must show the ratios.
   - The full sitemap / information architecture: every public page, every account page,
     every admin screen.
   - The user roles and what each can do: Super Admin, Admin, Content Editor, Finance
     Officer, Shop Manager, Volunteer Coordinator, Donor (public account), Guest.
   - The user journeys, written as step-by-step flows: one-off donation, recurring
     donation, mobile money donation, shop purchase, volunteer sign-up, newsletter
     sign-up, contact enquiry, and admin content update.
   - The module list with a one-line purpose for each.
   - A risk register: what could break on shared hosting, what could break with payments,
     what could break with deliverability, and the mitigation for each.

3. CONFIRM THE ENVIRONMENT PLAN
   - Based on my actual cPanel, tell me exactly which deployment strategy we will use and
     why, and what I need to enable or request from InMotion support before Phase 2.

Output the blueprint as a single structured document I can keep as the project brief.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 2 — Environment, Repository & GitHub → cPanel Deployment Pipeline

```text
PHASE 2 — LOCAL ENVIRONMENT, GITHUB REPO, AND AUTOMATED DEPLOYMENT TO CPANEL

Set up the project skeleton and the deployment pipeline before we build any features.
Nothing is "done" in this project until it is running on the live server.

1. LOCAL PROJECT
   - Create a fresh Laravel 13 application.
   - Configure Tailwind CSS, Vite, Livewire 3, and Alpine.
   - Install and configure: filament/filament v5, spatie/laravel-permission,
     spatie/laravel-medialibrary, spatie/laravel-sluggable, spatie/laravel-activitylog,
     spatie/laravel-backup, spatie/laravel-sitemap, spatie/laravel-honeypot,
     laravel/pint, pestphp/pest. Justify each one in a sentence and tell me if a lighter
     alternative is better for shared hosting.
   - Set up `.editorconfig`, Pint config, a `.gitignore` correct for Laravel + built assets,
     and a `.gitattributes`.

2. ENVIRONMENT CONFIGURATION
   - Produce a complete `.env.example` covering: app, database, mail, SMS provider,
     Paystack (public key, secret key, webhook secret, callback URL, currency=GHS),
     queue, cache, session, filesystem, backup destination, and feature flags.
   - Document which values I get from where (cPanel, Paystack dashboard, SMS provider).
   - Explain how to keep `.env` out of the repo but present on the server, and how to
     update it safely.

3. REPOSITORY
   - Give me the exact commands to initialise the repo, create the first commit, and push
     to a private GitHub repository.
   - Set up a branch strategy: `main` = production, `develop` = staging, feature branches.
   - Add a pull-request template and a commit message convention.

4. DEPLOYMENT — CHOOSE AND IMPLEMENT
   Based on my cPanel capabilities, implement ONE of these as primary and document the
   other as fallback:

   OPTION A — cPanel Git Version Control + `.cpanel.yml`
     - Full `.cpanel.yml` that copies the application to the home directory, copies
       `public/` contents into `public_html`, and preserves `.env` and `storage`.
     - Handle the "public folder is not the web root" problem correctly: adjust
       `index.php` paths, or use a symlink, and explain the trade-offs of each.
     - Post-deploy steps I must run (composer install, migrate --force, config:cache,
       route:cache, view:cache, storage:link) and how to run them without SSH if I have
       no SSH (e.g. a secured artisan-runner route protected by a token, removed later).

   OPTION B — GitHub Actions
     - A workflow that on push to `main`: installs PHP deps with `--no-dev
       --optimize-autoloader`, builds frontend assets with Node, runs the test suite, then
       deploys the built artefact to cPanel over SSH (rsync) or FTPS.
     - Zero-downtime approach: deploy to a release folder and switch a symlink, if the host
       allows symlinks; otherwise atomic-as-possible file sync with maintenance mode.
     - Store all credentials as GitHub Secrets. List every secret I must create.

5. SERVER TASKS
   - The exact cron entries for the Laravel scheduler and for queue processing on shared
     hosting (no Supervisor). Use the database queue driver and a
     `queue:work --stop-when-empty --max-time=...` pattern; explain the reasoning.
   - File and folder permissions for shared hosting (storage, bootstrap/cache), and what
     to do if the host runs PHP as a different user.
   - Force HTTPS, set up AutoSSL, and a hardened `.htaccess` (block dotfiles, block access
     to vendor/storage, security headers, HSTS, compression, caching rules).
   - A working `robots.txt` strategy for staging versus production.

6. STAGING
   - Set up a staging subdomain with its own database and its own `.env`, deploying from
     `develop`. Explain how to keep staging noindexed and how to use Paystack test keys
     there.

Give me a numbered runbook: everything I click in cPanel, everything I run in the
terminal, in order, with expected output at each step and what to do when it fails.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 3 — Database Architecture

```text
PHASE 3 — COMPLETE DATABASE ARCHITECTURE

Design the full relational schema for the entire application. Do not code features yet —
design the data layer first so nothing has to be re-migrated later.

Deliver:

1. An entity-relationship overview, grouped by module, with cardinalities.
2. Every migration file, complete, with correct types, nullability, defaults, indexes,
   composite indexes, unique constraints, and foreign keys with the right onDelete
   behaviour.
3. Every Eloquent model with relationships, casts, fillable/guarded, scopes, accessors,
   and any traits (soft deletes, slugs, media, activity log).
4. Seeders and factories for every table, seeded with real content from the foundation
   profile I uploaded, not lorem ipsum.

The schema must cover at minimum:

CORE / AUTH
  users, password_reset_tokens, sessions, roles, permissions, role_has_permissions,
  model_has_roles, model_has_permissions, two_factor fields, admin_activity_log,
  login_histories, notifications, jobs, failed_jobs, cache, media

CMS
  settings (grouped key/value with type casting), pages, page_sections (polymorphic
  flexible content blocks), block_types, menus, menu_items (nested/tree), banners/sliders,
  media_folders, faqs, faq_categories, testimonials, partners, team_members,
  team_departments, galleries, gallery_items, documents/downloads (annual reports,
  policies), announcements, redirects, seo_meta (polymorphic), page_revisions,
  translations/locales (if multilingual), theme_settings (colours, fonts, logos,
  dark/light overrides), contact_messages, subscribers, popups

PROGRAMMES
  focus_areas, projects, project_updates, project_galleries, project_documents,
  project_beneficiaries, project_locations (region/district for Ghana),
  project_milestones, project_partners, impact_metrics, impact_metric_values,
  causes/campaigns, cause_updates, cause_categories

FUNDRAISING
  donations, donation_items (for split/designated giving), donors, donation_plans
  (recurring: weekly/monthly/quarterly/annual), subscriptions, subscription_charges,
  pledges, offline_donations, donation_receipts, fundraisers (peer-to-peer pages),
  fundraiser_donations, gift_aid-style tribute fields (in honour of / in memory of),
  donation_goals, currencies (GHS primary), payment_transactions, payment_webhook_events,
  refunds, payouts

SHOP
  product_categories, products, product_variants, product_options, product_images,
  inventory_movements, carts, cart_items, orders, order_items, order_statuses,
  order_status_histories, shipping_zones (Ghana regions), shipping_rates,
  delivery_methods (delivery / pickup / digital), coupons, coupon_redemptions,
  taxes, invoices, digital_download_tokens, product_reviews, wishlists

ENGAGEMENT
  events, event_registrations, event_tickets, ticket_orders, volunteers,
  volunteer_applications, volunteer_opportunities, volunteer_hours, blog_categories,
  posts, post_tags, tags, comments (moderated), newsletters, newsletter_campaigns,
  campaign_recipients

COMMUNICATIONS
  email_templates, sms_templates, notification_logs, sms_logs, email_logs,
  scheduled_messages, contact_departments

SYSTEM
  audit_logs, api_tokens, backups_log, feature_flags, error_reports, visitor_stats
  (lightweight, privacy-respecting)

RULES YOU MUST FOLLOW
  - All money columns are unsigned BIGINT storing minor units (pesewas), with a currency
    column defaulting to 'GHS'. Provide a Money cast/value object for formatting as
    `GH₵ 1,234.56`.
  - Use UUIDs or ULIDs for anything exposed in a public URL or a payment reference.
  - Every donation and order must carry an immutable, unique, human-readable reference.
  - Soft deletes on all content tables; hard deletes only where legally required.
  - Timestamps everywhere; `created_by` / `updated_by` on admin-editable tables.
  - Index every foreign key and every column used in filtering, sorting, or lookups.
  - Design for MySQL on shared hosting: watch row size, avoid excessive JSON columns where
    a proper table is better, keep index counts sane.

Also produce:
  - A cPanel-specific setup guide: creating the database and DB user in "MySQL Databases",
    assigning ALL PRIVILEGES, the `cpaneluser_dbname` naming convention, the correct
    DB_HOST value (localhost) and port, and how to import/export via phpMyAdmin.
  - A migration-safety policy: never `migrate:fresh` in production, always
    `migrate --force`, how to write reversible migrations, and how to do zero-loss schema
    changes on a live donation database.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 4 — Application Foundation: Auth, Roles, Settings & Layout Shell

```text
PHASE 4 — APPLICATION FOUNDATION

Build the skeleton that every other feature plugs into.

1. AUTHENTICATION & AUTHORISATION
   - Registration, login, logout, email verification, password reset, remember me.
   - Separate guards/routing for admin (Filament panel at /admin or a custom path I set)
     and for public donor accounts.
   - Two-factor authentication (TOTP) required for all admin roles.
   - Roles and permissions seeded: Super Admin, Admin, Content Editor, Finance Officer,
     Shop Manager, Volunteer Coordinator, Donor. Define every permission string and map it.
   - Policies for every model. No authorisation logic scattered in controllers.
   - Rate limiting on login, registration, password reset, contact, donation initiation.
   - Login notification emails and a login history table.
   - Admin impersonation for support, fully logged.

2. SETTINGS LAYER (the backbone of the CMS)
   - A grouped settings system (general, contact, social, theme, donations, shop, email,
     SMS, SEO, legal, integrations, maintenance) with typed values (string, text, boolean,
     integer, json, image, colour, richtext).
   - A `setting('key', 'default')` helper, cached, with cache invalidation on save.
   - Seed every setting with real values from the foundation profile.
   - A Filament settings UI grouped into tabs, so I can edit all of it without code.

3. MEDIA LIBRARY
   - Central media management with folders, upload, alt text, captions, credit,
     replace-file, and usage tracking ("where is this image used").
   - Automatic responsive conversions (thumb, card, hero) and WebP/AVIF output.
   - Image optimisation that works without ImageMagick if the host lacks it — detect and
     degrade gracefully.
   - Upload validation: MIME sniffing, extension allowlist, size limits, filename
     sanitising, stripping EXIF/GPS from photos (important for beneficiary safeguarding).

4. LAYOUT SHELL
   - Base Blade layout with slots, driven entirely by settings and the menu system.
   - Header: logo (light/dark variants), primary nav (multi-level, from the menu builder),
     search, theme toggle, language switcher if multilingual, prominent "Donate" CTA.
   - Footer: configurable column blocks, contact info, social links, newsletter form,
     legal links, registration numbers, copyright with dynamic year.
   - Dark/light theme: CSS custom properties for all tokens, `class`-based Tailwind dark
     mode, an inline head script that applies the stored/system theme before first paint
     to prevent flash, a toggle with three states (light / dark / system), and persistence
     in localStorage plus a cookie so SSR-rendered pages match.
   - Skip-to-content link, focus-visible styles, landmark regions, and a live region for
     announcements.
   - Global components: buttons, cards, forms, alerts, modals, breadcrumbs, pagination,
     empty states, loading skeletons, toasts.
   - Custom 404, 403, 419, 429, 500, and maintenance pages, all branded.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 5 — The CMS (edit everything without touching code)

```text
PHASE 5 — FULL CONTENT MANAGEMENT SYSTEM

Build the CMS so I can run the entire website from the admin panel. This is the most
important phase for me. Assume I am non-technical when I use it.

1. PAGE BUILDER
   - Pages with title, slug, status (draft/scheduled/published), publish date, parent page,
     template, visibility, and SEO fields.
   - Flexible content sections: I add, reorder (drag and drop), duplicate, and remove blocks
     on any page. Build at least these block types:
     hero (image/video/slider), rich text, text + image, statistics/counters, call to
     action, donation widget, featured causes, featured projects, featured products,
     latest news, events list, gallery, video embed, testimonials carousel, partners/logos
     strip, team grid, FAQ accordion, timeline, map, contact form, newsletter signup,
     downloads list, impact report, quote, spacer/divider, custom HTML (admin-only,
     sanitised), accordion, tabs, pricing/giving levels, countdown.
   - Each block has its own settings: background colour/image, padding, alignment,
     container width, light/dark variant, animation on/off, and visibility (desktop/mobile).
   - Live preview and revision history with restore.

2. MENU BUILDER
   - Multiple menus (header, footer col 1..n, mobile, utility, legal).
   - Nested drag-and-drop items linking to pages, projects, causes, products, categories,
     posts, external URLs, anchors, or a "Donate" action.
   - Per-item: label, icon, target, CSS class, visibility by role, highlight/CTA styling.

3. GLOBAL CONTENT
   - Header builder: logo variants, sticky behaviour, top bar (phone/email/socials),
     announcement bar with date range, CTA button label and link, mega-menu toggle.
   - Footer builder: columns, widgets, newsletter block, payment/partner badges,
     registration details, back-to-top.
   - Theme editor: primary/secondary/accent colours for BOTH light and dark themes, font
     family selection, border radius, button style, section spacing, with a contrast
     checker that warns me when a combination fails WCAG AA, and a "reset to brand
     defaults" button.

4. CONTENT MODULES (full CRUD, all in the admin)
   - Blog/news with categories, tags, featured image, author, scheduled publishing,
     related posts, and moderated comments.
   - Gallery/albums, videos, documents & downloads (annual reports, policies, financials).
   - FAQs by category, testimonials, partners/sponsors, team/board members, announcements,
     popups/modals with targeting rules and frequency capping.
   - Contact form submissions inbox with statuses, assignment, notes, and reply-by-email.
   - Redirect manager (old URL → new URL) and a 404 log that suggests redirects.

5. ADMIN EXPERIENCE
   - Dashboard with the numbers that matter: total raised this month, donations today,
     recurring donors, top causes, shop revenue, pending orders, new messages, low stock,
     failed payments, and a simple chart.
   - Global search across all content types.
   - Bulk actions, filters, saved views, CSV/Excel export on every table.
   - Inline help text on every field written for a non-technical user.
   - An in-admin "Site Health" page: queue running?, scheduler running?, SSL valid?,
     storage writable?, last backup?, Paystack keys in live or test mode?, SMS credits low?

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 6 — Public Website Frontend

```text
PHASE 6 — PUBLIC-FACING WEBSITE

Build the public site using the uploaded template as visual inspiration, the extracted
brand tokens, and content pulled entirely from the CMS.

PAGES TO BUILD
  - Home (composed of CMS blocks, editable)
  - About: who we are, mission & vision, history/timeline, board & team, our approach,
    partners, annual reports & financials, careers
  - What we do: focus areas index, focus area detail
  - Projects: index with filters (focus area, region, status, year), project detail with
    gallery, updates, milestones, budget/progress, beneficiaries, documents, and a
    "support this project" CTA
  - Causes/Campaigns: index, detail with live progress bar, goal, amount raised, donor
    count, days remaining, giving levels, recent donors wall, updates, and share buttons
  - Donate: standalone, high-conversion donation page
  - Shop: index, category, product detail, cart, checkout, order confirmation
  - Get involved: volunteer, partner with us, fundraise for us, corporate giving, in-kind
    donations
  - Events: index, detail, registration
  - News/Blog: index, category, tag, single post, author
  - Gallery, Impact/Results, Testimonials, FAQ
  - Contact with map, departments, office hours, WhatsApp link, and form
  - Legal: privacy policy, terms, refund & cancellation policy, cookie policy, donation
    policy, child safeguarding policy, anti-fraud statement
  - Search results, sitemap page, 404

REQUIREMENTS
  - Every page is composed of CMS blocks or CMS-driven data. Zero hardcoded content.
  - Mobile-first responsive design; test at 320px, 375px, 768px, 1024px, 1440px.
  - Both themes styled and checked — every component must be reviewed in dark mode, not
    just inverted.
  - Performance budget: LCP under 2.5s on a simulated 3G connection, CLS under 0.1,
    JS under 100KB gzipped on the homepage. Lazy-load images with width/height set,
    preload the hero image and fonts, self-host fonts, defer non-critical CSS/JS.
  - Progressive enhancement: forms and navigation work without JavaScript where feasible.
  - Micro-interactions and scroll animations that respect `prefers-reduced-motion`.
  - Social sharing with per-page Open Graph and Twitter card images, plus dynamically
    generated OG images for causes showing the progress.
  - Breadcrumbs on every inner page, with structured data.
  - A sticky/floating donate button on mobile.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 7 — Projects, Causes & Impact Module

```text
PHASE 7 — PROJECTS, CAUSES AND IMPACT

Build the programmatic heart of the foundation site.

1. FOCUS AREAS
   - Manage the foundation's thematic areas (e.g. education, health, water, livelihoods)
     with icon, colour, description, and linked projects/causes.

2. PROJECTS (what the foundation does)
   - Fields: title, slug, focus area, summary, full description (block editor), status
     (planned / ongoing / completed / paused), start and end dates, location (Ghana region
     and district, with map coordinates), budget, funds raised, funds spent, beneficiaries
     reached, partners, team lead, gallery, videos, documents, tags.
   - Milestones with completion state and dates.
   - Project updates (a mini-blog per project) that can be emailed to that project's donors.
   - Impact metrics: define metrics (people served, boreholes drilled, scholarships given)
     and record values over time; display as counters and simple charts.
   - Public project page shows a transparent breakdown of funding and progress.

3. CAUSES / CAMPAIGNS (what people give to)
   - Fields: title, slug, linked project (optional), category, story, hero media, goal
     amount in GHS, amount raised (computed from completed donations, cached), start and
     end dates, urgency flag, minimum donation, suggested giving levels with a description
     of what each amount buys ("GH₵ 50 provides a school kit for one child"), status,
     featured flag, and a designated fund code for accounting.
   - Live progress bar, donor count, recent donors list with anonymity respected,
     days-remaining countdown, and a share block with referral tracking.
   - Cause updates that notify existing donors by email and optionally SMS.
   - Goal-reached behaviour: keep accepting, close, or redirect to another cause — my choice
     per cause.
   - "General Fund" as a permanent default cause so donations always have a destination.

4. PEER-TO-PEER FUNDRAISING (build if I confirmed I want it; otherwise scaffold the
   tables and hide the UI behind a feature flag)
   - Supporters create a personal fundraising page for a cause, with their own goal,
     story, and photo; donations attribute to both the fundraiser and the cause;
     leaderboards; and moderation before a page goes live.

5. TRANSPARENCY FEATURES
   - Public impact dashboard: total raised, total disbursed, beneficiaries reached,
     projects by region, updated automatically.
   - Downloadable annual reports and audited financials.
   - Optional per-cause expenditure log so donors can see how money was used.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 8 — Donations & Paystack Integration (GHS)

```text
PHASE 8 — DONATIONS AND PAYSTACK INTEGRATION

This is the highest-risk phase. Correctness matters more than speed. Money must never be
lost, double-counted, or credited without proof.

1. DONATION FLOW (public)
   - A donation widget usable on the donate page, on any cause, on any project, and as a
     CMS block, with: amount presets (configurable per cause), custom amount, frequency
     (one-off / weekly / monthly / quarterly / yearly), cause selection, and a
     "where most needed" option.
   - Donor details: name, email, phone (Ghana format validation, +233 normalisation),
     optional address, and a "give anonymously" toggle.
   - Options: cover the Paystack transaction fee (calculated and shown clearly),
     dedicate this gift (in honour of / in memory of, with optional notification email to a
     third party), leave a public message, opt in to updates (explicit consent, unticked
     by default), and Gift/pledge notes.
   - A clear summary before payment: `GH₵ X to <cause>, <frequency>`.
   - Guest donations allowed; optional account creation after donating.
   - Honeypot + rate limiting + optional CAPTCHA on the donation form.

2. PAYSTACK INTEGRATION — SERVER SIDE
   - A dedicated `PaystackService` (interface + implementation) so the gateway can be
     swapped later. Nothing calls Paystack directly from a controller.
   - Initialise transaction: amount in pesewas (integer, never float arithmetic),
     currency GHS, unique reference generated by us, customer email, callback URL,
     channels configurable, and metadata carrying donation id, cause id, donor id,
     campaign source, and UTM parameters.
   - Support both the redirect/checkout flow and inline popup; make it configurable.
   - Mobile money: support the direct charge flow with provider (mtn / vod / atl) and the
     phone number, handle the `pay_offline`/OTP/PIN prompt states, poll or wait for the
     webhook, and show the customer clear instructions ("check your phone and approve").
   - Verify every transaction server-side against the verify endpoint before marking it
     successful, even when the webhook already arrived.

3. WEBHOOKS — THE SOURCE OF TRUTH
   - A webhook endpoint excluded from CSRF, that:
     a) reads the RAW request body,
     b) computes HMAC SHA-512 with the Paystack secret key,
     c) compares it to the `x-paystack-signature` header using a timing-safe comparison,
     d) rejects anything that fails,
     e) optionally checks the source IP against Paystack's published IPs,
     f) stores the raw event in `payment_webhook_events` BEFORE processing,
     g) responds 200 immediately and processes the event on the queue,
     h) is fully idempotent — replaying the same event must never create a second donation
        or double-count a total.
   - Handle these events at minimum: charge.success, charge.failed, transfer.success,
     transfer.failed, refund.processed, subscription.create, subscription.disable,
     invoice.create, invoice.update, invoice.payment_failed.
   - Amount and currency from the webhook must be re-checked against our stored expected
     amount; a mismatch raises an alert and does not auto-complete.

4. RECURRING GIVING
   - Implement recurring donations with Paystack plans and subscriptions, plus our own
     `donation_plans` records so donors can view, pause, change amount, or cancel from
     their account or from a signed link in an email.
   - Handle failed renewals: retry policy, dunning emails and SMS, and automatic
     cancellation after N failures.
   - Card authorisations stored as tokens only (authorization_code) — never card data.

5. POST-DONATION
   - Thank-you page with the reference, receipt download, share prompt, and a soft ask to
     subscribe or become a monthly donor.
   - Automatic receipt as a branded PDF with the foundation's logo, registration number,
     donation reference, date, amount in GHS, cause, and tax-status wording.
   - Thank-you email and SMS (both templated in the CMS).
   - Failed-payment recovery email/SMS with a resume link.
   - Abandoned donation tracking (started but not completed) with an optional follow-up.

6. ADMIN — FINANCE
   - Donations table: filter by date, cause, channel, status, amount, recurring, donor;
     search by reference, name, phone, email; export to CSV/Excel.
   - Donation detail with the full Paystack payload, webhook timeline, and audit trail.
   - Manual actions: record an offline/cash/bank-transfer donation, mark reconciled,
     resend receipt, refund (via Paystack API with confirmation), and add internal notes.
   - Donor CRM: donor profile, lifetime value, first and last gift, frequency, tags,
     segments, and communication history.
   - Reports: daily/weekly/monthly totals, by cause, by channel, by region, recurring
     retention, average gift size, donor acquisition versus retention, and a reconciliation
     report against Paystack settlements/payouts.
   - A daily settlement reconciliation job that flags any mismatch.

7. SAFETY RULES
   - Never trust an amount submitted from the browser — recompute server-side.
   - Never mark a donation complete from the redirect callback alone.
   - Log every state transition of every donation.
   - Test mode versus live mode must be visually obvious in the admin.
   - Provide the full test plan: Paystack test cards, test mobile money numbers, failed
     payment simulation, webhook replay, duplicate webhook, and out-of-order webhook.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 9 — Shop / Fundraising Store

```text
PHASE 9 — E-COMMERCE STORE FOR FUNDRAISING

Build a shop where items are sold to raise funds for the foundation.

1. CATALOGUE
   - Categories (nested), products, variants (size, colour), SKUs, stock levels with
     low-stock alerts, backorder policy, product images with gallery, short and long
     description, specifications, weight and dimensions for shipping, featured flag,
     related products, and an optional link to the cause the proceeds support ("100% of
     proceeds fund the Borehole Project").
   - Product types: physical, digital download, ticket, and "donation product"
     (e.g. sponsor a meal) — each with the right checkout behaviour.
   - Prices stored in pesewas, displayed as GH₵, with optional compare-at price and
     member/bulk pricing.

2. CART & CHECKOUT
   - Persistent cart for guests (cookie/session) and logged-in users (database), merged on
     login.
   - Cart drawer with quantity edit, remove, subtotal, and stock re-validation.
   - Checkout: contact details, delivery method (delivery / pickup / digital), Ghana
     address fields (region, district/city, area, landmark, digital address GhanaPostGPS,
     phone), delivery notes, coupon code, optional round-up or add-a-donation step, order
     summary, and Paystack payment.
   - Shipping: zones by Ghana region, flat/weight/price-based rates, free-shipping
     threshold, pickup locations, and estimated delivery time.
   - Stock is reserved at order creation and only decremented on payment confirmation;
     released automatically if payment fails or times out.
   - Order references, guest order tracking by reference + phone/email.

3. ORDER MANAGEMENT
   - Statuses: pending payment, paid, processing, packed, shipped, out for delivery,
     delivered, completed, cancelled, refunded — configurable, with a status history.
   - Customer notifications on every status change by email and SMS.
   - Packing slip and invoice PDFs, bulk print.
   - Refunds (full and partial) through Paystack with stock restoration.
   - Digital downloads: signed, expiring, download-limited links.
   - Abandoned cart detection with an optional reminder email.

4. REPORTING
   - Sales by day/product/category, best sellers, stock valuation, revenue attributed to
     each cause, and combined "total funds raised" that adds donations plus net shop
     proceeds.

5. SHARED PAYMENT LAYER
   - Reuse the Paystack service from Phase 8. One transaction table, one webhook handler,
     polymorphic payable (donation or order). Do not duplicate the integration.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 10 — Email & SMS Communications

```text
PHASE 10 — EMAIL AND SMS SYSTEM

Build a unified, CMS-editable communications layer.

1. EMAIL
   - Configure transactional email. Compare cPanel SMTP versus a provider (Resend,
     Postmark, Brevo, Mailgun) for deliverability from a Ghana-hosted shared server, and
     recommend one. Explain why shared-hosting SMTP often lands in spam.
   - A branded, responsive, dark-mode-safe email layout using the foundation's logo and
     colours, tested in Gmail, Outlook, and Apple Mail.
   - Editable email templates in the CMS with a variable picker and a live preview:
     donation thank-you and receipt, recurring donation confirmation, renewal success,
     renewal failure, subscription cancelled, order confirmation, order status changes,
     shipping notice, digital download delivery, contact form auto-reply, contact form
     admin alert, volunteer application received, event registration, newsletter welcome,
     password reset, email verification, admin new-donation alert, low-stock alert, weekly
     summary to the director.
   - Newsletter: subscriber list with double opt-in, segments, campaign composer using CMS
     blocks, test send, scheduled send, throttled sending to respect shared-hosting limits,
     open/click tracking, and one-click unsubscribe with a preference centre.
   - Deliverability setup guide: SPF, DKIM, DMARC records in cPanel/DNS, a dedicated
     sending subdomain, warm-up advice, bounce and complaint handling, and suppression list.

2. SMS (Ghana)
   - A driver-based SMS layer (`SmsChannel` contract) with implementations for Arkesel,
     Hubtel, mNotify, and Twilio as fallback, selectable in the CMS settings, with credit
     balance display and a low-credit alert.
   - Phone number normalisation and validation for Ghana (0XXXXXXXXX ↔ +233XXXXXXXXX),
     network detection where useful, and de-duplication.
   - Editable SMS templates with variables and a character/segment counter that warns about
     GSM-7 versus UCS-2 and multi-part cost.
   - Use cases: donation thank-you, mobile money payment prompt instructions, recurring
     renewal reminder, failed payment alert, order confirmation and dispatch, event
     reminder, volunteer shift reminder, OTP for account actions, and bulk broadcasts to a
     segment.
   - Bulk SMS composer with recipient selection, cost estimate before sending, scheduling,
     throttling, delivery report ingestion, and a full send log.
   - Opt-out handling and a do-not-contact list. Never send marketing SMS without consent;
     always include the sender identity.
   - Sender ID: document the registration process with the Ghanaian provider/NCA, because
     unregistered sender IDs will be blocked.

3. QUEUEING & RELIABILITY
   - All email and SMS go through the queue with retries, backoff, and a failed-jobs UI in
     the admin where I can retry or discard.
   - Because there is no Supervisor, make the cron-driven worker robust: overlap
     prevention, memory limits, and a heartbeat the Site Health page can read.
   - Rate limiting per provider so we do not exceed shared-hosting or provider caps.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 11 — Engagement Modules: Volunteers, Events, Newsletter, Contact

```text
PHASE 11 — ENGAGEMENT MODULES

1. VOLUNTEERS
   - Public volunteer opportunities with role description, location, time commitment,
     skills needed, and application deadline.
   - Application form with skills, availability, references, and consent; admin review
     workflow (new → shortlisted → interviewed → approved → active → inactive) with
     status emails/SMS.
   - Volunteer profiles, hours logging, and a simple recognition/impact summary.
   - Safeguarding: document what checks are required before volunteers work with children.

2. EVENTS
   - Events with date/time, venue and map, description, gallery, capacity, and status.
   - Free registration and/or paid tickets through Paystack, with ticket types, quantity
     limits, promo codes, QR-coded tickets, check-in screen for the door, and reminder
     emails/SMS.
   - Past events archive with photos and outcomes.

3. NEWSLETTER & LEAD CAPTURE
   - Signup blocks in the footer, in content, and as an exit-intent popup with frequency
     capping and consent language.

4. CONTACT & ENQUIRIES
   - Multi-department contact form with spam protection, auto-reply, admin notification,
     an inbox with statuses and assignment, and SLA reminders.
   - Office locations with map, hours, phone, WhatsApp deep link, and directions.

5. PARTNERSHIPS
   - Corporate partnership enquiry form, in-kind donation offer form, and a partner logo
     wall managed from the CMS.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 12 — Security Hardening & Compliance

```text
PHASE 12 — SECURITY, PRIVACY AND COMPLIANCE

Act as the cybersecurity engineer. Audit everything built so far and harden it.

1. APPLICATION SECURITY
   - OWASP Top 10 review of this codebase with specific findings and fixes.
   - Enforce: CSRF on all state-changing forms, mass-assignment protection, parameterised
     queries only, output escaping, signed URLs for sensitive links, strict validation on
     every request via Form Requests, and authorisation via policies on every route.
   - File upload security: MIME verification, extension allowlist, size caps, storing
     uploads outside the web root and serving through a controller where appropriate,
     randomised filenames, EXIF stripping, and virus scanning if ClamAV is available.
   - Security headers: CSP (with a nonce strategy that works with Livewire and Alpine),
     HSTS, X-Content-Type-Options, X-Frame-Options/frame-ancestors, Referrer-Policy,
     Permissions-Policy. Provide both the middleware and the `.htaccess` version.
   - Rate limiting and throttling on auth, forms, donations, webhooks, and the API.
   - Bot protection: honeypots, timing checks, and optional Turnstile/reCAPTCHA.
   - Session security: secure and httpOnly cookies, SameSite, regeneration on login,
     idle and absolute timeouts for admin sessions, single-session option, and forced
     logout of all devices.
   - Admin panel: 2FA enforced, IP allowlist option, a non-obvious admin path, failed-login
     lockout, and full activity logging.
   - Secrets management: nothing in the repo, key rotation procedure, and what to do if a
     Paystack secret key leaks.

2. PAYMENT SECURITY
   - Confirm we never touch, log, or store card data; document our PCI DSS SAQ-A posture.
   - Ensure webhook signature verification cannot be bypassed and cannot be replayed.
   - Alerting on anomalies: many failed payments, unusually large donation, repeated
     refunds, or amount mismatches.

3. DATA PROTECTION
   - Ghana's Data Protection Act, 2012 (Act 843): what it requires of us, registration with
     the Data Protection Commission, and lawful basis for processing donor data. Also cover
     GDPR basics since we will receive donations from abroad.
   - Privacy policy, cookie policy, and a cookie consent banner that actually blocks
     non-essential scripts until consent, with a preferences UI.
   - Data subject rights: export my data, delete my data (with a legal-retention carve-out
     for financial records), and consent records with timestamps.
   - Data retention schedule per table.
   - Personal data minimisation and encryption at rest for sensitive fields.
   - Beneficiary safeguarding: consent records for photographs of children, the ability to
     blur/withdraw an image everywhere it appears, and a policy page.

4. INFRASTRUCTURE
   - Backups: automated database and file backups via spatie/laravel-backup to off-server
     storage (Google Drive / Dropbox / S3 / Backblaze), a schedule, retention, monitoring,
     and — most importantly — a documented and TESTED restore procedure.
   - Cloudflare in front of the site: DNS, SSL mode, WAF rules, caching rules that never
     cache authenticated or payment pages, and rate limiting.
   - Uptime and error monitoring that works on shared hosting (e.g. Sentry, Better Stack,
     or a simple health-check endpoint with UptimeRobot).
   - An incident response runbook: site down, payment gateway down, data breach, defacement,
     mail blacklisting.
   - Dependency scanning and a monthly patch routine.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 13 — SEO, Analytics & Accessibility

```text
PHASE 13 — SEO, ANALYTICS AND ACCESSIBILITY

1. SEO
   - Per-entity SEO fields editable in the CMS: meta title, meta description, canonical,
     robots directives, OG title/description/image, Twitter card — for pages, posts,
     projects, causes, products, categories, and events, with sensible auto-generated
     fallbacks and character-count guidance.
   - Structured data (JSON-LD): Organization/NGO with logo, contact points and socials;
     WebSite with SearchAction; BreadcrumbList; Article for posts; Product with Offer in
     GHS and availability; Event; FAQPage; and a DonateAction on the donate page.
   - XML sitemaps (index + per-type, auto-regenerated on publish), `robots.txt` managed
     from the CMS, and automatic ping on update.
   - Clean URL structure, no duplicate content, correct pagination handling, and 301
     redirects for any legacy URLs.
   - Local SEO for Ghana: NAP consistency, Google Business Profile guidance, and
     hreflang/locale handling if multilingual.
   - Core Web Vitals: an optimisation checklist plus the actual implementation (image
     sizing, font loading, critical CSS, deferred JS, caching headers, Brotli/gzip).
   - Give me a keyword and content plan for a Ghanaian foundation: donation intent terms,
     programme terms, and blog topics that attract donors and partners.

2. ANALYTICS
   - Privacy-respecting analytics (Plausible/Umami self-hosted or GA4 with consent mode),
     loaded only after consent.
   - Conversion tracking: donation started, donation completed with value in GHS, recurring
     started, add to cart, checkout started, purchase, newsletter signup, volunteer
     application, contact submitted.
   - UTM capture stored against donations and orders so we can attribute income to
     campaigns.
   - An internal, database-driven mini-dashboard so the director can see performance
     without logging into a third-party tool.

3. ACCESSIBILITY — WCAG 2.2 AA
   - Semantic HTML, landmarks, one h1 per page, logical heading order.
   - Full keyboard operability including menus, modals, carousels, the theme toggle, and
     the donation form; visible focus indicators; correct focus trapping and restoration.
   - ARIA only where needed and used correctly; live regions for async updates such as
     payment status.
   - Colour contrast verified in BOTH themes; never colour alone to convey meaning.
   - Form accessibility: labels, descriptions, inline error messages linked to fields,
     error summaries, and clear required-field indication.
   - Images: meaningful alt text managed in the media library, decorative images marked as
     such, and captions.
   - Respect `prefers-reduced-motion`; no auto-playing audio or unstoppable carousels.
   - Text resizing to 200% and reflow at 320px without loss of content.
   - Audit with axe and Lighthouse, plus a manual keyboard and screen-reader pass; give me
     the report and fix everything found.
   - Publish an accessibility statement page.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 14 — Testing & QA

```text
PHASE 14 — AUTOMATED TESTING AND QA

Act as the QA engineer. Nothing involving money ships untested.

1. AUTOMATED TESTS (Pest)
   - Unit tests for the Money value object, fee calculation, phone normalisation, slug
     generation, settings resolution, and the SMS driver contract.
   - Feature tests for: registration and login, 2FA, role-based access to every admin
     route, CMS page publishing, menu rendering, donation initiation, Paystack webhook
     signature verification (valid, invalid, replayed, tampered amount), donation state
     machine, recurring subscription lifecycle, cart and checkout, stock reservation and
     release, coupon rules, order status transitions, refunds, email queueing, SMS
     queueing, newsletter double opt-in, and contact form spam protection.
   - Browser tests for the critical paths: complete a donation, complete a purchase,
     toggle the theme, navigate by keyboard, and submit each public form.
   - Paystack: an HTTP fake for unit/feature tests plus a documented manual test run
     against Paystack test keys using test cards and test mobile money numbers.
   - Seeded demo data so I can click through a realistic site.

2. QUALITY GATES
   - GitHub Actions running Pint, static analysis (PHPStan/Larastan), and the test suite on
     every pull request; merging to `main` blocked on failure.
   - Code coverage target and a coverage report.

3. MANUAL QA
   - A written test plan covering every user journey, on Chrome, Firefox, Safari, and
     Android Chrome, in both themes, at all breakpoints.
   - A UAT checklist I can hand to a non-technical colleague, written in plain English.
   - Load sanity check: what happens under a burst of traffic from a campaign, and what the
     shared-hosting limits are.
   - A bug triage process and issue templates.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 15 — Performance & Shared-Hosting Optimisation

```text
PHASE 15 — PERFORMANCE ON INMOTION SHARED HOSTING

Optimise specifically for the constraints of shared hosting and Ghanaian network conditions.

- Caching strategy without Redis: config/route/view/event caching, database or file cache
  driver, cached settings, cached menus, cached homepage blocks, cached donation totals
  with event-based invalidation, and full-page caching for anonymous visitors where safe
  (never for cart, checkout, account, or admin).
- Eliminate N+1 queries; add eager loading; add the missing indexes; paginate everything;
  use chunking for exports and bulk sends.
- Queue tuning for a cron-driven worker: batch sizes, timeouts, memory limits, and
  preventing overlapping runs.
- Asset pipeline: hashed filenames, long cache headers, code splitting, tree shaking,
  removing unused Tailwind, self-hosted subset fonts, SVG sprites, and inlining critical CSS.
- Images: responsive srcset, modern formats with fallbacks, lazy loading below the fold,
  eager loading for the LCP image, and CDN offloading through Cloudflare.
- Database maintenance: table optimisation, log and session pruning, activity-log pruning,
  and archiving old webhook payloads.
- Inode and disk management: this matters on shared hosting — a media library can exhaust
  the inode limit. Give me a monitoring and cleanup plan.
- A performance budget and a Lighthouse target for mobile; run it and report before/after
  numbers.
- Guidance on when we will outgrow shared hosting and what the migration path to a VPS
  looks like.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 16 — Documentation & Handover

```text
PHASE 16 — DOCUMENTATION

Act as the technical documentation writer. Produce documentation good enough that a new
developer, and a non-technical administrator, can each take over without me.

1. TECHNICAL DOCS (in the repo, as Markdown)
   - README: what this is, the stack, requirements, local setup, and common commands.
   - ARCHITECTURE: module map, request lifecycle, key services, and design decisions with
     rationale.
   - DATABASE: schema reference and the ER diagram.
   - DEPLOYMENT: the full GitHub → cPanel runbook, including rollback.
   - PAYMENTS: how the Paystack integration works end to end, every webhook event, the
     donation state machine, and how to debug a "payment taken but not recorded" report.
   - ENVIRONMENT: every `.env` key explained.
   - SECURITY: the security model, and the incident response runbook.
   - TESTING: how to run tests and how to add them.
   - TROUBLESHOOTING: the twenty most likely failures and their fixes.
   - CONTRIBUTING and CHANGELOG.

2. ADMIN USER MANUAL (for the foundation staff, plain English, with screenshots)
   - How to log in and set up 2FA.
   - How to edit the header, footer, menus, and homepage.
   - How to create a page, a project, a cause, a post, a product, and an event.
   - How to upload and manage images and write good alt text.
   - How to view donations, export reports, record an offline donation, and resend a receipt.
   - How to process an order and update its status.
   - How to send a newsletter and a bulk SMS, including the cost implications.
   - How to change the theme colours, and how to switch between light and dark.
   - What NOT to touch, and who to call.
   - A one-page quick-reference card.

3. OPERATIONS
   - A maintenance calendar: daily, weekly, monthly, quarterly, and annual tasks.
   - A monitoring checklist and escalation contacts.
   - A licence and credits page for any third-party assets.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 17 — Launch

```text
PHASE 17 — GO-LIVE

Produce and then walk me through a complete launch runbook.

PRE-LAUNCH
  - Content: every placeholder replaced, all legal pages published and reviewed, contact
    details correct, team and board photos in, at least three real projects and three real
    causes live, shop stocked.
  - Paystack: business verified and settlement account confirmed, live keys installed,
    live webhook URL registered and tested, a real GH₵ 1 donation made and refunded, and
    settlement timing understood.
  - Email: SPF, DKIM, DMARC verified; a test send to Gmail, Yahoo, and Outlook checked for
    inbox placement.
  - SMS: sender ID approved, credits loaded, a live test message received.
  - Domain and SSL: DNS pointed, HTTPS forced, www/non-www canonical decided and redirected,
    certificate auto-renewal confirmed.
  - Search: Google Search Console and Bing verified, sitemap submitted, analytics live and
    goals configured.
  - Backups: first successful backup taken AND a restore rehearsed on staging.
  - Cron: scheduler and queue worker confirmed running in production.
  - Security: final scan, admin 2FA on every account, default credentials removed, debug
    mode OFF, `APP_ENV=production`, error pages verified, staging noindexed.
  - Performance: final Lighthouse run on mobile.
  - Accessibility: final axe run, zero critical issues.
  - Cross-browser and cross-device sign-off in both themes.
  - A rollback plan, tested.

LAUNCH DAY
  - Ordered cut-over steps with owners and timings, a smoke-test checklist to run
    immediately after, and a monitoring window.

POST-LAUNCH (first 30 days)
  - Daily donation reconciliation against Paystack.
  - Error and uptime monitoring review.
  - Search Console coverage and Core Web Vitals review.
  - Staff training session and a feedback loop.
  - A prioritised backlog of everything deferred.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

# PHASE 18 — Roadmap (post-launch enhancements)

```text
PHASE 18 — PHASE-TWO ROADMAP

Propose and then, on my approval, build a prioritised roadmap. Consider at least:

  - Progressive Web App with offline pages and add-to-home-screen.
  - USSD or short-code giving for donors without smartphones.
  - WhatsApp Business integration for receipts, updates, and enquiries.
  - Donor portal upgrades: giving history, receipts archive, impact timeline, and
    self-service subscription management.
  - A public REST API and webhooks for partners; a mobile app backend.
  - Grant and institutional-donor management, proposal pipeline, and reporting.
  - Beneficiary case management with strict access controls.
  - Multi-currency display with GHS settlement for diaspora donors.
  - Multilingual content (English, Twi, Ga, Ewe, French).
  - Accounting integration (QuickBooks/Xero/Zoho) and automated financial reporting.
  - Advanced donor segmentation, lifecycle automation, and lapsed-donor win-back journeys.
  - A/B testing on the donation form.
  - Matching-gift and corporate-match campaigns.
  - Live campaign thermometer for events and a public donation wall/screen mode.
  - Team/office management, staff intranet, or a board portal.
  - AI-assisted content drafting inside the CMS, with human approval required.

For each: value, effort, risk, dependencies, and a recommended sequence.

At the end of this phase: (a) list anything I missed, forgot, or under-specified;
(b) list anything you recommend adding to make this production-ready; (c) list decisions
you need from me before the next phase; (d) state what is now testable and how I verify it.
```

---

## Reference notes to keep with the project

### Money handling
Store all amounts as integer **pesewas**. GH₵ 50.00 → `5000`. Send `5000` to Paystack with
`"currency": "GHS"`. Format for display as `GH₵ 50.00`. Never use floats for arithmetic on
money.

### Paystack webhook verification (the shape it must take)
Read the raw body, compute `hash_hmac('sha512', $rawBody, $secretKey)`, compare with
`hash_equals()` against the `x-paystack-signature` header, store the event, respond `200`,
then process on the queue, idempotently keyed on the event id and transaction reference.

### Shared-hosting cron entries
```
* * * * * /usr/local/bin/php /home/CPANELUSER/app/artisan schedule:run >> /dev/null 2>&1
* * * * * /usr/local/bin/php /home/CPANELUSER/app/artisan queue:work --stop-when-empty --max-time=55 --tries=3 >> /dev/null 2>&1
```
Confirm the PHP binary path from cPanel's MultiPHP / PHP Selector, and adjust the app path.

### Things people commonly forget on projects like this
Donation receipts as PDFs · a General Fund fallback cause · covering the payment fee ·
offline/cash donation recording · reconciliation against settlements · refund policy page ·
consent for beneficiary photographs · EXIF stripping · sender-ID registration for SMS ·
SPF/DKIM/DMARC · a tested backup restore · inode limits · staging noindex ·
`APP_DEBUG=false` in production · rollback plan · admin 2FA · a 404-to-redirect workflow ·
dark-mode review of every single component · low-bandwidth performance ·
who owns the domain and the Paystack account.

---

*End of prompt pack. Run Phase 0, then Phase 1, and keep the gap-check clause at the end of
every phase.*
