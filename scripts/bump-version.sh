#!/usr/bin/env bash
# Sets the plugin version in both places WordPress code needs it, then tells you what to do next.
#
#   bash scripts/bump-version.sh 1.0.1
#   bash scripts/bump-version.sh 1.1.0-beta.1     (pre-release: published as GitHub "pre-release", sites are NOT offered it)
set -euo pipefail
cd "$(dirname "$0")/.."

NEW="${1:-}"
FILE="wp-plugin/persian-wp-chatbot.php"

if ! [[ "$NEW" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]]; then
  echo "Usage: bash scripts/bump-version.sh MAJOR.MINOR.PATCH[-suffix]   (e.g. 1.0.1)" >&2
  exit 1
fi

OLD="$(sed -n "s/^define( 'PWC_VERSION', '\([^']*\)' );.*/\1/p" "$FILE" | head -1)"
HDR="$(sed -n 's/^ \* Version: *\([0-9][^ ]*\).*/\1/p' "$FILE" | head -1)"
[ "$OLD" = "$HDR" ] || { echo "ERROR: header ($HDR) and PWC_VERSION ($OLD) already differ - fix by hand first." >&2; exit 1; }
[ "$NEW" != "$OLD" ] || { echo "ERROR: version is already $OLD." >&2; exit 1; }
# new must be greater (sort -V puts the larger last; a pre-release like 1.1.0-beta.1 sorts after 1.1.0 here, which is fine for a sanity check)
[ "$(printf '%s\n%s\n' "$OLD" "$NEW" | sort -V | tail -1)" = "$NEW" ] || { echo "ERROR: $NEW is not newer than $OLD." >&2; exit 1; }

sed -i "s/^\( \* Version:\s*\)$OLD\$/\1$NEW/" "$FILE"
sed -i "s/^\(define( 'PWC_VERSION', '\)$OLD\(' );\)/\1$NEW\2/" "$FILE"

echo "Plugin version: $OLD -> $NEW"
grep -nE "^ \* Version:|PWC_VERSION'" "$FILE"
cat <<MSG

Next:
  1. Add a "## [$NEW]" section to CHANGELOG.md
  2. git add -A && git commit -m "Release plugin $NEW"
  3. git push
  4. git tag plugin-v$NEW && git push origin plugin-v$NEW
MSG
