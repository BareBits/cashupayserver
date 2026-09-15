#!/bin/bash
# Rebuild assets/js/bc-ur.bundle.js — the self-contained browser bundle of
# @gandlaf21/bc-ur (MIT) that exposes window.bcur = { UR, UREncoder } for
# assets/js/animated-qr.js.
#
# The admin used to import this straight from cdn.skypack.dev; it is bundled
# and served locally so no third-party CDN sits in the path of QR code
# rendering (privacy: visitor IPs; integrity: a CDN could serve a renderer
# that encodes attacker-controlled data). Re-run only when bumping the
# pinned version below, and commit the regenerated bundle.

set -e
cd "$(dirname "$0")/.."

BCUR_VERSION=1.1.12
ESBUILD_VERSION=0.24.2

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

cd "$WORK"
npm init -y >/dev/null
npm install --no-audit --no-fund "@gandlaf21/bc-ur@${BCUR_VERSION}" "esbuild@${ESBUILD_VERSION}" >/dev/null
printf "import { UR, UREncoder } from '@gandlaf21/bc-ur';\nwindow.bcur = { UR, UREncoder };\n" > entry.js
npx esbuild entry.js --bundle --minify --format=iife --platform=browser \
    --outfile=bc-ur.bundle.js

cd - >/dev/null
cp "$WORK/bc-ur.bundle.js" assets/js/bc-ur.bundle.js
echo "assets/js/bc-ur.bundle.js rebuilt (@gandlaf21/bc-ur@${BCUR_VERSION})"
