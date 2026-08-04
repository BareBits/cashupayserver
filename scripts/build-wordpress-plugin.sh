#!/bin/bash
# Build CashuPay WordPress plugin zip

set -e

cd "$(dirname "$0")/.."

BUILD_DIR="build/cashupay"
rm -rf build/cashupay build/wordpress_plugin.zip

mkdir -p "$BUILD_DIR"

# Copy WordPress-specific files FLAT into the plugin root.
#
# This layout is not cosmetic: every one of these files requires its siblings
# as `__DIR__ . '/sibling.php'` (cashupay.php -> bootstrap.php, activation.php,
# rewrite-rules.php, admin-menu.php; bootstrap.php -> includes/urls.php), and
# setup.php requires btcpay-integration.php the same way. Keeping them in a
# wordpress/ subdirectory broke every one of those paths, so the zip this
# script produced could not even activate. docker/Dockerfile.wordpress and the
# test fixture both flatten; this now matches them.
# scripts/verify-plugin-build.php enforces it.
cp wordpress/*.php "$BUILD_DIR/"

# Install Composer dependencies (bitwasp/bitcoin etc.) before bundling.
if [ ! -f composer.phar ]; then
    PHP_BIN="${PHP_BIN:-php}"
    curl -sS https://getcomposer.org/installer | "$PHP_BIN" -- --quiet --install-dir=. --filename=composer.phar
fi
"${PHP_BIN:-php}" composer.phar install --no-progress --no-dev --optimize-autoloader --ignore-platform-reqs

# Copy shared core
cp -r includes/ "$BUILD_DIR/includes/"
cp -r vendor/ "$BUILD_DIR/vendor/"
# These are the top-level entry points wordpress/rewrite-rules.php dispatches
# to via CASHUPAY_PLUGIN_DIR. pay.php (self-serve /cashupay/pay/{store}) was
# missing, so that route fataled on the installed plugin.
cp admin.php setup.php api.php payment.php receive.php cron.php pay.php "$BUILD_DIR/"
cp -r api-keys/ "$BUILD_DIR/api-keys/"

# Copy assets
cp -r assets/ "$BUILD_DIR/assets/"

# Copy favicon and images
cp favicon.ico "$BUILD_DIR/"
cp -r images/ "$BUILD_DIR/images/"

# Copy cashu-wallet-php (excluding .git, tests, examples)
mkdir -p "$BUILD_DIR/cashu-wallet-php"
cp cashu-wallet-php/CashuWallet.php "$BUILD_DIR/cashu-wallet-php/"
cp cashu-wallet-php/bip39-english.txt "$BUILD_DIR/cashu-wallet-php/"

# Build and copy mint-discovery bundle
if [ -d "mint-discovery" ]; then
    cd mint-discovery && npm install --silent && npm run build --silent && cd ..
    mkdir -p "$BUILD_DIR/mint-discovery/dist"
    cp mint-discovery/dist/mint-discovery.bundle.js "$BUILD_DIR/mint-discovery/dist/"
    # Also copy the bundle to assets
    cp mint-discovery/dist/mint-discovery.bundle.js "$BUILD_DIR/assets/js/"
fi

# Create zip. The archive FILE is named wordpress_plugin.zip (the release asset
# name, later version-stamped by the release workflow), but the top-level
# directory inside it stays `cashupay/` — that is the WordPress plugin slug, and
# `wp plugin install` derives the install folder from it. Renaming the folder
# would change the slug and break activation.
cd build && zip -r wordpress_plugin.zip cashupay/ && cd ..

echo "WordPress plugin built: build/wordpress_plugin.zip"
