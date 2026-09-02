# Phase 2 Runbook — Environment, Repository & Deployment

**Every click and every command, in order, with expected output and what to do when it fails.**

| | |
|---|---|
| Account | cPanel `presti98` on InMotion Hosting · cPanel 134.0.53 |
| Home | `/home/presti98` |
| Production | `greaterhopefoundations.com` → docroot `/home/presti98/greaterhopefoundations.com` |
| Staging | `staging.greaterhopefoundations.com` → docroot `/home/presti98/staging.greaterhopefoundations.com` |
| PHP | **8.4 (`ea-php84`)** — the project target. 8.3 is the server system default and is overridden per-domain |
| SSH | enabled, **port 2222** |
| Strategy | GitHub Actions → build on runner → rsync → atomic symlink flip |

> ## ⚠️ Hosting decision — 2026-09-02
>
> **New hosting will be procured, running PHP 8.4. The `presti98` account is no longer the deployment target.**
>
> That splits this runbook in two:
>
> | Steps | Status | Why |
> |---|---|---|
> | **0–6** — local toolchain, Laravel skeleton, packages, Git, GitHub repo, deploy key | ✅ **Do now.** Host-independent. | Nothing here depends on which server we end up on. |
> | **7–11** — cPanel config, server bootstrap, cron, SSL, first deploy | ⏸ **Deferred** until the new host exists. | Doing these on `presti98` would be thrown away, and it would put donor-facing infrastructure on an account we are leaving. |
>
> **What survives the host change unchanged:** the whole pipeline design — build on the runner, rsync over SSH, atomic release symlink, rollback script. That was the point of the portability rules in `PHASE-1-BLUEPRINT.md` §11.6.1, and they are now being cashed in rather than theorised about.
>
> **What changes:** values, not architecture. `SSH_HOST`, `SSH_PORT`, `SSH_USER`, `DEPLOY_PATH`, `PHP_BIN`, `APP_URL`, the docroot path, and the cron syntax if the new host is not cPanel. All are GitHub secrets or one-line edits.
>
> **What this resolves:** risks **SH-18** (shared cPanel account with an unrelated business), **OPS-9** (infrastructure in the wrong entity's name), **OPS-11** (donor data on a third party's account) and **OPS-12** (the move never happening). All four were accepted-with-mitigation; a clean host in the foundation's name closes them properly.
>
> **Tell me when you have picked the host** — specifically whether it is cPanel or a VPS, and whether it runs MySQL or MariaDB. Those two answers are all I need to re-point steps 7–11.

---

> **Work top to bottom.** Steps 0–3 are on your machine, 4–6 on GitHub. Steps 7–11 wait for the new host.

---

## ⛔ Step 0 — Install PHP and Composer locally

**This is currently blocking.** Your machine has Git 2.55, Node 24.20 and OpenSSH, but **no PHP and no Composer**, so `composer create-project` cannot run and the Laravel skeleton cannot be generated.

Pick one. **Herd is the recommendation** — it is the only option that installs PHP *and* Composer together with extensions already enabled. Composer is not in winget at all, and the winget PHP package ships with every extension commented out.

### Option A — Herd (recommended)

```powershell
winget install --id BeyondCode.Herd -e
```

> The ID is `BeyondCode.Herd`, not `Laravel.Herd`. Herd is published by Beyond Code.

Close and reopen PowerShell, then:

```powershell
php -v; composer -V
```

**Expected:** `PHP 8.3.x` (or 8.4.x) and `Composer version 2.x`.

**If `php` is still not found:** open Herd once from the Start menu. It registers its PATH entry on first launch. Reopen your terminal afterwards.

**If Herd's PHP is older than 8.3:** open Herd → Settings → PHP and install 8.3 or 8.4, then set it active.

### Option B — PHP and Composer separately

Use this if you would rather not install a GUI app. It is three steps instead of one, and the third is the one people miss.

```powershell
winget install --id PHP.PHP.8.3 -e
```

Composer has no winget package — download and run the official Windows installer from **getcomposer.org/download** (`Composer-Setup.exe`). It will detect the PHP you just installed.

Then **enable the extensions**, which the winget package leaves entirely commented out. This script does it for you — run it in the same elevated PowerShell:

