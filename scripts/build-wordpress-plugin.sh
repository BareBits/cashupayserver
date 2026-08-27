#!/bin/bash
# Build the BareBits WordPress plugin zip.
#
# The plugin is GPL-licensed WordPress glue ONLY: it contains no BareBits
# server code. The server is either connected by URL or downloaded by the
# plugin from GitHub releases at onboarding time, so this build is a plain
# copy of wordpress/ — no composer install, no core files, no vendor/.

set -e

cd "$(dirname "$0")/.."

BUILD_DIR="build/cashupay"
rm -rf build/cashupay build/wordpress_plugin.zip

mkdir -p "$BUILD_DIR"

cp wordpress/*.php wordpress/readme.txt wordpress/license.txt "$BUILD_DIR/"
cp -r wordpress/assets "$BUILD_DIR/assets"

# Channel stamp: a testing-channel plugin build must install a testing-channel
# server (GitHub's /releases/latest only ever answers with stable releases,
# which may predate features the plugin's install flow depends on). The
# release workflow exports CASHUPAY_PLUGIN_CHANNEL=testing for prerelease
# tags; anything else leaves the in-tree 'stable' untouched.
if [ "${CASHUPAY_PLUGIN_CHANNEL:-stable}" = "testing" ]; then
    sed -i.bak "s/const CASHUPAY_PLUGIN_RELEASE_CHANNEL = 'stable';/const CASHUPAY_PLUGIN_RELEASE_CHANNEL = 'testing';/" \
        "$BUILD_DIR/installer.php"
    rm -f "$BUILD_DIR/installer.php.bak"
    grep -q "const CASHUPAY_PLUGIN_RELEASE_CHANNEL = 'testing';" "$BUILD_DIR/installer.php" \
        || { echo "ERROR: failed to stamp the testing release channel into installer.php" >&2; exit 1; }
fi

# Create zip. The archive FILE is named wordpress_plugin.zip (the release asset
# name, later version-stamped by the release workflow), but the top-level
# directory inside it stays `cashupay/` — that is the WordPress plugin slug, and
# `wp plugin install` derives the install folder from it. Renaming the folder
# would change the slug and break activation.
cd build && zip -r wordpress_plugin.zip cashupay/ && cd ..

echo "WordPress plugin built: build/wordpress_plugin.zip"
