"""WordPress-suite fixtures.

`wordpress_bare` + `wp_plugin_zip` back test_wp_plugin_zip_install, which
installs the real built plugin zip instead of the symlinked live-source tree
the other WP tests use.
"""
from __future__ import annotations

import uuid
from pathlib import Path
from typing import Iterator

import pytest

from fixtures.wordpress import (
    WordPressHandle,
    ensure_wp_plugin_zip,
    start_wordpress,
    stop_wordpress,
)

TESTS_DIR = Path(__file__).resolve().parent.parent
SESSION_TMP = TESTS_DIR / ".tmp"


@pytest.fixture(scope="session")
def wp_plugin_zip() -> Path:
    """The built WordPress plugin zip (CI artifact, or built locally). Session
    scoped so the (potentially expensive) build happens at most once."""
    return ensure_wp_plugin_zip()


@pytest.fixture
def wordpress_bare() -> Iterator[WordPressHandle]:
    """Fresh WordPress install with NO cashupay plugin, so a test can install
    the built zip itself. Function-scoped — each test gets its own WP root."""
    workdir = SESSION_TMP / f"wp-bare-{uuid.uuid4().hex[:8]}"
    handle = start_wordpress(workdir, install_cashupay=False)
    yield handle
    stop_wordpress(handle)