```powershell
$ini = (php --ini | Select-String 'Loaded Configuration File:').ToString().Split(':',2)[1].Trim()
if ($ini -eq '(none)') {
  $dir = Split-Path (Get-Command php).Source
  Copy-Item "$dir\php.ini-development" "$dir\php.ini"
  $ini = "$dir\php.ini"
  "Created $ini"
}
Copy-Item $ini "$ini.bak" -Force
$need = 'curl','fileinfo','gd','intl','mbstring','openssl','pdo_mysql','sodium','zip','exif','bcmath'
$text = Get-Content $ini -Raw
foreach ($e in $need) { $text = $text -replace "(?m)^\s*;\s*(extension\s*=\s*$e\s*)$", '$1' }
Set-Content $ini $text -Encoding UTF8
"Enabled: $($need -join ', ')"
```

### Verify before continuing — either option

```powershell
php -v
composer -V
php -m
```

**Expected:** PHP 8.3+, Composer 2.x, and the module list includes `bcmath ctype curl dom exif fileinfo gd iconv intl mbstring openssl pdo_mysql sodium tokenizer xml zip`.

Check for anything missing in one line:

```powershell
$have = php -m; 'bcmath','ctype','curl','dom','exif','fileinfo','gd','iconv','intl','mbstring','openssl','pdo_mysql','sodium','tokenizer','xml','zip' | Where-Object { $have -notcontains $_ }
```

**Expected:** no output. Anything printed is missing.

**If something is missing:** Laravel will still install, but Filament or Media Library will fail later with an obscure error. Fix it now — under Option B re-run the script above; under Herd, report it to me, because a missing extension in Herd is unusual and worth understanding.

> `intl` and `gd` are the two that most often catch people out, and they are exactly the two Media Library and date formatting need.

> ✅ **Done on this machine — 2026-09-02.** Herd 1.30.0, **PHP 8.4.25**, Composer 2.10.2, all 16 extensions present. Herd needed launching once from the Start menu before it downloaded a PHP runtime and registered its PATH entry; installing it via winget alone is not enough.

---

## Step 1 — Generate the Laravel skeleton

The repository already contains the Phase 2 overlay: CI workflows, deploy scripts, `.env.example`, `.gitignore`, `.htaccess`, docs. **These must not be overwritten.** So we generate Laravel into a temporary folder and merge only the files that do not already exist.

```powershell
cd "E:\Businesses\2. Greater Hope Foundations\SCGHF-Web-App"
composer create-project laravel/laravel:^13.0 .laravel-tmp --no-interaction
```

**Expected:** Composer downloads the skeleton and its dependencies, ending with `Application ready! Build something amazing.`

**If `laravel/laravel:^13.0` does not resolve:** Laravel 13 may not be released under that constraint yet. Run `composer show laravel/laravel --all | Select-String "^versions"` to see what exists, and use the highest stable major. Tell me what it reports — the framework version is a locked decision in `CLAUDE.md` and a change needs discussing, not assuming.

Now merge. `/XC /XN /XO` means **copy only files that do not already exist**, so every Phase 2 file survives:

```powershell
robocopy .laravel-tmp . /E /XC /XN /XO /NFL /NDL /NJH /NJS
```

**Expected:** exit code 1 (robocopy returns 1 for "files copied", which is success — anything ≥ 8 is a real error).

```powershell
Remove-Item .laravel-tmp -Recurse -Force
```

### Pin the Composer platform to 8.4

`config.platform.php` tells Composer which PHP to resolve *for*, independent of what it happens to be running on. Without it, the lockfile reflects your workstation rather than the server — and the lockfile is what ships.

```powershell
composer config platform.php 8.4.1
composer update --no-interaction
composer check-platform-reqs
```

**Expected:** `composer.json` gains a `config.platform.php` block; `check-platform-reqs` reports every requirement as `success`.

