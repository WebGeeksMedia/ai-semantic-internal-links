#!/usr/bin/env bash
#
# Build the distribution zip: ai-semantic-internal-links.zip, with the plugin in a
# ai-semantic-internal-links/ folder, ready for upload or WordPress.org.
#
# Usage: bash build.sh
#
# Whitelist build: only the files listed below ship. readme.txt and LICENSE are
# included because WordPress.org needs them. vendor/ is not included: the plugin
# uses its own class autoloader and has no runtime Composer dependencies.
#

set -euo pipefail

PLUGIN_SLUG="ai-semantic-internal-links"
BUILD_DIR="build"

cd "$(dirname "$0")"

rm -rf "$BUILD_DIR"
rm -f "$PLUGIN_SLUG.zip"
mkdir -p "$BUILD_DIR/$PLUGIN_SLUG"

cp ai-semantic-internal-links.php uninstall.php readme.txt LICENSE "$BUILD_DIR/$PLUGIN_SLUG/"
cp -R includes assets "$BUILD_DIR/$PLUGIN_SLUG/"

# Strip OS metadata files.
find "$BUILD_DIR" \( -name '.DS_Store' -o -name '._*' -o -name 'Thumbs.db' \) -delete

(
	cd "$BUILD_DIR"
	COPYFILE_DISABLE=1 zip -rqX "../$PLUGIN_SLUG.zip" "$PLUGIN_SLUG"
)

rm -rf "$BUILD_DIR"

echo "Built $PLUGIN_SLUG.zip"
