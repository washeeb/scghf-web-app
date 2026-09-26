#!/usr/bin/env bash
#
#  build-release-locally.sh — assemble a release tree on this machine, the
#  way .github/workflows/deploy.yml does, for a deploy by hand.
#
#  Only for when GitHub Actions cannot run (a billing hold, an outage). The
#  workflow is the deploy; this replays its "Assemble the release tree" step
#  so the same activate.sh can be fed the same shape of release. Run from
#  Git Bash on Windows or any POSIX shell; needs git, composer, and a Vite
#  build already in public/build (npm run build).
#
#      bash deploy/scripts/build-release-locally.sh /tmp/scghf-release
#
#  Then, per docs/DEPLOYMENT.md §3a:
#      REL=$(date -u +%Y%m%d-%H%M%S)-$(git rev-parse --short HEAD)
#      ssh -p 2222 USER@HOST "mkdir -p ~/scghf-staging/releases/$REL"
#      (cd /tmp/scghf-release && tar czf - .) | ssh -p 2222 USER@HOST "tar xzf - -C ~/scghf-staging/releases/$REL"
#      ssh -p 2222 USER@HOST "DEPLOY_PATH=/home/USER/scghf-staging REL=$REL PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php bash -s" < deploy/scripts/activate.sh
#
set -euo pipefail

OUT=${1:?usage: build-release-locally.sh <output-dir>}
COMPOSER=${COMPOSER:-composer}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
cd "$REPO"

test -d public/build || { echo "No public/build — run npm run build first." >&2; exit 1; }

rm -rf "$OUT"; mkdir -p "$OUT"

# The committed tree only — never an untracked or ignored file.
git archive HEAD | tar -x -C "$OUT"

# What the workflow builds: the Vite output (built here already) and the
# no-dev vendor with Filament's published assets. The .env.example copy is
# what lets composer's post-autoload-dump boot the app; removed after.
cp -r public/build "$OUT/public/build"
cd "$OUT"
cp .env.example .env
$COMPOSER install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --quiet
test -f public/css/filament/filament/app.css || { echo "Filament CSS missing — composer's post-autoload-dump did not publish it." >&2; exit 1; }
rm -f .env

# The workflow's exclusions, kept in step with deploy.yml.
rm -rf .git .github node_modules tests docs .editorconfig .gitattributes .gitignore .gitmessage phpunit.xml pint.json
find . -name '*.md' -not -path './resources/manual/*' -delete
find . \( -name '*.png' -o -name '*.jpg' \) -not -path './resources/brand/*' -not -path './vendor/*' -not -path './public/*' -delete
find . -name '*.docx' -delete

echo "Release tree at $OUT: $(du -sh . | cut -f1), $(find . -type f | wc -l) files"