> #### Why 8.4 and not 8.3 — decided 2026-09-02
>
> Phase 1 planned to target 8.3, the server's system default. Attempting it produced two hard failures:
>
> - **`pestphp/pest-plugin-laravel` v5 requires PHP ^8.4.** Falling back to Pest 4 does not help — Pest 4 conflicts with the PHPUnit 12.5 that Laravel 13 ships, and Pest 5 requires PHPUnit 13, which itself requires **PHP ≥ 8.4.1**. On 8.3, Pest is not installable at all. `CLAUDE.md` mandates Pest and makes tests non-optional for money, auth and webhook code, so this was not a preference.
> - **`spatie/laravel-sitemap` has no version compatible with both PHP 8.3 and Guzzle 8.** Versions supporting Guzzle 8 (which Laravel 13 ships) require PHP ^8.4; every older version pins Guzzle ^7.
>
> Laravel 13 + Symfony 8 + Filament 5 is a PHP 8.4-era stack, and the same conflict would have recurred with every future package. The server already has `ea-php84` installed and running the sibling domain, and 8.4 has a longer security runway (EOL ~Dec 2028 vs ~Dec 2027).
>
> **The pin is `8.4.1`, not `8.4.0`** — PHPUnit 13 requires ≥ 8.4.1 exactly. This is a floor, so the server running a later patch is fine.
>
> ⚠️ **Still to verify:** the actual patch version and extension set of `ea-php84` on the server. `php -v` and `php -m` in Step 8's bootstrap will report both. If `ea-php84` turns out to be older than 8.4.1, or is missing an extension `ea-php83` has, tell me before deploying.

Confirm the merge kept our files and added Laravel's:

```powershell
Test-Path artisan, composer.json, app, config, routes, public\index.php, public\.htaccess, .env.example, .github\workflows\deploy.yml
```

**Expected:** eight `True` values.

```powershell
Select-String -Path public\.htaccess -Pattern "Greater Hope" -Quiet
```

**Expected:** `True` — confirming our hardened `.htaccess` was not overwritten by Laravel's default.

---

## Step 2 — Install the project dependencies

Each package, and why it is here. **Justifications and shared-hosting alternatives** are in `docs/DEPENDENCIES.md`; run them in this order.

```powershell
composer require filament/filament:"^5.0" --no-interaction
```
Admin panel and CMS. This is the largest dependency in the project and the one the foundation's staff will live in every day.

```powershell
composer require livewire/livewire spatie/laravel-permission spatie/laravel-medialibrary spatie/laravel-sluggable spatie/laravel-activitylog spatie/laravel-sitemap spatie/laravel-honeypot --no-interaction
```

```powershell
composer require spatie/laravel-backup --no-interaction
composer require --dev pestphp/pest pestphp/pest-plugin-laravel laravel/pint --no-interaction
```

```powershell
npm install
```

That is all that is needed. **Do not add Tailwind or Alpine separately:**

- Laravel 13 already ships `tailwindcss` ^4 and `@tailwindcss/vite` ^4. Tailwind 4 is CSS-first — there is no `tailwind.config.js` and no `autoprefixer` to add.
- **Livewire 4 bundles Alpine.** Installing `alpinejs` as well gives you two Alpine instances on the page, which breaks `x-data` in ways that are genuinely hard to diagnose.

**`package-lock.json` must be committed.** Both workflows use `npm ci`, and `actions/setup-node` with `cache: npm` fails outright without a lockfile — which is exactly how the first CI run failed.

**Expected:** each completes without a dependency conflict.

**If Filament v5 does not resolve:** check what is current with `composer show filament/filament --all`. `CLAUDE.md` locks v5; if only v4 exists, stop and tell me rather than silently downgrading.

**If `spatie/laravel-medialibrary` complains about `ext-exif` or `ext-imagick`:** it needs one image driver. GD is enough and is what the server has; Imagick is better but often absent on shared hosting. We use GD.

Publish what needs publishing, then verify the app boots:

```powershell
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"
php artisan pest:install
```

```powershell
Copy-Item .env.example .env
php artisan key:generate
php artisan about
```

**Expected:** the environment table prints, showing Laravel's version, PHP 8.3+, and the `database` cache/queue/session drivers.

**If it errors on the database:** expected at this point — no database exists yet. Anything else is a real problem.

---

## Step 2.5 — Local database

The app currently runs on **SQLite** so it would boot without a database server. That is fine for Phase 2, and **not** fine for Phase 3.

**Why it has to change before Phase 3.** Phase 3 designs the full schema — integer-pesewa `BIGINT UNSIGNED` money columns, composite indexes, `utf8mb4` collations, foreign keys with specific `onDelete` behaviour, and the row-size and index-count discipline `CLAUDE.md` calls for. SQLite silently tolerates several things MySQL rejects: it ignores index key-length limits, does not enforce `ENUM`, is lax about `ALTER TABLE`, and only enforces foreign keys when explicitly switched on. Designing a donation ledger against a database that forgives what production will not is how you discover a schema problem after go-live instead of during Phase 3.

