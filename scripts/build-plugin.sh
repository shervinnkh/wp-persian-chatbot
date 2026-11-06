#!/usr/bin/env bash
# Builds the installable WordPress plugin package.
#
#   bash scripts/build-plugin.sh [OWNER/REPO]
#
# Output (in ./dist):
#   persian-wp-chatbot.zip         (root folder inside the zip is persian-wp-chatbot/)
#   persian-wp-chatbot.zip.sha256
#
# These two exact file names are what the in-plugin updater looks for in a GitHub Release.
# OWNER/REPO defaults to $GITHUB_REPOSITORY (set automatically inside GitHub Actions). It is written
# into the *built copy* of the plugin (updater constant + "Update URI" header); the source stays untouched.
set -euo pipefail
cd "$(dirname "$0")/.."

SLUG="persian-wp-chatbot"
SRC="wp-plugin"
REPO="${1:-${GITHUB_REPOSITORY:-}}"

# --- versions must agree -------------------------------------------------------------------------
HDR_VER="$(sed -n 's/^ \* Version: *\([0-9][^ ]*\).*/\1/p' "$SRC/$SLUG.php" | head -1)"
CONST_VER="$(sed -n "s/^define( 'PWC_VERSION', '\([^']*\)' );.*/\1/p" "$SRC/$SLUG.php" | head -1)"
if [ -z "$HDR_VER" ] || [ "$HDR_VER" != "$CONST_VER" ]; then
  echo "ERROR: plugin header Version ('$HDR_VER') and PWC_VERSION ('$CONST_VER') differ." >&2
  exit 1
fi
if [ -n "${RELEASE_TAG:-}" ] && [ "$RELEASE_TAG" != "plugin-v$HDR_VER" ]; then
  echo "ERROR: tag '$RELEASE_TAG' does not match plugin version $HDR_VER (expected plugin-v$HDR_VER)." >&2
  exit 1
fi

# --- stage a clean copy ---------------------------------------------------------------------------
rm -rf build dist
mkdir -p "build/$SLUG" dist
cp -R "$SRC/." "build/$SLUG/"
# docs / dev files are not shipped to WordPress sites
find "build/$SLUG" -type f \( -name '*.md' -o -name 'openapi.yaml' -o -name '.DS_Store' -o -name '*.bak' \) -delete

# --- point the updater at the real repository (only the two intended places) ------------------------
if [ -n "$REPO" ]; then
  case "$REPO" in */*) ;; *) echo "ERROR: repo must look like OWNER/REPO" >&2; exit 1;; esac
  sed -i "s#^\(\s*public const GITHUB_REPO = \)'OWNER/REPO';#\1'$REPO';#" "build/$SLUG/includes/Updates/Updater.php"
  sed -i "s#^\( \* Update URI:\s*\)https://github.com/OWNER/REPO#\1https://github.com/$REPO#" "build/$SLUG/$SLUG.php"
  grep -q "GITHUB_REPO = '$REPO';" "build/$SLUG/includes/Updates/Updater.php" \
    || { echo "ERROR: could not set GITHUB_REPO in Updater.php" >&2; exit 1; }
else
  echo "WARNING: no OWNER/REPO given - updater stays disabled in this build." >&2
fi

# --- zip + checksum -------------------------------------------------------------------------------
( cd build && zip -r -X -q "../dist/$SLUG.zip" "$SLUG" )
( cd dist && sha256sum "$SLUG.zip" > "$SLUG.zip.sha256" )
rm -rf build

echo "Built version $HDR_VER:"
ls -l dist
cat "dist/$SLUG.zip.sha256"
