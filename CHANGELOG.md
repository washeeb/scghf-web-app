# Changelog

All notable changes to this project are recorded here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions are phase-based until launch, then [SemVer](https://semver.org/).

---

## [Unreleased]

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
- **Local PHP 8.4 vs server PHP 8.3:** `config.platform.php` pinned to `8.3.0` so Composer
  resolves the lockfile for the server, not the workstation. Both CI workflows already pin 8.3.

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