Install MySQL 8.4 LTS — it matches `CLAUDE.md` and is the most likely production engine:

```powershell
winget install --id Oracle.MySQL -e
```

The installer asks for a **root password**. Save it in your password manager; you will need it again.

Then create the development database:

```powershell
mysql -u root -p -e "CREATE DATABASE scghf_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE scghf_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Switch the local `.env` off SQLite:

```powershell
(Get-Content .env) -replace '^DB_CONNECTION=sqlite.*', 'DB_CONNECTION=mysql' `
  -replace '^# DB_DATABASE=.*', 'DB_DATABASE=scghf_dev' | Set-Content .env
Add-Content .env "`nDB_HOST=127.0.0.1`nDB_PORT=3306`nDB_USERNAME=root`nDB_PASSWORD=<your root password>"
```

Verify:

```powershell
php artisan migrate:fresh
php artisan db:show
```

**Expected:** `db:show` reports MySQL 8.4.x, database `scghf_dev`, and the migration tables.

> ⚠️ `migrate:fresh` **drops every table.** It is safe here because this is a local database with no real data. It must never be run against staging or production — `CLAUDE.md` and the Phase 3 migration-safety policy both prohibit it.

**If you would rather not install MySQL yet:** SQLite will carry you through the rest of Phase 2. But do this before Phase 3 starts, and tell me if you skip it, because it changes how much I can trust a green local test run.

---

## Step 3 — First commit

The repository was initialised when Phase 2 started. Set the commit template and make the first commit.

```powershell
git config commit.template .gitmessage
git config core.autocrlf false
git add -A
git status --short
```

**Expected:** you see `app/`, `config/`, `.github/`, `deploy/`, `docs/` and so on — and **you must NOT see `.env`, `vendor/`, `node_modules/`, or `public/build/`.**

> ⛔ **If `.env` appears in that list, stop.** `.gitignore` is not being applied. Run `git rm --cached .env` and re-check before going any further. A committed `.env` is a credential leak that survives in history.

```powershell
git commit -m "feat: Laravel 13 skeleton, deployment pipeline, and project scaffolding

Phase 2. Adds the application skeleton, CI and deploy workflows, server-side
release scripts, hardened .htaccess, environment template, and the Phase 2
runbook.

Deployment: GitHub Actions builds on the runner and rsyncs to cPanel over SSH
port 2222, into atomic release directories with a symlink flip."
```

---

## Step 4 — Create the private GitHub repository

⚠️ **This publishes code to an external service.** The repository must be **private** — it will contain the foundation's configuration and, later, seeded content.

```powershell
gh auth status
gh repo create scghf-web-app --private --source=. --remote=origin --description "St. Cecilia's Greater Hope Foundations — donation and fundraising web application"
```

**If you do not use `gh`:** create the repository manually at github.com (private, no README, no .gitignore, no licence), then:

```powershell
git remote add origin https://github.com/<your-username>/scghf-web-app.git
```

Push both branches:

```powershell
git branch -M main
git push -u origin main
git switch -c develop
git push -u origin develop
```

**Expected:** both branches appear on GitHub. Actions will run and **the deploy will fail at "Verify the server is reachable"** — correct, because the server is not bootstrapped yet. Steps 7–9 fix that.

### Branch protection

GitHub → Settings → Branches → Add rule, for **`main`**:

- ✅ Require a pull request before merging
- ✅ Require status checks to pass → select **`Lint, analyse, test`**
- ✅ Require branches to be up to date before merging
- ✅ Do not allow bypassing the above settings

Repeat for `develop` with only the status check requirement.

**Branch strategy**

| Branch | Deploys to | Rule |
|---|---|---|
| `main` | production | PR only, CI green, never pushed to directly |
| `develop` | staging | PR from feature branches; the integration branch |
| `feature/*` | nothing | branched from `develop`, PR back into `develop` |
| `hotfix/*` | — | branched from `main`, PR into **both** `main` and `develop` |

---

## Step 5 — Generate the deploy key

**Do this on your machine, not the server.** A dedicated key, never your personal one — so it can be revoked without locking you out.

```powershell
ssh-keygen -t ed25519 -C "github-actions-deploy-scghf" -f "$env:USERPROFILE\.ssh\scghf_deploy" -N '""'
```

