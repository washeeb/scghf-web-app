#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════════
#  activate.sh — turn an uploaded release into the live one, atomically.
#
#  Piped to the server over SSH by .github/workflows/deploy.yml:
#      ssh … "DEPLOY_PATH=… REL=… PHP_BIN=… bash -s" < deploy/scripts/activate.sh
#
#  Everything before the symlink flip is reversible. The flip itself is a single
#  rename(2) — the site is never serving a half-updated tree.
#
#  Required env: DEPLOY_PATH  REL  PHP_BIN
#  Optional env: SKIP_MIGRATIONS (0|1)  KEEP_RELEASES (default 5)
# ═══════════════════════════════════════════════════════════════════════════════
set -euo pipefail

: "${DEPLOY_PATH:?DEPLOY_PATH is required}"
: "${REL:?REL is required}"
: "${PHP_BIN:?PHP_BIN is required}"
SKIP_MIGRATIONS="${SKIP_MIGRATIONS:-0}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"

RELEASE_DIR="$DEPLOY_PATH/releases/$REL"
SHARED_DIR="$DEPLOY_PATH/shared"
CURRENT_LINK="$DEPLOY_PATH/current"

say()  { printf '\n\033[1;36m▸ %s\033[0m\n' "$*"; }
ok()   { printf '  \033[0;32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[0;33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[1;31m✗ FATAL: %s\033[0m\n' "$*" >&2; exit 1; }

# ── Preconditions ────────────────────────────────────────────────────────────
say "Verifying the uploaded release"
[ -d "$RELEASE_DIR" ]                 || die "Release directory missing: $RELEASE_DIR"
[ -f "$RELEASE_DIR/artisan" ]         || die "artisan missing — the upload is incomplete."
[ -d "$RELEASE_DIR/vendor" ]          || die "vendor/ missing — the CI build did not ship dependencies."
[ -f "$RELEASE_DIR/public/index.php" ]|| die "public/index.php missing."
[ -d "$RELEASE_DIR/public/build" ]    || die "public/build missing — frontend assets were not built."
[ -f "$SHARED_DIR/.env" ]             || die "shared/.env missing. Run the first-run bootstrap (docs/DEPLOYMENT.md)."
command -v "$PHP_BIN" >/dev/null 2>&1 || die "PHP binary not found at: $PHP_BIN"
ok "Release contents look complete"
ok "PHP: $("$PHP_BIN" -r 'echo PHP_VERSION;')"

# ── Shared state ─────────────────────────────────────────────────────────────
# storage/ and .env must survive every deploy, so they live outside the release
# and are linked in. Uploads, logs and secrets are therefore never overwritten.
say "Linking shared state"
rm -rf "$RELEASE_DIR/storage"
ln -sfn "$SHARED_DIR/storage" "$RELEASE_DIR/storage"
ln -sfn "$SHARED_DIR/.env"    "$RELEASE_DIR/.env"
ok "storage → shared/storage"
ok ".env → shared/.env"

mkdir -p "$RELEASE_DIR/bootstrap/cache"
chmod -R 755 "$RELEASE_DIR/bootstrap/cache"

# public/storage → ../storage/app/public, which resolves through to shared storage.
ln -sfn "$RELEASE_DIR/storage/app/public" "$RELEASE_DIR/public/storage"
ok "public/storage → storage/app/public"

# ── Sanity: never deploy a misconfigured environment ─────────────────────────
say "Checking environment sanity"
cd "$RELEASE_DIR"

APP_ENV_VAL=$(grep -E '^APP_ENV=' "$SHARED_DIR/.env" | head -1 | cut -d= -f2- | tr -d '"'"'"' ' || true)
APP_DEBUG_VAL=$(grep -E '^APP_DEBUG=' "$SHARED_DIR/.env" | head -1 | cut -d= -f2- | tr -d '"'"'"' ' || true)
APP_URL_VAL=$(grep -E '^APP_URL=' "$SHARED_DIR/.env" | head -1 | cut -d= -f2- | tr -d '"'"'"' ' | sed 's:/*$::' || true)
ok "APP_ENV=$APP_ENV_VAL"

