#!/bin/bash
# Webserver smoke for a BareBits install: pre-setup curl assertions that prove
# the serving layer — routing, rewrites, and the deny rules that keep the
# wallet database off the web. Works against any deployment (no container or
# shell access needed), so operators can point it at their own install:
#
#   scripts/webserver-smoke.sh http://localhost:8080            # Apache (.htaccess)
#   scripts/webserver-smoke.sh http://localhost:8081 --nginx    # docker/nginx-site.conf
#
# --nginx relaxes the Apache-only expectations: the mod_deflate/mod_expires
# asset checks are skipped (the canonical nginx config doesn't configure
# compression or expiry), and denied paths may answer 403 or 404 as long as
# they never serve content.
#
# Run in CI by .github/workflows/webserver-smoke.yml against the production
# Docker image (Apache) and the nginx test image.
set -u

BASE=""
NGINX=0
for arg in "$@"; do
  case "$arg" in
    --nginx) NGINX=1 ;;
    http://*|https://*) BASE="${arg%/}" ;;
    *) echo "usage: $0 <base-url> [--nginx]" >&2; exit 2 ;;
  esac
done
if [ -z "$BASE" ]; then
  echo "usage: $0 <base-url> [--nginx]" >&2
  exit 2
fi

FAILED=0

fail() {
  echo "FAIL - $1" >&2
  shift
  for line in "$@"; do echo "       $line" >&2; done
  FAILED=1
}

ok() {
  echo "ok - $1"
}

# fetch <method-agnostic GET> — sets STATUS, HEADERS, BODY (first 2KB)
fetch() {
  local url="$1"; shift
  local tmp
  tmp=$(mktemp)
  STATUS=$(curl -s -o "$tmp" -D "$tmp.h" -w '%{http_code}' "$@" "$url" || echo "000")
  HEADERS=$(cat "$tmp.h" 2>/dev/null || true)
  BODY=$(head -c 2048 "$tmp" 2>/dev/null | tr -d '\0' || true)
  rm -f "$tmp" "$tmp.h"
}

check_status() { # name url expected-status...
  local name="$1" url="$2"; shift 2
  fetch "$url"
  for want in "$@"; do
    if [ "$STATUS" = "$want" ]; then ok "$name ($STATUS)"; return 0; fi
  done
  fail "$name: got $STATUS, wanted $*" "url: $url" "body: $(echo "$BODY" | head -c 300)"
  return 1
}

check_denied() { # name url — 403 always ok; --nginx also accepts 404; never content
  local name="$1" url="$2"
  fetch "$url"
  local okstatus=0
  [ "$STATUS" = "403" ] && okstatus=1
  [ "$NGINX" = "1" ] && [ "$STATUS" = "404" ] && okstatus=1
  if [ "$okstatus" != "1" ]; then
    fail "$name: got $STATUS, wanted denied" "url: $url" "body: $(echo "$BODY" | head -c 300)"
    return 1
  fi
  case "$BODY" in
    *"SQLite format 3"*|*"<?php"*)
      fail "$name: denied status but content leaked" "url: $url"
      return 1 ;;
  esac
  ok "$name ($STATUS)"
}

# --- 0. Prime: first request initializes the schema, the data dir, and the
#        runtime data-dir protections (Database::ensureDataDirectoryProtections).
check_status "setup page answers" "$BASE/setup.php" 200

# --- 1. Routing ---------------------------------------------------------------
fetch "$BASE/" -L
if [ "$STATUS" = "200" ]; then
  ok "front page follows to a live page ($STATUS)"
else
  fail "front page: got $STATUS following redirects from /"
fi

# /health is cron-key gated: unauthenticated it answers 403 JSON. A 404 means
# the clean-URL route (front controller -> router.php PATH_INFO) never fired.
fetch "$BASE/health"
if [ "$STATUS" = "403" ] && printf '%s' "$BODY" | grep -q "forbidden"; then
  ok "clean URL /health routes (403 JSON)"