**Expected:** two files — `scghf_deploy` (private) and `scghf_deploy.pub` (public).

> ✅ **Done 2026-09-02.** Ed25519 keypair generated at `~\.ssh\scghf_deploy`. The keypair is host-independent, so it carries over to whatever hosting is chosen. **The rest of this step — importing and authorising the public key — waits for the new host.**
>
> ⚠️ The private key has no passphrase, which is what CI needs. It is therefore a credential in its own right: it lives only at `~\.ssh\scghf_deploy` and in the GitHub `SSH_PRIVATE_KEY` secret, and it goes nowhere else. If it is ever exposed, delete the public key from the host's authorised list and generate a new pair — that is the whole remediation, which is exactly why it is a dedicated key.

Get the public key:

```powershell
Get-Content "$env:USERPROFILE\.ssh\scghf_deploy.pub"
```

**In cPanel → Security → SSH Access → Manage SSH Keys → Import Key:** paste it into the *public key* box, give it the name `github-actions`, and import.

> ⛔ **Then click "Manage" next to the imported key and press "Authorize".**
> An imported-but-unauthorised key does not error — it silently falls back to password authentication, which in CI looks like a hang or a generic auth failure. This is the single most common cPanel SSH mistake.

Test it:

```powershell
ssh -i "$env:USERPROFILE\.ssh\scghf_deploy" -p 2222 presti98@23.235.219.254
```

**Expected:** a shell prompt, with no password requested.

**If it asks for a password:** the key is not authorized. Go back and press Authorize.
**If "Connection refused":** wrong port — it is 2222, not 22.
**If "Permission denied (publickey)":** the public key was pasted with a line break, or you pasted the private key by mistake. Re-copy it as one line.

---

## Step 6 — GitHub secrets and variables

GitHub → Settings → Environments → **New environment** → `production`. Repeat for `staging`.

Generate the host fingerprint first:

```powershell
ssh-keyscan -p 2222 23.235.219.254
```

**Set now — host-independent:**

| Secret | Value |
|---|---|
| `SSH_PRIVATE_KEY` | full contents of `~\.ssh\scghf_deploy`, **including** the `-----BEGIN`/`-----END` lines |

**Wait for the new host — every value below is host-specific:**

| Secret | What it will be |
|---|---|
| `SSH_HOST` | the new server's IP or hostname |
| `SSH_PORT` | `22` on most hosts; the old cPanel account used `2222`. **Do not assume — check.** |
| `SSH_USER` | the new account username |
| `SSH_KNOWN_HOSTS` | `ssh-keyscan -p <port> <host>`. **Must be regenerated for the new host** — a stale fingerprint fails the deploy at "Configure SSH", which is confusing if you have forgotten it was pinned. |
| `DEPLOY_PATH` | `/home/<user>/scghf` on cPanel, or something like `/var/www/scghf` on a VPS |
| `PHP_BIN` | `/opt/cpanel/ea-php84/root/usr/bin/php` on cPanel, or plain `php` on a VPS |

> Create both **Environments** now even though most secrets are still empty. The workflow references them by name, and the environment is where the production approval gate lives.

**Variables** (Environments → Variables, not Secrets):

| Variable | production | staging |
|---|---|---|
| `APP_URL` | `https://greaterhopefoundations.com` | `https://staging.greaterhopefoundations.com` |

On the `production` environment, also tick **Required reviewers** and add yourself. Production deploys then wait for a one-click approval — a cheap guard against an accidental merge going straight to a live donation site.

> **What is deliberately NOT here:** Paystack keys, database credentials, and mail credentials. Those live only in `shared/.env` on the server and never pass through CI.

---

## Step 7 — cPanel setup

### 7.1 Pin the PHP version

**Software → MultiPHP Manager.** `greaterhopefoundations.com` currently shows `PHP 8.3 (ea-php83)` with an **Inherited** badge — it follows the system default, so InMotion could move your production PHP without warning. **The project targets 8.4**, so this both changes the version and pins it.

1. Tick the checkbox for `greaterhopefoundations.com` **only**
2. Confirm "Selected: 1"
3. Set the PHP Version dropdown to **`PHP 8.4 (ea-php84)`**
4. Apply
5. Repeat for `staging.greaterhopefoundations.com` once it exists (step 7.5)

**Expected:** the Inherited badge disappears and the row reads `PHP 8.4 (ea-php84)`.