if [ "$APP_ENV_VAL" = "production" ]; then
  [ "$APP_DEBUG_VAL" = "false" ] || die "APP_DEBUG must be false in production. Refusing to deploy."
  if grep -qE '^PAYSTACK_SECRET_KEY=sk_test_' "$SHARED_DIR/.env"; then
    die "Production .env holds a Paystack TEST key. Refusing to deploy."
  fi
  if grep -qE '^PAYMENT_DRIVER=fake' "$SHARED_DIR/.env"; then
    # Not a warning: PaymentServiceProvider refuses to boot production on the
    # fake driver, so the migrate step below would die with a stack trace.
    # Say it plainly here instead.
    die "PAYMENT_DRIVER=fake in production. The application refuses to boot like this — set PAYMENT_DRIVER=paystack and the live keys in shared/.env."
  fi
  ok "Production guards passed"
fi

# ── Migrations ───────────────────────────────────────────────────────────────
# Run BEFORE the flip. Migrations must be expand-only (add columns/tables, never
# drop or rename in the same deploy) so the currently-live release keeps working
# against the new schema for the few seconds until the symlink moves.
if [ "$SKIP_MIGRATIONS" = "1" ]; then
  say "Skipping migrations (requested)"
else
  # A dump of the database as it is BEFORE this release's migrations touch
  # it. Migrations are expand-only by policy, but a rollback after a
  # migration that turned out to be wrong needs the data from before it, and
  # the nightly backup is up to a day old. Kept in shared/backups, the last
  # five, outside every release directory. Read the credentials the way the
  # application does, from shared/.env.
  say "Dumping the database before migrating"
  mkdir -p "$SHARED_DIR/backups"
  envval() { grep -E "^$1=" "$SHARED_DIR/.env" | head -1 | cut -d= -f2- | tr -d '"'"'"' '; }
  DB_HOST_VAL=$(envval DB_HOST); DB_PORT_VAL=$(envval DB_PORT); DB_NAME_VAL=$(envval DB_DATABASE)
  DB_USER_VAL=$(envval DB_USERNAME); DB_PASS_VAL=$(envval DB_PASSWORD)
  DUMP="$SHARED_DIR/backups/pre-deploy-$REL.sql.gz"
  if command -v mysqldump >/dev/null 2>&1 && [ -n "$DB_NAME_VAL" ]; then
    if MYSQL_PWD="$DB_PASS_VAL" mysqldump --single-transaction --quick --no-tablespaces         -h "${DB_HOST_VAL:-127.0.0.1}" -P "${DB_PORT_VAL:-3306}" -u "$DB_USER_VAL" "$DB_NAME_VAL" 2>/dev/null | gzip -6 > "$DUMP"; then
      chmod 600 "$DUMP"
      ok "pre-deploy dump: $(du -h "$DUMP" | cut -f1) → shared/backups/$(basename "$DUMP")"
      ls -1t "$SHARED_DIR"/backups/pre-deploy-*.sql.gz 2>/dev/null | tail -n +6 | xargs -r rm -f
    else
      rm -f "$DUMP"
      printf '  [0;33m![0m mysqldump failed — continuing without a pre-deploy dump (the nightly backup still exists).
'
    fi
  else
    printf '  [0;33m![0m mysqldump not available — continuing without a pre-deploy dump.
'
  fi

  say "Running migrations"
  "$PHP_BIN" artisan migrate --force --no-interaction || die "Migration failed. Nothing was flipped — the live site is untouched."
  ok "Schema up to date"

  # Permissions and roles are code (RoleAndPermissionSeeder) and the seeder
  # is idempotent: it adds what a release introduced and removes nothing.
  # Without this a new permission exists in a policy and in no role, which
  # is a 403 on a screen the release shipped. Then the encryption sweep,
  # also idempotent, for any column a release moved to an encrypted cast.
  say "Seeding permissions and roles"
  "$PHP_BIN" artisan db:seed --class=RoleAndPermissionSeeder --force --no-interaction || die "Permission seeding failed. Nothing was flipped."
  "$PHP_BIN" artisan scghf:encrypt-at-rest --execute --no-interaction || die "Encryption sweep failed. Nothing was flipped."
  ok "Permissions current, encrypted columns swept"

  # The launch photography: fetched into the library for any slot that has
  # no picture yet (database/seeders/launch-images.json). Idempotent, and a
  # network hiccup must not stop a release — the site renders without the
  # pictures, so warn and carry on.
  say "Launch photography"
  if "$PHP_BIN" artisan scghf:launch-images --no-interaction; then
    ok "launch photography present"
  else
    warn "scghf:launch-images could not fetch everything; run it again by hand."
  fi
