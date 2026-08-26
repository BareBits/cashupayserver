#!/bin/bash
# Build the Windows desktop distribution zip.
#
# Bundles the standalone app (build/cashupayserver, built by
# build-standalone.sh) with an official windows.php.net PHP runtime and the
# windows/ launcher assets into a portable, double-clickable package:
#
#   CashuPayServer/
#     CashuPayServer.bat     launcher (server + browser + background tasks)
#     desktop-helper.php     browser opener + cron ticker (see windows/)
#     cron-runner.php        CLI cron invocation
#     README.txt             merchant instructions
#     vc_redist.x64.exe      MSVC runtime php.exe needs (installed on demand)
#     php/                   PHP runtime + php.ini.template + cacert.pem
#     app/                   the standalone app (data lives in app/data)
#
# The official windows.php.net build is used instead of a static-php-cli
# single binary because static-php-cli cannot build GMP on Windows (marked
# "wip" in its support matrix) and GMP is required for the on-chain xpub,
# submarine-swap and sweep features. bcmath/ctype/json are compiled into the
# official Windows builds; everything else needed ships as an ext/ DLL.

set -euo pipefail

cd "$(dirname "$0")/.."

# Pinned runtime. When bumping: update BOTH values from
# https://downloads.php.net/~windows/releases/releases.json (sha256 is listed
# per artifact). Old patch releases move from releases/ to releases/archives/
# when a newer one ships, hence the two-URL fallback below.
PHP_WIN_VERSION="${PHP_WIN_VERSION:-8.3.33}"
PHP_WIN_SHA256="${PHP_WIN_SHA256:-534399107056313246f424adbbb7937337e40fbbf6aa7bc26287ba9cfd2e4a2a}"
PHP_WIN_ZIP="php-${PHP_WIN_VERSION}-nts-Win32-vs16-x64.zip"
PHP_WIN_URLS=(
    "https://downloads.php.net/~windows/releases/${PHP_WIN_ZIP}"
    "https://downloads.php.net/~windows/releases/archives/${PHP_WIN_ZIP}"
)

STAGE="build/CashuPayServer"
OUT="build/cashupayserver-windows.zip"
CACHE="build/cache"

# The app payload is the standalone build; make it if it isn't there yet.
if [ ! -d build/cashupayserver ]; then
    ./scripts/build-standalone.sh
fi

rm -rf "$STAGE" "$OUT"
mkdir -p "$STAGE/php" "$CACHE"

# --- 1. Official Windows PHP runtime (pinned + checksummed) -----------------
if [ ! -f "$CACHE/$PHP_WIN_ZIP" ]; then
    downloaded=0
    for url in "${PHP_WIN_URLS[@]}"; do
        echo "Fetching $url"
        if curl -fsSL "$url" -o "$CACHE/$PHP_WIN_ZIP.tmp"; then
            mv "$CACHE/$PHP_WIN_ZIP.tmp" "$CACHE/$PHP_WIN_ZIP"
            downloaded=1
            break
        fi
    done
    if [ "$downloaded" != 1 ]; then
        echo "ERROR: could not download $PHP_WIN_ZIP" >&2
        exit 1
    fi
fi
echo "${PHP_WIN_SHA256}  ${CACHE}/${PHP_WIN_ZIP}" | sha256sum -c -

unzip -q "$CACHE/$PHP_WIN_ZIP" -d "$STAGE/php"

# Fail loudly if this PHP build doesn't carry an extension the app needs —
# php.ini.template's extension list and this check must stay in sync.
for dll in php_curl php_fileinfo php_gmp php_mbstring php_openssl \
           php_pdo_sqlite php_sqlite3 php_zip; do
    if [ ! -f "$STAGE/php/ext/${dll}.dll" ]; then
        echo "ERROR: ${dll}.dll missing from the PHP runtime zip" >&2
        exit 1
    fi
done

# --- 2. CA bundle for outbound TLS ------------------------------------------
# Official Windows PHP has no CA store configured; the app verifies TLS peers
# (mints, LNURL, relays), so ship Mozilla's bundle and point php.ini at it.
# The bundle is a rolling release — verify integrity against curl.se's
# published digest rather than a pin.
curl -fsSL https://curl.se/ca/cacert.pem -o "$CACHE/cacert.pem"
curl -fsSL https://curl.se/ca/cacert.pem.sha256 -o "$CACHE/cacert.pem.sha256"
(cd "$CACHE" && sha256sum -c cacert.pem.sha256)
cp "$CACHE/cacert.pem" "$STAGE/php/cacert.pem"

# --- 3. MSVC runtime installer ----------------------------------------------
# php.exe (VS16 build) needs vcruntime140.dll, which bare Windows installs may
# lack. The launcher runs this installer only when PHP fails to start.
# Microsoft's aka.ms link is a rolling "latest" with no stable digest to pin;
# it is fetched over TLS from Microsoft directly.
if [ ! -f "$CACHE/vc_redist.x64.exe" ]; then
    curl -fsSL https://aka.ms/vs/17/release/vc_redist.x64.exe \
        -o "$CACHE/vc_redist.x64.exe"
fi
cp "$CACHE/vc_redist.x64.exe" "$STAGE/vc_redist.x64.exe"

# --- 4. App + launcher assets -----------------------------------------------
cp -r build/cashupayserver "$STAGE/app"
cp windows/desktop-helper.php windows/cron-runner.php "$STAGE/"
cp windows/php.ini.template windows/render-ini.php "$STAGE/php/"

# Batch files and merchant-facing text must be CRLF (cmd.exe mis-parses
# LF-only labels; notepad on older Windows shows LF files as one line).
# Normalize first so this stays correct however git checked the files out.
for pair in "windows/CashuPayServer.bat:CashuPayServer.bat" \
            "windows/README-windows.txt:README.txt"; do
    src="${pair%%:*}"; dst="${pair##*:}"
    sed -e 's/\r$//' -e 's/$/\r/' "$src" > "$STAGE/$dst"
done

# Verify the normalization actually held: an LF-only line in a .bat makes
# cmd.exe mis-parse labels silently, so fail the build rather than ship it.
for f in "$STAGE/CashuPayServer.bat" "$STAGE/README.txt"; do
    if ! awk '!/\r$/ { exit 1 }' "$f"; then
        echo "ERROR: $f contains a line without a CRLF ending" >&2
        exit 1
    fi
done

# --- 5. Zip -----------------------------------------------------------------
(cd build && zip -q -r cashupayserver-windows.zip CashuPayServer/)

echo "Windows desktop build: $OUT"
