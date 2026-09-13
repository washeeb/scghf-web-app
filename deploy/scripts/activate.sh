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
ok "APP_ENV=$APP_ENV_VAL"

if [ "$APP_ENV_VAL" = "production" ]; then
  [ "$APP_DEBUG_VAL" = "false" ] || die "APP_DEBUG must be false in production. Refusing to deploy."
  if grep -qE '^PAYSTACK_SECRET_KEY=sk_test_' "$SHARED_DIR/.env"; then
    die "Production .env holds a Paystack TEST key. Refusing to deploy."
  fi
  if grep -qE '^PAYMENT_DRIVER=fake' "$SHARED_DIR/.env"; then
    printf '  \033[0;33m!\033[0m PAYMENT_DRIVER=fake in production — donations will NOT reach Paystack.\n'
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
  say "Running migrations"
  "$PHP_BIN" artisan migrate --force --no-interaction || die "Migration failed. Nothing was flipped — the live site is untouched."
  ok "Schema up to date"
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

# ── robots.txt ───────────────────────────────────────────────────────────────
# Generated at deploy time from APP_ENV rather than committed, so staging can
# never inherit production's file. A static file is also served by Apache before
# PHP boots, so this survives a 500 — which a route-based robots.txt would not.
say "Writing robots.txt for APP_ENV=$APP_ENV_VAL"
if [ "$APP_ENV_VAL" = "production" ]; then
  cat > "$RELEASE_DIR/public/robots.txt" <<'ROBOTS'
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

Sitemap: https://greaterhopefoundations.com/sitemap.xml
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
