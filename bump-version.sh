#!/usr/bin/env bash
#
# Bump the AI Semantic Internal Links version in all locations.
# Usage: bash bump-version.sh 2.2.1
#

set -euo pipefail

NEW_VERSION="${1:-}"

if [[ -z "$NEW_VERSION" ]]; then
  echo "Error: Version argument required." >&2
  echo "Usage: bash bump-version.sh X.Y.Z" >&2
  exit 1
fi

if ! [[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Error: Version must be in X.Y.Z format (got: $NEW_VERSION)." >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ERRORS=0

# --- 1. Plugin header Version: in ai-semantic-internal-links.php ---
FILE="$SCRIPT_DIR/ai-semantic-internal-links.php"
OLD=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\).*/\1/p' "$FILE")
if [[ -z "$OLD" ]]; then
  echo "Error: Could not find 'Version:' in plugin header of $FILE" >&2
  ERRORS=$((ERRORS + 1))
else
  sed -i.bak "s/^\( \* Version: *\)[0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*/\1$NEW_VERSION/" "$FILE"
  echo "ai-semantic-internal-links.php  Version header:   $OLD → $NEW_VERSION"
fi

# --- 2. ILSEJ_VERSION constant in ai-semantic-internal-links.php ---
OLD_CONST=$(sed -n "s/.*define( 'ILSEJ_VERSION', '\([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\)'.*/\1/p" "$FILE")
if [[ -z "$OLD_CONST" ]]; then
  echo "Error: Could not find ILSEJ_VERSION constant in $FILE" >&2
  ERRORS=$((ERRORS + 1))
else
  sed -i.bak "s/define( 'ILSEJ_VERSION', '[0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*'/define( 'ILSEJ_VERSION', '$NEW_VERSION'/" "$FILE"
  echo "ai-semantic-internal-links.php  VERSION constant:  $OLD_CONST → $NEW_VERSION"
fi

# --- 3. Stable tag in readme.txt ---
README="$SCRIPT_DIR/readme.txt"
OLD_TAG=$(sed -n 's/^Stable tag:[[:space:]]*\([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\).*/\1/p' "$README")
if [[ -z "$OLD_TAG" ]]; then
  echo "Error: Could not find 'Stable tag:' in $README" >&2
  ERRORS=$((ERRORS + 1))
else
  sed -i.bak "s/^Stable tag: *[0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*/Stable tag: $NEW_VERSION/" "$README"
  echo "readme.txt               Stable tag:        $OLD_TAG → $NEW_VERSION"
fi

rm -f "$FILE.bak" "$README.bak"

if [[ "$ERRORS" -gt 0 ]]; then
  echo ""
  echo "Error: $ERRORS location(s) could not be updated." >&2
  exit 1
fi

echo ""
echo "Done. All 3 version locations updated to $NEW_VERSION."