fi

# ── Stamp the build ──────────────────────────────────────────────────────────
# APP_RELEASE is the one .env value that changes per deploy, so it is written
# into shared/.env here rather than by hand. Site Health → Environment shows it,
# which is how anybody can tell which build is live without a shell.
if grep -qE '^APP_RELEASE=' "$SHARED_DIR/.env"; then
  sed -i -E "s|^APP_RELEASE=.*|APP_RELEASE=$REL|" "$SHARED_DIR/.env"
else
  printf '\nAPP_RELEASE=%s\n' "$REL" >> "$SHARED_DIR/.env"
fi
ok "APP_RELEASE=$REL"

# ── Warm the caches on the new release, before it goes live ──────────────────
say "Building caches"
"$PHP_BIN" artisan config:cache  --no-interaction && ok "config"
"$PHP_BIN" artisan route:cache   --no-interaction && ok "routes"
"$PHP_BIN" artisan view:cache    --no-interaction && ok "views"
"$PHP_BIN" artisan event:cache   --no-interaction && ok "events"
# Filament ships its own optimiser; both are no-ops until Filament is installed.
"$PHP_BIN" artisan filament:optimize --no-interaction 2>/dev/null && ok "filament" || true
"$PHP_BIN" artisan icons:cache       --no-interaction 2>/dev/null && ok "icons"    || true
"$PHP_BIN" artisan storage:link      --no-interaction 2>/dev/null || true

# ── Preflight ────────────────────────────────────────────────────────────────
# What is still a placeholder, which keys are missing, which flags are on
# with nothing behind them, whether cron and the queue have run. Printed on
# every deploy. It STOPS a production deploy only when PREFLIGHT_GATE=1 is set
# in the environment — the very first deploy cannot pass it (cron points at
# current/, which does not exist until the flip), and staging carries
# placeholders by design. Once production is live, set PREFLIGHT_GATE=1 in
# the GitHub environment so a site with {{PHONE_PRIMARY}} in its footer
# cannot be put live again.
say "Preflight"
if "$PHP_BIN" artisan scghf:preflight --no-interaction; then
  ok "preflight passed"
elif [ "$APP_ENV_VAL" = "production" ] && [ "${PREFLIGHT_GATE:-0}" = "1" ]; then
  die "Preflight failed. Nothing was flipped — fix the settings or the .env keys it named, then deploy again."
else
  printf '  [0;33m![0m Preflight reported problems (above). Not blocking this deploy; read them.
'
fi

# ── robots.txt ───────────────────────────────────────────────────────────────
# Generated at deploy time from APP_ENV rather than committed, so staging can
# never inherit production's file. A static file is also served by Apache before
# PHP boots, so this survives a 500 — which a route-based robots.txt would not.
say "Writing robots.txt for APP_ENV=$APP_ENV_VAL"
if [ "$APP_ENV_VAL" = "production" ]; then
  cat > "$RELEASE_DIR/public/robots.txt" <<ROBOTS
User-agent: *
Allow: /

