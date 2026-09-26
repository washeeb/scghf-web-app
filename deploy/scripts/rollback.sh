#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════════
#  rollback.sh — point 'current' back at the previous release.
#
#  Runs automatically when the post-deploy smoke test fails, and can be run by
#  hand over SSH at any time:
#
#      ssh -p 2222 n789825@HOST \
#        "DEPLOY_PATH=/home/n789825/scghf PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php bash -s" \
#        < deploy/scripts/rollback.sh
#
#  ⚠️  Code rolls back. DATABASE MIGRATIONS DO NOT. This is why migrations must be
#      expand-only: the previous release has to keep working against the newer
#      schema. If a deploy contained a destructive migration, rolling back the
#      code is not enough — restore the pre-deploy database dump as well.
#
#  Required env: DEPLOY_PATH  PHP_BIN
#  Optional env: TARGET (a specific release name; defaults to the recorded previous)
# ═══════════════════════════════════════════════════════════════════════════════
set -euo pipefail

: "${DEPLOY_PATH:?DEPLOY_PATH is required}"
: "${PHP_BIN:?PHP_BIN is required}"

CURRENT_LINK="$DEPLOY_PATH/current"
SHARED_DIR="$DEPLOY_PATH/shared"
RELEASES_DIR="$DEPLOY_PATH/releases"

say() { printf '\n\033[1;36m▸ %s\033[0m\n' "$*"; }
ok()  { printf '  \033[0;32m✓\033[0m %s\n' "$*"; }
die() { printf '\n\033[1;31m✗ FATAL: %s\033[0m\n' "$*" >&2; exit 1; }

say "Current state"
if [ -L "$CURRENT_LINK" ]; then
  NOW=$(basename "$(readlink "$CURRENT_LINK")")
  echo "  live: $NOW"
else
  NOW=""
  echo "  live: (no current symlink)"
fi

# ── Work out where to roll back to ───────────────────────────────────────────
if [ -n "${TARGET:-}" ]; then
  ROLLBACK_TO="$TARGET"
  echo "  target: $ROLLBACK_TO (explicit)"
elif [ -f "$SHARED_DIR/previous_release" ]; then
  ROLLBACK_TO=$(basename "$(cat "$SHARED_DIR/previous_release")")
  echo "  target: $ROLLBACK_TO (recorded previous)"
else
  # No recorded previous release means nothing was ever live before the
  # release that just failed. Guessing "the newest other directory" is how
  # the first staging deploy rolled back onto a release whose migrations
  # had FAILED — a directory is not a record of having been live. Refuse;
  # the failed release stays current, and a person decides.
  die "No previous release is recorded (shared/previous_release). This was the first activation, so there is nothing known-good to go back to. Fix forward, or name a release explicitly."
fi

[ -n "$ROLLBACK_TO" ] || die "No release to roll back to. Deploy a known-good commit instead."
[ -d "$RELEASES_DIR/$ROLLBACK_TO" ] || die "Release not found: $RELEASES_DIR/$ROLLBACK_TO"
[ -f "$RELEASES_DIR/$ROLLBACK_TO/artisan" ] || die "Target release looks incomplete (no artisan)."
if [ "$ROLLBACK_TO" = "$NOW" ]; then
  die "Target is already live. Nothing to do."
fi

# ── Flip back ────────────────────────────────────────────────────────────────
say "Rolling back to $ROLLBACK_TO"
ln -sfn "$RELEASES_DIR/$ROLLBACK_TO" "$CURRENT_LINK"
ok "current → releases/$ROLLBACK_TO"

# The previous release's caches were built against its own config. Rebuild them
# so nothing stale from the failed release leaks through.
say "Rebuilding caches"
cd "$CURRENT_LINK"
"$PHP_BIN" artisan config:cache --no-interaction && ok "config"
"$PHP_BIN" artisan route:cache  --no-interaction && ok "routes"
"$PHP_BIN" artisan view:cache   --no-interaction && ok "views"
"$PHP_BIN" artisan event:cache  --no-interaction && ok "events"
"$PHP_BIN" artisan queue:restart --no-interaction 2>/dev/null && ok "queue restarted" || true
"$PHP_BIN" artisan up --no-interaction 2>/dev/null || true

echo "$ROLLBACK_TO" > "$SHARED_DIR/current_release"
if [ -n "$NOW" ]; then
  echo "$RELEASES_DIR/$NOW" > "$SHARED_DIR/previous_release"
fi

printf '\n\033[1;33m═══ Rolled back to %s ═══\033[0m\n' "$ROLLBACK_TO"
printf '\033[1;33m    Check whether the failed deploy ran migrations. If it did, and any\n'
printf '    were destructive, restore the pre-deploy database dump now.\033[0m\n\n'
