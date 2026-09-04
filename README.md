# St. Cecilia's Greater Hope Foundations — Web Application

Donation, fundraising and e-commerce platform for a Ghanaian foundation.
Laravel 13 · Livewire 4 · Filament 5 · Tailwind · MySQL · Paystack (GHS) · shared cPanel hosting.

> *"To turn remembrance into impact."*

---

## What this is

A production web application for a registered Ghanaian non-profit, built around four divisions:

| Division | Remit |
|---|---|
| **Life Spring Foundation** | Health |
| **BrightPath Fund Initiative** | Education |
| **Legacy of Love Initiative** | Orphans, Widows & Widowers |
| **Every Soul Missions** | Evangelism |

It accepts one-off and recurring donations in Ghanaian Cedis via Paystack (**Mobile Money first** — it is how Ghana pays), runs a fundraising shop, showcases projects and measured impact, and is fully editable by non-technical staff through a Filament CMS.

---

## Documentation map

| Document | What it covers |
|---|---|
| **`CLAUDE.md`** | Standing project context. Decisions already made — read before changing anything. |
| **`docs/PHASE-1-BLUEPRINT.md`** | The project brief: brand tokens, sitemap, roles, user journeys, module list, risk register, environment plan. |
| **`FOUNDATION-WEBAPP-MASTER-PROMPT.md`** | The phase sequence, Phase 0 → 18. |
| **`docs/PHASE-2-RUNBOOK.md`** | ⭐ Setup and deployment, step by step, with expected output and failure modes. |
| **`docs/PHASE-3-DATA-ARCHITECTURE.md`** | The schema: ~150 tables by module, conventions, and the binding migration-safety policy. |
| **`docs/DEPENDENCIES.md`** | Why each package is here, and what was deliberately rejected. |
| **`CHANGELOG.md`** | What changed, when. |

---

## Local setup

**Requires PHP 8.4+, Composer 2, Node 20+.** On Windows, `winget install --id BeyondCode.Herd -e` supplies PHP and Composer together. See `docs/PHASE-2-RUNBOOK.md` Step 0 — and Step 2.5 for the local MySQL, which Phase 3 onward needs.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
```

Then `php artisan serve`.

Full first-time setup — including generating the Laravel skeleton — is **`docs/PHASE-2-RUNBOOK.md`**.

---

## The rules that are not negotiable

These are in `CLAUDE.md` in full. The short version, because getting them wrong costs money or trust:

**Money.** Every amount is an **integer of pesewas** (GHS × 100). Never a float. `GH₵ 50.00` is `5000`. Every Paystack call carries `"currency": "GHS"`.

**Payment truth comes from the webhook, never the browser redirect.** Verify `hash_hmac('sha512', $rawBody, $secret)` against `x-paystack-signature` with `hash_equals()`. Store the raw event, respond 200 immediately, process on the queue, and make processing idempotent — a replayed event must never double-count a donation.

**No hardcoded content in Blade.** No strings, phone numbers, emails, addresses, colours or images. Everything comes from the CMS layer. If you are about to hardcode real content, move it into settings instead.

**Tests are required** for anything touching money, auth, or webhooks. Not optional.

**Both themes, every component.** Light and dark, each checked for WCAG 2.2 AA contrast individually — not by inverting colours and hoping.

**Shared hosting.** No Docker, no root, no Redis, no Supervisor, no persistent Node process. Flag anything that needs them before writing it.

**Every message goes through `MessageDispatcher`.** One door out, so the suppression list, the logging and the hourly rate limit are true rather than merely available. A second send path would be a path with no suppression check on it — and mail to somebody who asked us to stop is what stops receipts being delivered to everybody else.

**Flagged means built, not noted.** If something is identified as needed, the schema goes in and it gets built in the same phase — see `CLAUDE.md`. A genuine deferral is a feature flag that is *off* plus a line in the open-questions table; anything else is a gap that reads as a feature to whoever audits it next.

**The theme comes from the database, and the server decides it.** Colour tokens live in `theme_settings` and are inlined into the head — changing the brand colour is an edit in the admin panel, not a deploy. A cookie carries the visitor's choice so the server can render `.dark` in the HTML itself; localStorage alone would mean every page paints light and then corrects itself, which is the flash.

**No image is published until its metadata has been removed.** A photograph taken on a phone carries the coordinates of where it was taken, which here is frequently a beneficiary's home. `Media::isPublishable()` refuses anything unsanitised, and "we have not checked yet" is refused the same way as "we checked and it failed".

**Reading personal data is an auditable action.** Anything that opens a beneficiary's file, exports records, or takes data out of the application records an entry through `AuditLogger`. Those actions change no model, so nothing else would notice them — and *who read this?* is the question that matters most for the records this foundation holds.

---

## Branches and deployment

| Branch | Deploys to | How |
|---|---|---|
| `main` | `greaterhopefoundations.com` | PR only, CI green, manual approval |
| `develop` | `staging.greaterhopefoundations.com` | PR from `feature/*` |
| `feature/*` | — | branched from `develop` |

Push → GitHub Actions runs the quality gate, builds `vendor/` and the Vite assets **on the runner**, rsyncs to cPanel over SSH port 2222 into a timestamped release directory, runs migrations, warms caches, then flips the `current` symlink. The server never needs Composer or Node.

Rollback is one symlink move: `deploy/scripts/rollback.sh`.

---

## Repository layout

```
app/  bootstrap/  config/  database/  public/  resources/  routes/  tests/   Laravel
.github/workflows/       ci.yml (PR gate) · deploy.yml (build + ship)
deploy/scripts/          bootstrap-server.sh · activate.sh · rollback.sh
deploy/cpanel/           cron.txt · .cpanel.yml (documented fallback)
docs/                    runbook, dependencies
```

---

## Contributing

Commit format: `type(scope): subject` — see `.gitmessage` (`git config commit.template .gitmessage`).
Every PR uses `.github/PULL_REQUEST_TEMPLATE.md`; the checklists are the review standard.
Run `vendor/bin/pint` before pushing.

**Never commit a secret.** `.env` is ignored; `.env.example` documents every key. CI fails the build if a live key or a private key appears in tracked files.

---

## Status

**Phase 2 complete** — environment, repository, deployment pipeline.
**Phase 3 complete** — all eight modules landed: core identity · settings & CMS · programmes · fundraising · shop · engagement · communications · system, plus a gap sweep. ~131 tables.

**Phase 4 in progress** — application foundation. Landed: the admin panel and its front door (Filament at a configurable path, mandatory TOTP, sign-in recording), a policy for every model with a test that keeps it that way, and the layout shell (theme system, header and footer from the seeded menus, branded error pages). Still to come: public donor accounts and the media library UI. See `CHANGELOG.md`.

> The admin panel is at **`/scghf-office`**, not `/admin` — set by `ADMIN_PATH`.

**Hosting is not yet chosen.** New hosting running PHP 8.4 will be procured; the project does not launch on the shared account it was scaffolded against. The pipeline is host-agnostic by design, so only values change — `SSH_HOST`, `SSH_USER`, `DEPLOY_PATH`, `PHP_BIN`, `APP_URL`. Runbook steps 7–11 are deferred until then.

Placeholders are tracked in `docs/PHASE-1-BLUEPRINT.md` §0. Paystack credentials and the SMS sender ID are deliberately placeholdered: the payments module is built and fully tested against a fake gateway, and SMS runs on the `log` driver, until the real accounts exist.

---

*Built in memory of Mrs Cecilia Anyatuik Adam.*
