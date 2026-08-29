#!/bin/bash
# Bring up the test environment and run pytest.
#
# - Initializes the cashu-wallet-php submodule if missing.
# - Creates tests/.venv/ with the suite's Python deps.
# - Creates tests/.venv-nutshell/ (managed lazily by the mint fixture).
# - Downloads pinned bitcoind/lnd into tests/bin/ (cached on disk; gitignored).
# - Forwards any extra args straight to pytest.
#
# Serving backend (--backend=phps|apache|nginx|all):
#   With no flag the suite runs on ALL THREE backends sequentially — php -S,
#   then real Apache, then nginx+FPM in docker (see tests/fixtures/
#   webserver.py). A single backend runs exactly one pytest pass. CI pins
#   --backend=phps; the containerized passes are the local full-coverage run.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${TESTS_DIR}/.." && pwd)"

BACKEND="all"
PYTEST_ARGS=()
for arg in "$@"; do
  case "$arg" in
    --backend=*) BACKEND="${arg#--backend=}" ;;
    *) PYTEST_ARGS+=("$arg") ;;
  esac
done
case "$BACKEND" in
  phps|apache|nginx|all) ;;
  *) echo "[run-tests] invalid --backend=${BACKEND} (phps|apache|nginx|all)" >&2; exit 2 ;;
esac

# The docker backends need `sudo -n docker`; warn early so a passwordless-sudo
# gap doesn't surface as a fully-skipped pass an hour in.
if [ "$BACKEND" != "phps" ]; then
  if ! sudo -n docker version >/dev/null 2>&1; then
    echo "[run-tests] warning: 'sudo -n docker' unavailable — apache/nginx passes will skip all tests" >&2
  fi
fi

# 1. Submodules
if [ ! -f "${REPO_ROOT}/cashu-wallet-php/CashuWallet.php" ]; then
  echo "[run-tests] initializing cashu-wallet-php submodule"
  (cd "${REPO_ROOT}" && git submodule update --init --recursive)
fi

# 1b. Composer vendor/ for on-chain Bitcoin support (bitwasp/bitcoin et al).
#     Uses the static PHP binary the fixture manager downloaded, plus a pinned
#     composer.phar. --ignore-platform-reqs sidesteps a stale PHP 7 pin in a
#     transitive dep (lastguest/murmurhash) that still runs fine on PHP 8.
if [ ! -d "${REPO_ROOT}/vendor" ] && [ -f "${REPO_ROOT}/composer.json" ]; then
  python3 - "${TESTS_DIR}" "${REPO_ROOT}" <<'PYEOF'
import subprocess, sys
sys.path.insert(0, sys.argv[1])
from fixtures import binaries
php = binaries.ensure(binaries.PHP)["php"]
composer = binaries.ensure_file(binaries.COMPOSER)
print(f"[run-tests] installing composer dependencies into {sys.argv[2]}/vendor", flush=True)
subprocess.run(
    [str(php), str(composer), "install", "--no-progress", "--no-dev",
     "--optimize-autoloader", "--ignore-platform-reqs"],
    cwd=sys.argv[2], check=True,
)
PYEOF
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

# 4. Playwright Chromium (only needed for UI tests; install lands under
#    tests/bin/playwright-browsers/ so it caches across runs and survives
#    `tests/.venv` recreation). Skip with SKIP_PLAYWRIGHT=1 or when running
#    only e2e/wordpress tests.
export PLAYWRIGHT_BROWSERS_PATH="${TESTS_DIR}/bin/playwright-browsers"
if [ -z "${SKIP_PLAYWRIGHT:-}" ]; then
  if ! find "${PLAYWRIGHT_BROWSERS_PATH}" -name 'headless_shell' -o -name 'chrome' 2>/dev/null | grep -q .; then
    echo "[run-tests] downloading playwright chromium (one-time)"
    if ! playwright install chromium; then
      echo "[run-tests] warning: playwright install chromium failed; UI tests will be skipped" >&2
    fi
  fi
fi

# 5. Hand off to pytest
cd "${TESTS_DIR}"
if [ "$BACKEND" != "all" ]; then
  export CASHUPAY_TEST_BACKEND="$BACKEND"
  exec pytest "${PYTEST_ARGS[@]+"${PYTEST_ARGS[@]}"}"
fi

# --backend=all: one full pass per backend, sequentially, with a summary.
declare -A RESULTS
overall=0
for backend in phps apache nginx; do
  echo ""
  echo "[run-tests] ===== pytest pass: CASHUPAY_TEST_BACKEND=${backend} ====="
  if CASHUPAY_TEST_BACKEND="$backend" pytest "${PYTEST_ARGS[@]+"${PYTEST_ARGS[@]}"}"; then
    RESULTS[$backend]="pass"
    # A green pass's workdirs (~4-5G of payserver data dirs + WP trees per
    # pass) have no postmortem value; three passes don't fit most disks.
    # Failed passes keep theirs.
    rm -rf .tmp/payserver-* .tmp/wp-* .tmp/session-* 2>/dev/null || true
  else
    RESULTS[$backend]="FAIL($?)"
    overall=1
  fi
done
echo ""
echo "[run-tests] backend summary:"
for backend in phps apache nginx; do
  echo "  ${backend}: ${RESULTS[$backend]}"
done
exit "$overall"
