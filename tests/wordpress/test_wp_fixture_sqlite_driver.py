"""The WordPress fixture puts the SQLite drop-in on its AST driver by default.

The legacy translator driver wraps every statement in a deferred BEGIN and
cannot upgrade a read transaction to a write once another php -S worker has
committed; WooCommerce's shutdown-hook session save then fails with
"database is locked" and the next request sees an empty cart. The AST driver
opens writes with BEGIN IMMEDIATE instead. This pins the fixture's wp-config
generation so the default cannot silently drift back (no server is started).
"""
from __future__ import annotations

import pytest

from fixtures import wordpress as wpfix

pytestmark = pytest.mark.wordpress


def _config(monkeypatch, **env: str) -> str:
    for name in ("CASHUPAY_WP_SQLITE_AST_DRIVER", "CASHUPAY_WP_SQLITE_JOURNAL_MODE"):
        monkeypatch.delenv(name, raising=False)
    for name, value in env.items():
        monkeypatch.setenv(name, value)
    return wpfix._wp_config_php(port=8080)


def test_ast_driver_is_the_default(monkeypatch) -> None:
    cfg = _config(monkeypatch)
    assert "define('WP_SQLITE_AST_DRIVER', true);" in cfg
    assert "SQLITE_JOURNAL_MODE" not in cfg, "journal mode stays the drop-in's default"


@pytest.mark.parametrize("off", ["0", "false", "no", "off", " OFF "])
def test_ast_driver_can_be_switched_off_for_ab_runs(monkeypatch, off) -> None:
    cfg = _config(monkeypatch, CASHUPAY_WP_SQLITE_AST_DRIVER=off)
    assert "WP_SQLITE_AST_DRIVER" not in cfg


@pytest.mark.parametrize("on", ["1", "true", "yes"])
def test_explicit_on_values_keep_the_driver(monkeypatch, on) -> None:
    cfg = _config(monkeypatch, CASHUPAY_WP_SQLITE_AST_DRIVER=on)
    assert "define('WP_SQLITE_AST_DRIVER', true);" in cfg


def test_journal_mode_knob_is_opt_in(monkeypatch) -> None:
    cfg = _config(monkeypatch, CASHUPAY_WP_SQLITE_JOURNAL_MODE="WAL")
    assert "define('SQLITE_JOURNAL_MODE', 'WAL');" in cfg
    assert "define('WP_SQLITE_AST_DRIVER', true);" in cfg