Then confirm the runtime is actually what we need — this is the verification the platform pin depends on:

```bash
/opt/cpanel/ea-php84/root/usr/bin/php -v
/opt/cpanel/ea-php84/root/usr/bin/php -m
```

**Expected:** PHP **8.4.1 or newer**, and the module list contains all 16 required extensions.

⚠️ **If `ea-php84` is older than 8.4.1**, PHPUnit 13 will not run there. It is dev-only so it never deploys, but tell me — it means the platform pin needs lowering and Pest needs re-checking.
⚠️ **If an extension present on `ea-php83` is missing from `ea-php84`**, stop and raise it with InMotion before switching production over. Common gaps are `intl` and `exif`.

> ⚠️ That dropdown defaults to **PHP 5.6**. Applying it to the wrong domain takes a live site down instantly. Check the selection count before pressing Apply.

**Expected:** the Inherited badge disappears.

### 7.2 Enable PHP-FPM

Same page, PHP-FPM column, toggle it on for `greaterhopefoundations.com`. This keeps PHP workers warm between requests and makes OPcache genuinely effective — the cheapest performance win available on this account.

**If it fails with a memory error:** the FPM pool exceeds the plan's allowance. Ask InMotion to set `pm.max_children` against the 2 GB PMEM ceiling rather than leaving FPM off.

### 7.3 PHP INI values

**Software → MultiPHP INI Editor** → select `greaterhopefoundations.com`:

| Directive | Value |
|---|---|
| `memory_limit` | `256M` |
| `upload_max_filesize` | `32M` |
| `post_max_size` | `32M` |
| `max_execution_time` | `120` |
| `max_input_vars` | `3000` |
| `opcache.enable` | `1` |

`max_input_vars` matters: Filament forms with repeaters post a lot of fields, and the default 1000 silently truncates them.

### 7.4 Databases

**Databases → Database Wizard**, twice:

| | Production | Staging |
|---|---|---|
| Database | `presti98_scghf_prod` | `presti98_scghf_stage` |
| User | `presti98_scghf` | `presti98_scghfstg` |
| Privileges | ALL PRIVILEGES | ALL PRIVILEGES |

**Save both passwords into your password manager now.** They are shown once.

While in phpMyAdmin, note the **Server version** from the home page — it is the last unknown from Phase 1 §4.3.

### 7.5 Staging subdomain

**Domains → Create A Domain**: `staging.greaterhopefoundations.com`, document root `/home/presti98/staging.greaterhopefoundations.com`.

Then **Files → Directory Privacy** on that folder: enable protection, create a user. Staging is now behind a password as well as noindexed.

### 7.6 SSL

**Security → SSL/TLS Status.** Confirm `greaterhopefoundations.com`, `www.greaterhopefoundations.com` and the staging subdomain all show a valid AutoSSL certificate. Run AutoSSL if any is missing.

**Expected:** three green entries.

> Do not enable the HSTS header in `public/.htaccess` until this passes. Browsers cache HSTS for a year and it cannot be retracted early.

### 7.7 Email

**Email → Email Accounts** — create: `info@`, `donations@`, `volunteer@`, `shop@`, `media@`, `safeguarding@`, `noreply@`, `dmarc@`.

**Email → Email Deliverability** — confirm SPF and DKIM are valid **for `greaterhopefoundations.com`**, not just the primary domain. Click Repair if either is amber.

**Domains → Zone Editor** — add a TXT record:

| Name | Value |
|---|---|
| `_dmarc` | `v=DMARC1; p=none; rua=mailto:dmarc@greaterhopefoundations.com; fo=1` |

`p=none` is monitor-only. Move to `quarantine` then `reject` after a few weeks of clean reports.

### 7.8 Account security

**Security → Two-Factor Authentication** — enable it on the cPanel account. This account also hosts a second business; a compromise exposes both.

---

## Step 8 — Bootstrap the server

**cPanel → Advanced → Terminal**, or over SSH. Upload the script first:

```powershell
scp -P 2222 -i "$env:USERPROFILE\.ssh\scghf_deploy" deploy\scripts\bootstrap-server.sh presti98@23.235.219.254:~/
```

Then in Terminal:

```bash
bash ~/bootstrap-server.sh production
```