# Nothing here is useful to a crawler, and some of it is donor-specific.
Disallow: /admin
Disallow: /account
Disallow: /donate/processing
Disallow: /donate/thank-you
Disallow: /donate/failed
Disallow: /donate/callback
Disallow: /shop/cart
Disallow: /shop/checkout
Disallow: /shop/order
Disallow: /webhooks
Disallow: /newsletter/confirm
Disallow: /newsletter/unsubscribe
Disallow: /login
Disallow: /register
Disallow: /password
Disallow: /*?*utm_

Sitemap: ${APP_URL_VAL}/sitemap.xml
ROBOTS
  ok "production robots.txt (indexable)"
else
  cat > "$RELEASE_DIR/public/robots.txt" <<'ROBOTS'
# Staging. Not for indexing, ever.
User-agent: *
Disallow: /
ROBOTS
  # Belt and braces: robots.txt is a request, X-Robots-Tag is an instruction.
  # Directory Privacy on the staging folder is the third layer.
  if ! grep -q 'X-Robots-Tag' "$RELEASE_DIR/public/.htaccess" 2>/dev/null; then
    cat >> "$RELEASE_DIR/public/.htaccess" <<'HT'

# ── Added at deploy time: this is a non-production environment ──────────────
<IfModule mod_headers.c>
    Header always set X-Robots-Tag "noindex, nofollow, noarchive, nosnippet"
</IfModule>
HT
  fi
  ok "staging robots.txt (disallow all) + X-Robots-Tag header"
fi

# ── Staging gate ─────────────────────────────────────────────────────────────
# Staging is on a real domain with documented demo credentials, so it sits
# behind HTTP Basic auth. cPanel's Directory Privacy would write the same
# directives into the docroot's .htaccess — which is this release's
# public/.htaccess and is replaced on every deploy — so the gate is applied
# here, from a password file that lives in shared/ and survives releases:
#
#     htpasswd -c $DEPLOY_PATH/shared/htpasswd <username>
#
# Never on production, whatever is in shared/. Three paths stay open: the
# health check (the deploy's smoke test and Site Health), the payment
# webhooks (Paystack test events must reach staging), .well-known
# (AutoSSL renewals validate over HTTP) and /deploy/ (the OPcache reset
# the deploy itself calls, guarded by its own one-time token).
# Both env names are needed: the rewrite to index.php is an internal
# redirect, and Apache renames variables across it with a REDIRECT_ prefix.
if [ "$APP_ENV_VAL" != "production" ] && [ -f "$SHARED_DIR/htpasswd" ]; then
  # Apache — not PHP — reads this file, as its own user, and only when a
  # browser presents credentials: unreadable means a 500 on every
  # authenticated request while anonymous probes still get a tidy 401. It
  # holds a password hash and nothing else; 644 is what cPanel uses.
  chmod 644 "$SHARED_DIR/htpasswd"
  if ! grep -q 'AuthUserFile' "$RELEASE_DIR/public/.htaccess" 2>/dev/null; then
    cat >> "$RELEASE_DIR/public/.htaccess" <<HT

# ── Added at deploy time: this environment is password-protected ────────────
<IfModule mod_auth_basic.c>
    AuthType Basic
    AuthName "Staging — testers only"
    AuthUserFile $SHARED_DIR/htpasswd
    SetEnvIf Request_URI "^/up\$" scghf_open
    SetEnvIf Request_URI "^/webhooks/" scghf_open
    SetEnvIf Request_URI "^/\.well-known/" scghf_open
    SetEnvIf Request_URI "^/deploy/" scghf_open
    <RequireAny>
        Require env scghf_open
        Require env REDIRECT_scghf_open
        Require valid-user
    </RequireAny>
</IfModule>
HT
  fi
  ok "Basic auth gate from shared/htpasswd (/up, /webhooks, /.well-known open)"
elif [ "$APP_ENV_VAL" != "production" ]; then
  warn "No shared/htpasswd — this non-production environment is OPEN to the internet. Create one: htpasswd -c $SHARED_DIR/htpasswd <username>"
fi

# ── PHP handler ──────────────────────────────────────────────────────────────
# cPanel pins a domain's PHP version by writing an AddHandler block into the
# docroot's .htaccess — and our docroot is a symlink to THIS release's public/,
# whose .htaccess arrives from the repository without it. Without this block
# Apache serves the release with the server default (ea-php83 on InMotion),
# which cannot run the application. PHP-FPM would make the version part of
# the vhost instead, but it is not offered on this plan. Derived from
# PHP_BIN, so a host that is not cPanel gets nothing written.
case "$PHP_BIN" in
  /opt/cpanel/ea-php*/root/usr/bin/php)
    EA_PKG=$(printf '%s' "$PHP_BIN" | cut -d/ -f4)   # /opt/cpanel/ea-php84/... → ea-php84
    if ! grep -q "x-httpd-$EA_PKG" "$RELEASE_DIR/public/.htaccess" 2>/dev/null; then
      cat >> "$RELEASE_DIR/public/.htaccess" <<HT

