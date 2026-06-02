#!/bin/bash
# Build standalone CashuPayServer distribution zip

set -e

cd "$(dirname "$0")/.."

BUILD_DIR="build/cashupayserver"
rm -rf build/cashupayserver build/cashupayserver.zip

mkdir -p "$BUILD_DIR"

# Install Composer dependencies for the on-chain Bitcoin payment support
# (bitwasp/bitcoin and friends). The lockfile pins exact versions; we use
# --ignore-platform-reqs because a transitive dep (lastguest/murmurhash) has
# a stale PHP 7 pin even though it runs fine on PHP 8.
if [ ! -f composer.phar ]; then
    PHP_BIN="${PHP_BIN:-php}"
    curl -sS https://getcomposer.org/installer | "$PHP_BIN" -- --quiet --install-dir=. --filename=composer.phar
fi
"${PHP_BIN:-php}" composer.phar install --no-progress --no-dev --optimize-autoloader --ignore-platform-reqs

# Build mint-discovery bundle first
if [ -d "mint-discovery" ]; then
    cd mint-discovery && npm install --silent && npm run build --silent && cd ..
    cp mint-discovery/dist/mint-discovery.bundle.js assets/js/
fi

# Copy core files
cp -r includes/ "$BUILD_DIR/includes/"
cp -r vendor/ "$BUILD_DIR/vendor/"
cp -r assets/ "$BUILD_DIR/assets/"
cp -r api-keys/ "$BUILD_DIR/api-keys/"
cp admin.php setup.php api.php payment.php receive.php cron.php router.php index.php recover.php "$BUILD_DIR/"
cp .htaccess manifest.json favicon.ico "$BUILD_DIR/"
cp -r images/ "$BUILD_DIR/images/"

# Copy cashu-wallet-php (clean, no .git)
mkdir -p "$BUILD_DIR/cashu-wallet-php"
cp cashu-wallet-php/CashuWallet.php "$BUILD_DIR/cashu-wallet-php/"
cp cashu-wallet-php/bip39-english.txt "$BUILD_DIR/cashu-wallet-php/"

# Create data directory with protection
mkdir -p "$BUILD_DIR/data"
echo 'deny from all' > "$BUILD_DIR/data/.htaccess"
echo '<!DOCTYPE html><html><body></body></html>' > "$BUILD_DIR/data/index.html"

# Write BUILD_INFO so the auto-updater can identify what's installed and
# decide whether the live .htaccess is still pristine enough to overwrite.
# CI sets COMMIT_SHA and CHANNEL via env; local builds fall back to git.
COMMIT_SHA="${COMMIT_SHA:-$(git rev-parse HEAD 2>/dev/null || echo unknown)}"
CHANNEL="${CHANNEL:-}"
BUILT_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
VERSION="$(grep -E "define\('CASHUPAY_VERSION'" includes/config.php | sed -E "s/.*'([^']+)'\);.*/\1/" | head -1)"
HTACCESS_SHA256="$(sha256sum "$BUILD_DIR/.htaccess" | awk '{print $1}')"
{
  echo "COMMIT_SHA=$COMMIT_SHA"
  echo "CHANNEL=$CHANNEL"
  echo "BUILT_AT=$BUILT_AT"
  echo "VERSION=$VERSION"
  echo "HTACCESS_SHA256=$HTACCESS_SHA256"
} > "$BUILD_DIR/BUILD_INFO"

# Create zip
cd build && zip -r cashupayserver.zip cashupayserver/ && cd ..

echo "Standalone build: build/cashupayserver.zip"
