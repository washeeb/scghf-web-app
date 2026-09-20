# Deployment — GitHub to cPanel, and back again

The runbook for a release, a hotfix and a rollback. First-time setup of a
server (SSH key, cPanel database, the bootstrap script, cron) is
`PHASE-2-RUNBOOK.md` steps 4–11 and is not repeated here.

## 1. The shape

```
push to develop ──▶ GitHub Actions: CI (lint, analyse, test) ──▶ deploy.yml → STAGING
push to main    ──▶ GitHub Actions: CI                        ──▶ deploy.yml → PRODUCTION

deploy.yml:   test again ──▶ build assets ──▶ rsync a release tree ──▶ ssh activate.sh ──▶ smoke test ──▶ (rollback.sh on failure)

on the server:   $DEPLOY_PATH/
                 ├── current  → releases/<timestamp>-<sha>      (the symlink Apache's docroot follows)
                 ├── releases/<timestamp>-<sha>/                (the last five kept)
                 └── shared/   .env  storage/  backups/  queue.lock  current_release  previous_release
```

Nothing on the server is edited by hand except `shared/.env`. A release is
a directory; going live is a symlink move; going back is the same move
the other way.

## 2. A normal release

1. Merge into `develop`. CI must be green (three checks: *Lint, analyse,
   test*, *Coverage*, *Browser tests*).
2. `deploy.yml` runs for `develop` → the **staging** environment. Watch it
   in Actions; the smoke test hits `/` and `/up` and checks that `.env`,
   logs and `vendor/` are not reachable.
3. On staging: sign in, *Site Health* all green, the journey you changed,
   `X-Page-Cache: hit` on a public page after one reload.
4. Open a PR `develop → main`. Merge. The same workflow deploys
   **production** (the `production` GitHub environment — add a required
   reviewer there if you want a human gate; there is none by default).
5. After: *Site Health → Environment* shows the new `APP_RELEASE`;
   `/up` is 200; one page of the site in each theme.

What `activate.sh` does, in order, and why the order:

| Step | Detail |
|---|---|
| link `storage/` and `.env` from `shared/` | uploads and settings survive every release |
| environment guards | refuses production with `APP_DEBUG=true` or a `sk_test_` key; warns on `PAYMENT_DRIVER=fake` |
| **pre-deploy database dump** | `mysqldump` to `shared/backups/pre-deploy-<release>.sql.gz` (last five kept) — the nightly backup is up to a day old and a rollback after a bad migration needs the data from *before* it |
| `migrate --force` | **before** the flip; migrations are expand-only by policy so the live release keeps working against the new schema for the seconds until the symlink moves. A failing migration stops here and the live site is untouched |
| `db:seed --class=RoleAndPermissionSeeder`, `scghf:encrypt-at-rest --execute` | both idempotent: a permission a release introduced reaches the roles that hold it (otherwise a 403 on a new screen), and any column a release moved to an `encrypted` cast is swept |
| stamp `APP_RELEASE` into `shared/.env` | *Site Health* shows which build is live |
| `config:cache`, `route:cache`, `view:cache`, `event:cache`, `filament:optimize`, `icons:cache` | built in the **new** directory, before it is live |
| `scghf:preflight` | placeholders still in settings, missing keys, flags on with nothing behind them, cron and queue heartbeats. Printed on every deploy; **stops a production deploy** only once the GitHub variable `PREFLIGHT_GATE=1` is set on the production environment (the first deploy cannot pass it — cron points at `current/`, which does not exist until the flip) |
| flip `current` | `ln -sfn` — atomic |
| `queue:restart`, `scghf:cache-clear`, `scghf:opcache-reset`, `up` | the next cron worker picks up the new code; pages stored by the old release are gone; the web workers' OPcache is emptied through a one-time token (PHP-FPM keeps bytecode across deploys — without this the site can serve the previous release's code after the flip). If the workers still run a release without that route, a one-off file under `public/deploy/` does the reset instead. The release's `public/.user.ini` sets `opcache.revalidate_path=1`, so the docroot symlink is resolved per request and a new release is new files to OPcache — with the host's default (`0`) the workers kept the first resolution and never saw a flip at all |
| prune to five releases | disk and inodes |

## 3. A hotfix

Same path, faster: branch from `main`, fix with its test, PR to `main`,
merge. Then merge `main` back into `develop` so the branches do not drift.
Never push to `main` from a machine; never `--force`.

`workflow_dispatch` lets you run the deploy by hand from the Actions tab
with **Skip migrations** ticked — for the one case where a migration was
run manually on the server and running it again would fail.

### 3a. When Actions cannot run

On 2026-09-20 every workflow run was refused: *"The job was not started
because recent account payments have failed or your spending limit needs
to be increased"* (GitHub → Settings → Billing & plans). Until that is
settled nothing deploys by itself. A release can be shipped by hand by
replaying the workflow's steps from a developer machine:

1. `npm run build`, then `bash deploy/scripts/build-release-locally.sh /tmp/scghf-release`
   — the same tree the workflow assembles (no-dev vendor, Vite output, the
   same exclusions).
2. Name it and upload it (rsync is not on Windows; tar over SSH is):
   `REL=$(date -u +%Y%m%d-%H%M%S)-$(git rev-parse --short HEAD)`, then
   `(cd /tmp/scghf-release && tar czf - .) | ssh -p 2222 n789825@HOST "mkdir -p ~/scghf-staging/releases/$REL && tar xzf - -C ~/scghf-staging/releases/$REL"`.
