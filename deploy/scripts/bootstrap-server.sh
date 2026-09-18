#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════════
#  bootstrap-server.sh — run ONCE per environment, by hand, before the first deploy.
#
#  Creates the directory skeleton, the shared state, and the docroot symlink.
#  It does NOT deploy code — GitHub Actions does that.
#
#  Run it in cPanel → Terminal, or over SSH:
#      bash bootstrap-server.sh production
#      bash bootstrap-server.sh staging
#
#  Safe to re-run: every step is idempotent and nothing existing is destroyed.
# ═══════════════════════════════════════════════════════════════════════════════
set -euo pipefail

ENVIRONMENT="${1:-}"
if [ "$ENVIRONMENT" != "production" ] && [ "$ENVIRONMENT" != "staging" ]; then
  echo "Usage: bash bootstrap-server.sh [production|staging]" >&2
  exit 1
fi

HOME_DIR="${HOME:-/home/presti98}"

if [ "$ENVIRONMENT" = "production" ]; then
  DEPLOY_PATH="$HOME_DIR/scghf"
  DOCROOT="$HOME_DIR/greaterhopefoundations.com"
else
  DEPLOY_PATH="$HOME_DIR/scghf-staging"
  DOCROOT="$HOME_DIR/staging.greaterhopefoundations.com"
fi

say() { printf '\n\033[1;36m▸ %s\033[0m\n' "$*"; }
ok()  { printf '  \033[0;32m✓\033[0m %s\n' "$*"; }
warn(){ printf '  \033[0;33m!\033[0m %s\n' "$*"; }
die() { printf '\n\033[1;31m✗ FATAL: %s\033[0m\n' "$*" >&2; exit 1; }

printf '\n\033[1m═══ Bootstrapping %s ═══\033[0m\n' "$ENVIRONMENT"
echo "  home:    $HOME_DIR"
echo "  deploy:  $DEPLOY_PATH"
echo "  docroot: $DOCROOT"

# ── 1. Directory skeleton ────────────────────────────────────────────────────
say "Creating the directory skeleton"
mkdir -p "$DEPLOY_PATH"/{releases,shared,backups}
mkdir -p "$DEPLOY_PATH"/shared/storage/{app,framework,logs}
mkdir -p "$DEPLOY_PATH"/shared/storage/app/public
mkdir -p "$DEPLOY_PATH"/shared/storage/framework/{cache,sessions,views,testing}
mkdir -p "$DEPLOY_PATH"/shared/storage/framework/cache/data
ok "$DEPLOY_PATH/{releases,shared,backups}"
ok "shared/storage/ tree created"

chmod -R 755 "$DEPLOY_PATH/shared/storage"

# ── 2. The PHP binary ────────────────────────────────────────────────────────
say "Locating the PHP binary"
PHP_BIN=""
# ea-php84 first: the project targets PHP 8.4 (Laravel 13 + Filament 5 + Pest 5
# all require it). ea-php83 is kept only as a last-resort fallback so this script
# still reports something useful on a server where 8.4 is absent.
for candidate in /opt/cpanel/ea-php84/root/usr/bin/php \
                 /opt/cpanel/ea-php83/root/usr/bin/php \
                 "$(command -v php 2>/dev/null || true)"; do
  if [ -n "$candidate" ] && [ -x "$candidate" ]; then PHP_BIN="$candidate"; break; fi
done
[ -n "$PHP_BIN" ] || die "No PHP binary found. Check MultiPHP Manager."
ok "$PHP_BIN"
ok "version $("$PHP_BIN" -r 'echo PHP_VERSION;')"

MISSING=""
for ext in bcmath ctype curl dom exif fileinfo gd iconv intl mbstring openssl pdo_mysql sodium tokenizer xml zip; do
  "$PHP_BIN" -m | grep -qi "^${ext}$" || MISSING="$MISSING $ext"
done
if [ -n "$MISSING" ]; then
  warn "MISSING PHP extensions:$MISSING"
  warn "Open a ticket with InMotion — there is no PHP Selector on this account."
