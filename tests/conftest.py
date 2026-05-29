"""Top-level pytest fixtures.

Session-scoped: bitcoind, lnd_mint, lnd_payer, channels, mint.
Function-scoped: payserver (fresh data dir), webhook_sink.

Importing the fixtures module also forces binary download on first run.
"""
from __future__ import annotations

import shutil
import sys
import time
import uuid
from pathlib import Path
from typing import Iterator

import pytest

# Make fixtures/ importable as a package without needing an editable install.
TESTS_DIR = Path(__file__).resolve().parent
if str(TESTS_DIR) not in sys.path:
    sys.path.insert(0, str(TESTS_DIR))

from fixtures import binaries  # noqa: E402
from fixtures.bitcoind import BitcoindHandle, start_bitcoind, stop_bitcoind  # noqa: E402
from fixtures.lnd import (  # noqa: E402
    LndHandle,
    open_dual_channels,
    start_lnd,
    stop_lnd,
)
from fixtures.nutshell import MintHandle, start_mint, stop_mint  # noqa: E402
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver  # noqa: E402
from fixtures.webhook_sink import WebhookSink, start_webhook_sink, stop_webhook_sink  # noqa: E402

SESSION_TMP = TESTS_DIR / ".tmp"


@pytest.fixture(scope="session")
def session_workdir() -> Iterator[Path]:
    SESSION_TMP.mkdir(parents=True, exist_ok=True)
    workdir = SESSION_TMP / f"session-{int(time.time())}-{uuid.uuid4().hex[:6]}"
    workdir.mkdir(parents=True, exist_ok=True)
    yield workdir
    # Leave workdir on disk for postmortem; .tmp is gitignored.


@pytest.fixture(scope="session")
def installed_binaries() -> dict:
    return binaries.ensure_all()


@pytest.fixture(scope="session")
def bitcoind(session_workdir: Path, installed_binaries: dict) -> Iterator[BitcoindHandle]:
    handle = start_bitcoind(session_workdir)
    yield handle
    stop_bitcoind(handle)


@pytest.fixture(scope="session")
def lnd_mint(session_workdir: Path, bitcoind: BitcoindHandle, installed_binaries: dict) -> Iterator[LndHandle]:
    handle = start_lnd(session_workdir, "mint", bitcoind)
    yield handle
    stop_lnd(handle)


@pytest.fixture(scope="session")
def lnd_payer(session_workdir: Path, bitcoind: BitcoindHandle, installed_binaries: dict) -> Iterator[LndHandle]:
    handle = start_lnd(session_workdir, "payer", bitcoind)
    yield handle
    stop_lnd(handle)


@pytest.fixture(scope="session")
def channels(bitcoind: BitcoindHandle, lnd_payer: LndHandle, lnd_mint: LndHandle) -> None:
    open_dual_channels(bitcoind, lnd_payer, lnd_mint)


@pytest.fixture(scope="session")
def mint(session_workdir: Path, lnd_mint: LndHandle, channels: None) -> Iterator[MintHandle]:
    handle = start_mint(session_workdir, lnd_mint)
    yield handle
    stop_mint(handle)


@pytest.fixture
def payserver(tmp_path_factory: pytest.TempPathFactory) -> Iterator[PayserverHandle]:
    workdir = SESSION_TMP / f"payserver-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(workdir)
    yield handle
    stop_payserver(handle)
    # Leave workdir on disk for postmortem; .tmp is gitignored. Periodic cleanup
    # via `rm -rf tests/.tmp` is up to the developer.


@pytest.fixture
def webhook_sink() -> Iterator[WebhookSink]:
    sink = start_webhook_sink()
    yield sink
    stop_webhook_sink(sink)
