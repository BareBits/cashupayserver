#!/bin/bash
# Deploy the wordpress.org plugin variant to the wp.org plugin directory SVN.
#
# Called by the release workflow (wporg-svn job) for STABLE tags only: the
# wordpress.org directory has no prerelease concept, so testing-channel tags
# must never land there. The tag gate below enforces that independently of
# the workflow's own stable/prerelease classifier.
#
# What one deploy does, in SVN terms:
#   - trunk/   <- the exact contents of the built wporg plugin zip (the same
#                 bytes published on the GitHub release; nothing is rebuilt)
#   - assets/  <- directory-page art: wordpress/wporg-assets/* plus
#                 docs/screenshots/* renamed to the screenshot-N.png names the
#                 readme's == Screenshots == section captions
#   - tags/<version> <- a server-side copy of trunk (what wp.org serves once
#                 the readme's Stable tag points at it)
#
# Re-running for an already-deployed version is a no-op: if tags/<version>
# exists in SVN the script exits 0 without touching anything, so a re-run of
# a failed release workflow can't half-overwrite a shipped plugin version.
#
# Usage: scripts/deploy-wporg-svn.sh <wporg-plugin-zip> <tag>
#   <tag> is the release tag, e.g. v1.5.1 (plain stable form only).
#
# Environment:
#   WPORG_SVN_PASSWORD   required — the wp.org SVN password
#   WPORG_SVN_USERNAME   default: barebits
#   WPORG_SVN_URL        default: https://plugins.svn.wordpress.org/<slug>;
#                        tests point this at a local file:// repository

set -euo pipefail

cd "$(dirname "$0")/.."

ZIP="${1:-}"
TAG="${2:-}"
if [ -z "$ZIP" ] || [ -z "$TAG" ]; then
    echo "usage: scripts/deploy-wporg-svn.sh <wporg-plugin-zip> <tag>" >&2
    exit 2
fi
if [ ! -f "$ZIP" ]; then
    echo "ERROR: plugin zip not found: $ZIP" >&2
    exit 1
fi

# Stable tags only (vX.Y or vX.Y.Z, no suffix). Anything else — testing.N,
# rc, alpha — must never reach the wordpress.org directory.
if [[ ! "$TAG" =~ ^v[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
    echo "ERROR: '$TAG' is not a stable release tag; refusing to deploy to wordpress.org" >&2
    exit 1
fi
VERSION="${TAG#v}"

WPORG_SLUG="barebits-lightning-payments-via-bitcoin"
: "${WPORG_SVN_USERNAME:=barebits}"
: "${WPORG_SVN_PASSWORD:?WPORG_SVN_PASSWORD is required}"
: "${WPORG_SVN_URL:=https://plugins.svn.wordpress.org/$WPORG_SLUG}"

SVN_OPTS=(--non-interactive --no-auth-cache
          --username "$WPORG_SVN_USERNAME" --password "$WPORG_SVN_PASSWORD")

# The screenshot-N.png names wp.org displays, in the order captioned by the
# readme's == Screenshots == section (wordpress/readme.txt). Keep the two in
# sync when adding or reordering screenshots.
SCREENSHOTS=(
    create-invoice.png
    create-invoice-simple.png
    payment-pending.png
    payment-success.png
    admin-invoices.png
)

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Idempotency gate: a shipped version is immutable. (svn ls failure here can
# also mean auth/network trouble, which then fails loudly at checkout below.)
if svn ls "${SVN_OPTS[@]}" "$WPORG_SVN_URL/tags/$VERSION" >/dev/null 2>&1; then
    echo "tags/$VERSION already exists in $WPORG_SVN_URL — already deployed, nothing to do."
    exit 0
fi

unzip -q "$ZIP" -d "$WORK/zip"
if [ ! -d "$WORK/zip/barebits" ]; then
    echo "ERROR: zip does not contain the barebits/ plugin directory" >&2
    exit 1
fi
# The build stamps the readme's Stable tag from the plugin Version, and
# verify-release-version.sh pins both to the git tag — so a mismatch here
# means the wrong zip was passed for this tag.
if ! grep -q "^Stable tag: $VERSION$" "$WORK/zip/barebits/readme.txt"; then
    echo "ERROR: readme Stable tag in the zip does not match version $VERSION" >&2
    exit 1
fi
# The install-alongside downloader is forbidden by the directory's guidelines;
# its presence means the full-build zip was passed instead of the wporg one.
if [ -e "$WORK/zip/barebits/installer.php" ]; then
    echo "ERROR: installer.php present — this is not the wporg variant zip" >&2
    exit 1
fi

# Sparse checkout: trunk and assets in full, tags as an empty stub (tags hold
# a full copy per release — never pull those down; the version tag is created
# by a server-side URL copy at the end).
CO="$WORK/svn"
svn checkout -q "${SVN_OPTS[@]}" --depth immediates "$WPORG_SVN_URL" "$CO"
for dir in trunk assets tags; do
    if [ ! -d "$CO/$dir" ]; then
        mkdir "$CO/$dir"
        svn add -q "$CO/$dir"
    fi
done
svn update -q "${SVN_OPTS[@]}" --set-depth infinity "$CO/trunk" "$CO/assets"

# trunk <- zip contents, exactly.
rsync -a --delete --exclude='.svn' "$WORK/zip/barebits/" "$CO/trunk/"

# assets <- directory art, staged first so rsync --delete also prunes
# obsolete art from SVN.
STAGE="$WORK/assets"
mkdir "$STAGE"
cp wordpress/wporg-assets/*.png "$STAGE/"
i=1
for shot in "${SCREENSHOTS[@]}"; do
    cp "docs/screenshots/$shot" "$STAGE/screenshot-$i.png"
    i=$((i + 1))
done
rsync -a --delete --exclude='.svn' "$STAGE/" "$CO/assets/"

# SVN bookkeeping: schedule new files, drop vanished ones.
svn add -q --force "$CO/trunk" "$CO/assets"
svn status "$CO" | sed -n 's/^![[:space:]]*//p' | while IFS= read -r missing; do
    svn delete -q "$missing"
done

# Without an explicit mime-type wp.org serves images as octet-stream and the
# directory page shows broken art. Only set where absent, to avoid committing
# no-op property changes on every release.
find "$CO/assets" -name '*.png' -not -path '*/.svn/*' | while IFS= read -r png; do
    if [ "$(svn propget svn:mime-type "$png" 2>/dev/null)" != "image/png" ]; then
        svn propset -q svn:mime-type image/png "$png"
    fi
done

if [ -n "$(svn status "$CO")" ]; then
    svn commit -q "${SVN_OPTS[@]}" -m "Release $VERSION" "$CO"
else
    echo "trunk and assets already match $VERSION — committing nothing, tagging only."
fi

# Server-side copy: atomic, and never downloads tag contents.
svn copy -q "${SVN_OPTS[@]}" -m "Tag $VERSION" \
    "$WPORG_SVN_URL/trunk" "$WPORG_SVN_URL/tags/$VERSION"

echo "Deployed $VERSION to $WPORG_SVN_URL (trunk + assets + tags/$VERSION)."