else
  ok "all required extensions present"
fi

# ── 3. shared/.env ───────────────────────────────────────────────────────────
say "Preparing shared/.env"
if [ -f "$DEPLOY_PATH/shared/.env" ]; then
  ok ".env already exists — left untouched"
else
  cat > "$DEPLOY_PATH/shared/.env" <<'ENVEOF'
# Populate from .env.example in the repository, then delete this banner.
# This file is the ONLY place real secrets live. It is never in Git.
APP_NAME="St. Cecilia's Greater Hope Foundations"
APP_ENV=REPLACE_ME
APP_KEY=
APP_DEBUG=false
APP_URL=https://REPLACE_ME
APP_TIMEZONE=Africa/Accra
ENVEOF
  warn "A STUB .env was created. Fill it from .env.example before deploying."
fi
chmod 600 "$DEPLOY_PATH/shared/.env"
ok "chmod 600 (owner-only)"

# ── 4. Protect the application root from the web ─────────────────────────────
# Belt and braces: $DEPLOY_PATH is already outside the docroot, but if anyone
# ever repoints a domain at it by mistake, this denies everything.
say "Hardening the application root"
cat > "$DEPLOY_PATH/.htaccess" <<'HTEOF'
# This directory must never be web-accessible. It sits outside the document
# root by design; this file is the second line of defence.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HTEOF
ok "deny-all .htaccess written to $DEPLOY_PATH"

# ── 5. The document root symlink ─────────────────────────────────────────────
say "Wiring the document root"
if [ -L "$DOCROOT" ]; then
  ok "already a symlink → $(readlink "$DOCROOT")"
elif [ -d "$DOCROOT" ]; then
  if [ -n "$(ls -A "$DOCROOT" 2>/dev/null)" ]; then
    BACKUP="$DOCROOT.bak.$(date +%Y%m%d%H%M%S)"
    mv "$DOCROOT" "$BACKUP"
    warn "Existing docroot had content — moved to:"
    warn "  $BACKUP"
    warn "Keep it until the site has served real traffic for a week."
  else
    rmdir "$DOCROOT"
    ok "removed empty docroot directory"
  fi
  ln -sfn "$DEPLOY_PATH/current/public" "$DOCROOT"
  ok "$DOCROOT → $DEPLOY_PATH/current/public"
else
  ln -sfn "$DEPLOY_PATH/current/public" "$DOCROOT"
  ok "$DOCROOT → $DEPLOY_PATH/current/public"
fi

warn "The symlink dangles until the first deploy creates 'current'. That is expected."

# ── 6. Report what CI needs ──────────────────────────────────────────────────
say "GitHub secrets for the '$ENVIRONMENT' environment"
cat <<REPORT

  SSH_USER      $(whoami)
  SSH_HOST      (shared IP, or the hostname from cPanel → Server Information)
  SSH_PORT      2222
  DEPLOY_PATH   $DEPLOY_PATH
  PHP_BIN       $PHP_BIN

  Repository variable:
  APP_URL       https://$(basename "$DOCROOT")

  Generate SSH_KNOWN_HOSTS on your own machine with:
      ssh-keyscan -p 2222 <SSH_HOST>

REPORT

say "Cron entries to add in cPanel → Cron Jobs"
cat <<CRON

  * * * * * $PHP_BIN $DEPLOY_PATH/current/artisan schedule:run >> /dev/null 2>&1

  * * * * * /usr/bin/flock -n $DEPLOY_PATH/shared/queue.lock $PHP_BIN $DEPLOY_PATH/current/artisan queue:work --stop-when-empty --max-time=55 --timeout=50 --tries=3 --memory=128 --sleep=1 --max-jobs=250 >> /dev/null 2>&1

CRON

printf '\033[1;32m═══ %s bootstrap complete ═══\033[0m\n\n' "$ENVIRONMENT"
echo "Next: fill in $DEPLOY_PATH/shared/.env, then push to trigger the first deploy."
echo
