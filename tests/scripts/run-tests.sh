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