# php -- BEGIN cPanel-generated handler, do not edit
# Set the "$EA_PKG" package as the default "PHP" programming language.
<IfModule mime_module>
  AddHandler application/x-httpd-$EA_PKG .php .php8 .phtml
</IfModule>
# php -- END cPanel-generated handler, do not edit
HT
    fi
    ok "PHP handler: $EA_PKG"
    ;;
esac

# ── Permissions ──────────────────────────────────────────────────────────────
# cPanel runs PHP as the account user, so 755/644 is sufficient. Never 777 —
# on shared hosting that is world-writable to other tenants' processes.
say "Setting permissions"
# '+' batches the chmod calls — with vendor/ present that is thousands of files,
# and one-exec-per-file would take minutes on shared hosting.
find "$RELEASE_DIR" -type d -exec chmod 755 {} + 2>/dev/null || true
find "$RELEASE_DIR" -type f -exec chmod 644 {} + 2>/dev/null || true
chmod 755 "$RELEASE_DIR/artisan"
chmod -R 755 "$RELEASE_DIR/bootstrap/cache"
[ -d "$RELEASE_DIR/deploy/scripts" ] && chmod 755 "$RELEASE_DIR"/deploy/scripts/*.sh 2>/dev/null || true
chmod 600 "$SHARED_DIR/.env"
ok "dirs 755, files 644, .env 600"

# ── Remember where we were, so rollback.sh knows ─────────────────────────────
if [ -L "$CURRENT_LINK" ]; then
  readlink "$CURRENT_LINK" > "$DEPLOY_PATH/shared/previous_release"
fi

# ══════════════════════════ THE ATOMIC MOMENT ════════════════════════════════
# ln -sfn writes a temp link then rename(2)s it over the old one. rename(2) is
# atomic on POSIX: no request ever sees a missing or partial 'current'.
say "Activating release $REL"
ln -sfn "$RELEASE_DIR" "$CURRENT_LINK"
ok "current → releases/$REL"
# ═════════════════════════════════════════════════════════════════════════════

# ── Post-activation ──────────────────────────────────────────────────────────
say "Post-activation"
"$PHP_BIN" "$CURRENT_LINK/artisan" queue:restart --no-interaction 2>/dev/null && ok "queue workers signalled to restart" || true
# Pages stored by the previous release must not be the first thing this one
# serves. Empties the page cache and starts a new fragment generation.
"$PHP_BIN" "$CURRENT_LINK/artisan" scghf:cache-clear --no-interaction 2>/dev/null && ok "page and fragment caches emptied" || true
# PHP-FPM keeps compiled bytecode across deploys; nothing restarts the pool
# when the symlink moves, so the web workers can go on serving the previous
# release's code while every artisan command here sees the new one. The
# first staging deploy of the launch content served pages without their
# pictures for exactly this reason. Non-fatal: a stale cache clears itself
# as files are revalidated; a rolled-back release would not.
"$PHP_BIN" "$CURRENT_LINK/artisan" scghf:opcache-reset --no-interaction && ok "web workers' OPcache emptied" || warn "OPcache was not reset (see above) — the web workers may serve the previous release's code until it revalidates"
"$PHP_BIN" "$CURRENT_LINK/artisan" up --no-interaction 2>/dev/null || true
echo "$REL" > "$SHARED_DIR/current_release"

# ── Prune ────────────────────────────────────────────────────────────────────
say "Pruning old releases (keeping $KEEP_RELEASES)"
cd "$DEPLOY_PATH/releases"
CURRENT_TARGET=$(basename "$(readlink "$CURRENT_LINK")")
# shellcheck disable=SC2012
ls -1t | tail -n "+$((KEEP_RELEASES + 1))" | while read -r old; do
  [ "$old" = "$CURRENT_TARGET" ] && continue
  rm -rf "${DEPLOY_PATH:?}/releases/${old:?}"
  echo "  removed $old"
done
ok "$(ls -1 "$DEPLOY_PATH/releases" | wc -l) release(s) retained"

printf '\n\033[1;32m═══ Deploy complete: %s ═══\033[0m\n\n' "$REL"