else
  fail "clean URL /health: got $STATUS, wanted 403 with a JSON 'forbidden' body" \
       "body: $(echo "$BODY" | head -c 200)"
fi

check_status "clean URL /setup routes" "$BASE/setup" 200
check_status "clean URL /admin routes" "$BASE/admin" 200 302

# /i/{invoiceId} is the BTCPay-compatible invoice URL BTCPay API clients (the
# WooCommerce gateway's "pay again" link) build themselves off the server URL;
# the front controller serves it through payment.php. payment.php's own
# answers ("Service unavailable" pre-setup, "Invoice not found" for a bogus
# id) prove the route reached PHP — a bare web-server 404 body means it never
# did.
fetch "$BASE/i/smoke-nonexistent"
if { [ "$STATUS" = "503" ] && printf '%s' "$BODY" | grep -q "Service unavailable"; } \
    || { [ "$STATUS" = "404" ] && printf '%s' "$BODY" | grep -q "Invoice not found"; }; then
  ok "BTCPay invoice URL /i/{id} routes (payment.php answered $STATUS)"
else
  fail "BTCPay invoice URL /i/{id}: got $STATUS, wanted payment.php's 503/404 body" \
       "body: $(echo "$BODY" | head -c 200)"
fi
check_status "API rewrite /api/v1/server/info" "$BASE/api/v1/server/info" 200 401 503
check_status "BTCPay alias /v1/server/info" "$BASE/v1/server/info" 200 401 503
check_status "pairing endpoint /api-keys/authorize" "$BASE/api-keys/authorize" 200 302 400 401 405 503

# --- 2. Deny rules ------------------------------------------------------------
check_denied "dotfile .htaccess blocked" "$BASE/.htaccess"
check_denied "dotdir .git blocked" "$BASE/.git/config"
check_denied "includes/ blocked" "$BASE/includes/database.php"
check_denied "cashu-wallet-php/ blocked" "$BASE/cashu-wallet-php/CashuWallet.php"
check_denied "scripts/ blocked" "$BASE/scripts/build-standalone.sh"
check_denied "docker/ blocked" "$BASE/docker/production-vhost.conf"
check_denied "user_config.php blocked" "$BASE/user_config.php"
check_denied "data/ dir blocked" "$BASE/data/"
check_denied "wallet DB blocked" "$BASE/data/cashupay.sqlite"
check_denied "wallet DB WAL blocked" "$BASE/data/cashupay.sqlite-wal"

# --- 3. Headers (both configs ship the security-header set) -------------------
fetch "$BASE/setup.php"
for header in "X-Content-Type-Options" "X-Frame-Options" "Referrer-Policy"; do
  if printf '%s' "$HEADERS" | grep -qi "^$header:"; then
    ok "security header $header present"
  else
    fail "security header $header missing on /setup.php"
  fi
done

# --- 4. Asset behaviour (Apache only: mod_deflate + mod_expires) --------------
if [ "$NGINX" = "0" ]; then
  # The app ships no standalone CSS (styles are inline); JS is covered by both
  # the DEFLATE type list and ExpiresByType in .htaccess.
  asset="assets/js/mint-ui.js"
  fetch "$BASE/$asset" -H "Accept-Encoding: gzip"
  if [ "$STATUS" != "200" ]; then
    fail "asset $asset: got $STATUS"
  else
    if printf '%s' "$HEADERS" | grep -qi '^Content-Encoding:.*gzip'; then
      ok "mod_deflate compresses JS"
    else
      fail "mod_deflate: no gzip Content-Encoding on $asset"
    fi
    if printf '%s' "$HEADERS" | grep -qiE '^(Expires|Cache-Control):'; then
      ok "mod_expires sets caching headers on JS"
    else
      fail "mod_expires: no Expires/Cache-Control on $asset"
    fi
  fi
fi

if [ "$FAILED" != "0" ]; then
  echo "webserver-smoke: FAILURES above" >&2
  exit 1
fi
echo "webserver-smoke: all checks passed"
