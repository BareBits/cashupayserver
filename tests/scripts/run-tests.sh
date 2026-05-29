#!/bin/bash
# Bring up the test environment and run pytest.
#
# - Initializes the cashu-wallet-php submodule if missing.
# - Creates tests/.venv/ with the suite's Python deps.
# - Creates tests/.venv-nutshell/ (managed lazily by the mint fixture).
# - Downloads pinned bitcoind/lnd into tests/bin/ (cached on disk; gitignored).
# - Forwards any extra args straight to pytest.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${TESTS_DIR}/.." && pwd)"

# 1. Submodules
if [ ! -f "${REPO_ROOT}/cashu-wallet-php/CashuWallet.php" ]; then
  echo "[run-tests] initializing cashu-wallet-php submodule"
  (cd "${REPO_ROOT}" && git submodule update --init --recursive)
fi

# 1a. Apply the LNURL-pay URL override patch to cashu-wallet-php so the
#     auto-melt tests can point the resolver at a local mock server. The patch
#     adds a CASHU_LNURL_URL_TEMPLATE env honor that no-ops in production. We
#     intentionally don't commit this to the submodule — it's a tests-only shim
#     that should eventually land upstream.
LNURL_PATCH="${TESTS_DIR}/scripts/cashu-wallet-php-lnurl-override.patch"
if [ -f "${LNURL_PATCH}" ]; then
  if (cd "${REPO_ROOT}/cashu-wallet-php" && git apply --check "${LNURL_PATCH}" 2>/dev/null); then
    echo "[run-tests] applying LNURL override patch to cashu-wallet-php"
    (cd "${REPO_ROOT}/cashu-wallet-php" && git apply "${LNURL_PATCH}")
  fi
fi

# 2. Test venv
VENV="${TESTS_DIR}/.venv"
if [ ! -d "${VENV}" ]; then
  echo "[run-tests] creating ${VENV}"
  python3 -m venv "${VENV}"
fi
# shellcheck disable=SC1091
source "${VENV}/bin/activate"
pip install --quiet --upgrade pip
pip install --quiet -r "${TESTS_DIR}/requirements.txt"

# 3. PHP is downloaded on first use by the binary manager — no host PHP needed.

# 4. Playwright browser (only needed for UI tests; install is best-effort,
#    skip with SKIP_PLAYWRIGHT=1 or if you're only running e2e/wordpress tests).
export PLAYWRIGHT_BROWSERS_PATH="${TESTS_DIR}/bin/playwright-browsers"
if [ -z "${SKIP_PLAYWRIGHT:-}" ]; then
  if ! playwright install chromium >/dev/null 2>&1; then
    echo "[run-tests] warning: playwright install chromium failed; UI tests will be skipped" >&2
  fi
fi

# 5. Hand off to pytest
cd "${TESTS_DIR}"
exec pytest "$@"