**Expected:** it creates `/home/presti98/scghf/{releases,shared,backups}`, reports the PHP binary and version, lists any missing PHP extensions, writes a deny-all `.htaccess` in the app root, and converts the docroot into a symlink — moving any existing content to a timestamped `.bak` first. It finishes by printing your GitHub secrets and cron lines.

**Copy the "MISSING PHP extensions" line if there is one** — that goes straight into an InMotion ticket.

Repeat for staging:

```bash
bash ~/bootstrap-server.sh staging
```

### Fill in the real `.env`

```bash
nano /home/presti98/scghf/shared/.env
```

Paste the contents of `.env.example`, then change:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://greaterhopefoundations.com
FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
LOG_LEVEL=warning
ALLOW_SEARCH_INDEXING=true

DB_HOST=localhost
DB_DATABASE=presti98_scghf_prod
DB_USERNAME=presti98_scghf
DB_PASSWORD=<from step 7.4>

MAIL_MAILER=smtp
MAIL_USERNAME=noreply@greaterhopefoundations.com
MAIL_PASSWORD=<mailbox password>

PAYMENT_DRIVER=fake        # until the Paystack account exists
SMS_DRIVER=log             # until a provider is chosen
```

Then generate the key:

```bash
/opt/cpanel/ea-php84/root/usr/bin/php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Paste that into `APP_KEY=`. Save with `Ctrl+O`, `Enter`, `Ctrl+X`.

> ⛔ **`APP_KEY` must never change once real data exists.** It encrypts sessions and any encrypted column. Changing it makes that data unreadable — permanently. Back it up in your password manager the moment you set it.

For **staging**, the same but `APP_ENV=staging`, `ALLOW_SEARCH_INDEXING=false`, the staging database, and a **different** `APP_KEY`.

```bash
chmod 600 /home/presti98/scghf/shared/.env
```

---

## Step 9 — Cron

**cPanel → Advanced → Cron Jobs.** Add the four entries from `deploy/cpanel/cron.txt`. Leave the notification email **blank**, or you will get a mail every minute.

### First, verify per-minute cron is even allowed

Add a temporary job, Once Per Minute:

```
* * * * * /bin/date >> /home/presti98/cron-test.log 2>&1
```

Wait five minutes, then:

```bash
wc -l /home/presti98/cron-test.log
```

**Expected:** `5`.

**If fewer:** InMotion restricts the interval on this plan. Change the production scheduler and queue entries to the permitted frequency (`*/5` or `*/15`) and tell me — the queue design in Phase 8 assumes a cadence and needs adjusting.

**Delete the test cron job and the log file afterwards.**

---

## Step 10 — First deploy

```powershell
git push origin develop
```

Watch GitHub → Actions. The pipeline runs: quality gate → build → deploy to staging.

**Expected, in order** — two jobs, `Quality gate` then `Build & deploy`:

| Job → step | Expected |
|---|---|
| **Quality gate** | Pint clean, tests pass, no secrets found |
| Build frontend assets | `public/build/manifest.json` exists; build size printed |
| Assemble the release tree | file count and total size printed |
| Verify the server is reachable | passes — `shared/.env` was created in step 8 |
| Upload the release | rsync transfers to `releases/<timestamp>-<sha>/` |
| Activate the release | migrations run, caches build, `robots.txt` written, symlink flips, old releases pruned |
| Smoke test | `/` and `/up` return 200; `/.env`, `/vendor/autoload.php`, `/.git/config` all return 403/404 |

Then visit `https://staging.greaterhopefoundations.com` (it will prompt for the Directory Privacy password) — **the Laravel welcome page, over HTTPS.**

When staging is confirmed:

```powershell
git switch main
git merge develop
git push origin main
```

Approve the production deploy when GitHub prompts, then check `https://greaterhopefoundations.com`.

### When the deploy fails

| Symptom | Cause | Fix |
|---|---|---|
| `Permission denied (publickey)` | key not authorized in cPanel | Step 5 — press **Authorize** |
| `Connection refused` | port 22 assumed | it is 2222; check `SSH_PORT` |
| `Host key verification failed` | `SSH_KNOWN_HOSTS` empty or wrong | re-run `ssh-keyscan -p 2222 <host>` |
| `shared/.env is missing` | bootstrap not run | Step 8 |
| `vendor/ missing` | build job failed | check the build job log |
| Migration fails | DB credentials or DB missing | Step 7.4; test with `php artisan db:show` over SSH |
| Homepage 500 | almost always `.env` | `tail -50 /home/presti98/scghf/shared/storage/logs/laravel.log` |
| Homepage 403 | symlinked docroot refused, or perms | see below |
| `/.env` returns 200 | `.htaccess` not applied | **security incident** — see below |