3. Activate exactly as the workflow does:
   `ssh -p 2222 n789825@HOST "DEPLOY_PATH=/home/n789825/scghf-staging REL=$REL PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php SKIP_MIGRATIONS=0 PREFLIGHT_GATE=0 bash -s" < deploy/scripts/activate.sh`.
4. Run the smoke checks from §2 by hand (`/up` → 200, `/.env` → not 200).

The quality gate does not run this way: run `php artisan test` locally
first. For production substitute `~/scghf` and `PREFLIGHT_GATE=1`.

## 4. Rollback

Two kinds.

**Automatic.** If the smoke test fails after the symlink moved, the
workflow runs `rollback.sh`: the previous release becomes `current`, the
caches are rebuilt in it, the queue is restarted. Total: seconds. The
workflow is marked failed; read the log.

**By hand** (a bad release that passed the smoke test):

```bash
ssh -p $SSH_PORT $SSH_USER@$SSH_HOST
DEPLOY_PATH=/home/<account>/scghf PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php bash /home/<account>/scghf/current/deploy/scripts/rollback.sh
```

`rollback.sh` reads `shared/previous_release`, flips, rebuilds, and prints
what to check next. Or name a release: `... rollback.sh <release-dir-name>`.

**What a rollback does not undo: the database.** Migrations are
expand-only, so the old code runs against the new schema. If the bad
release's migration itself was wrong — a column with the wrong type, a
data migration that mangled rows — restore the pre-deploy dump it made:

```bash
ls -1t /home/<account>/scghf/shared/backups/pre-deploy-*.sql.gz | head -1
gunzip -c /home/<account>/scghf/shared/backups/pre-deploy-<release>.sql.gz | mysql -u <DB_USERNAME> -p <DB_DATABASE>
```

Then `php artisan migrate:status` to see what the restored schema thinks is
applied, and fix forward. **Any donation or order that arrived between the
dump and the restore is lost from the database** — Paystack's dashboard
still has the payments; `scghf:reconcile-payments --execute` re-records the
settled ones from the gateway. Do this on a call with somebody else, not
alone at midnight.

## 5. Secrets and variables

GitHub → Settings → Secrets and variables → Actions, per environment
(`staging`, `production`):

| Secret | What |
|---|---|
| `SSH_HOST`, `SSH_PORT`, `SSH_USER` | the cPanel account's SSH |
| `SSH_PRIVATE_KEY` | the deploy key generated in `PHASE-2-RUNBOOK.md` step 5 |
| `SSH_KNOWN_HOSTS` | `ssh-keyscan -p <port> <host>` |
| `DEPLOY_PATH` | `/home/<account>/scghf` (staging: `…/scghf-staging`) |
| `PHP_BIN` | `/opt/cpanel/ea-php84/root/usr/bin/php` |
| `SMOKE_BASIC_AUTH` | staging only, optional: `user:password` from `shared/htpasswd`, so the smoke test checks the homepage through the Basic-auth gate. Without it a 401 there counts as alive and `/up` (left open) proves the boot |

| Variable | What |
|---|---|
| `APP_URL` | the environment's URL, for the smoke test |
| `COVERAGE_MIN` | the coverage floor (`PHASE-14-QA.md` §2.2) |
| `PREFLIGHT_GATE` | `1` on production once live: a failing preflight stops the deploy before the flip |

The application's own configuration — database, Paystack, mail, SMS — is
`shared/.env` on the server and nowhere else. `ENVIRONMENT.md` lists every
key. After editing `.env` on the server: `php artisan config:cache` in
`current/`, or nothing changes.

## 6. Cron

Two lines per environment, in cPanel → Cron Jobs (`deploy/cpanel/cron.txt`
has them with the account paths filled in):

```
* * * * * /opt/cpanel/ea-php84/root/usr/bin/php /home/<account>/scghf/current/artisan schedule:run >> /dev/null 2>&1
* * * * * /usr/bin/flock -n /home/<account>/scghf/shared/queue.lock /opt/cpanel/ea-php84/root/usr/bin/php /home/<account>/scghf/current/artisan queue:work --stop-when-empty --max-time=55 --timeout=50 --tries=3 --memory=128 --sleep=1 --max-jobs=250 >> /dev/null 2>&1
```

They point at `current/`, so they survive every deploy. *Site Health →
Scheduled jobs* and *Background queue* say whether they are running.

## 7. Staging

Deployed from `develop`, its own database and `.env`, `PAYMENT_DRIVER`
either `fake` or `paystack` with **test** keys, `seo.allow_indexing` off
(the setting; `robots.txt` says so), `APP_ENV=staging`, and behind HTTP
Basic auth: `activate.sh` writes the gate into every release from
`shared/htpasswd` (`htpasswd -c <DEPLOY_PATH>/shared/htpasswd <user>`),
leaving `/up`, `/webhooks/*` and `/.well-known/*` open. Not cPanel's
Directory Privacy, which writes into the release's `.htaccess` and is lost
on the next deploy. Load it with
`php artisan db:seed --class=DemoDataSeeder` for testers; the seeder
refuses to run on production.

## 8. Before the first production deploy

`PHASE-2-RUNBOOK.md` step 11, `scghf:preflight` clean, the `PHASE-8`
payments plan §3 (one live cedi), and the Phase 17 launch checklist.