**403 on the homepage.** Apache may have `FollowSymLinks` disabled for addon domains. Test:

```bash
ls -la /home/presti98/greaterhopefoundations.com
```

If it is a valid symlink and you still get 403, use the thin front controller instead of a symlinked docroot:

```bash
rm /home/presti98/greaterhopefoundations.com
mkdir -p /home/presti98/greaterhopefoundations.com
cp /home/presti98/scghf/current/public/.htaccess /home/presti98/greaterhopefoundations.com/
cat > /home/presti98/greaterhopefoundations.com/index.php <<'PHP'
<?php
// Thin front controller. The application lives outside the web root; only this
// file and .htaccess are inside it.
define('LARAVEL_START', microtime(true));
$app = '/home/presti98/scghf/current';
if (file_exists($m = $app.'/storage/framework/maintenance.php')) require $m;
require $app.'/vendor/autoload.php';
$kernel = require_once $app.'/bootstrap/app.php';
$kernel->handleRequest(Illuminate\Http\Request::capture());
PHP
ln -sfn /home/presti98/scghf/current/public/build /home/presti98/greaterhopefoundations.com/build
ln -sfn /home/presti98/scghf/shared/storage/app/public /home/presti98/greaterhopefoundations.com/storage
```

Trade-off: you no longer get the docroot for free on each deploy, so `build/` and `storage/` are symlinked separately. The symlinked docroot is cleaner — try it first.

> ⛔ **If `/.env` ever returns 200:** treat it as a live incident. Rotate every credential in that file immediately — database password, mail password, `APP_KEY`, and Paystack keys if present. Then fix `.htaccess`. Do not reason about whether anyone actually fetched it.

---

## Step 11 — Verify

Run all of these. Every one should pass before Phase 2 is closed.

```bash
# On the server
ls -la /home/presti98/scghf/current                 # symlink → releases/<name>
ls -1 /home/presti98/scghf/releases | wc -l         # ≤ 5
cat /home/presti98/scghf/shared/current_release
/opt/cpanel/ea-php84/root/usr/bin/php /home/presti98/scghf/current/artisan about
/opt/cpanel/ea-php84/root/usr/bin/php /home/presti98/scghf/current/artisan db:show
```

```powershell
# From your machine
curl.exe -I https://greaterhopefoundations.com
curl.exe -o NUL -w "%{http_code}`n" -s https://greaterhopefoundations.com/.env
curl.exe -o NUL -w "%{http_code}`n" -s http://greaterhopefoundations.com    # expect 301
```

**Expected:** `HTTP/2 200` with `x-content-type-options: nosniff` and `referrer-policy` present; `403` or `404` for `.env`; `301` for plain HTTP.

**Rollback drill — do this once now, while nothing is at stake:**

```bash
ssh -p 2222 presti98@23.235.219.254 \
  "DEPLOY_PATH=/home/presti98/scghf PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php bash -s" \
  < deploy/scripts/rollback.sh
```

Confirm the site still loads, then deploy again to move forward. **A rollback path you have never exercised is not a rollback path.**

---

## Appendix — Managing `.env` safely

**Why it is not in Git:** it holds the database password, mail password, `APP_KEY` and (later) live Paystack keys. Anything in Git history is effectively permanent and visible to everyone with repository access, forever.

**How it stays present on the server:** it lives at `shared/.env`, outside every release. `activate.sh` symlinks it into each new release. Deploys never touch it.

**To change a value:**

```bash
cp /home/presti98/scghf/shared/.env /home/presti98/scghf/shared/.env.bak.$(date +%F)
nano /home/presti98/scghf/shared/.env
/opt/cpanel/ea-php84/root/usr/bin/php /home/presti98/scghf/current/artisan config:cache
```

> The `config:cache` step is **not optional**. With a cached config, editing `.env` alone changes nothing — Laravel reads the cache, and you will chase a phantom bug for an hour.

**Adding a new key:** add it to `.env.example` with a placeholder in the same commit, and note it in the PR's "Manual steps required on deploy". A key that exists only on the server is a key the next person cannot know about.
