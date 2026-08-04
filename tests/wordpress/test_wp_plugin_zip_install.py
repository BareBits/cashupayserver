"""E2E: the SHIPPED WordPress plugin zip installs and activates cleanly.

Every other WP test assembles the plugin from symlinks to live source. This one
consumes the real ``build/wordpress_plugin.zip`` artifact — the exact bytes the
release workflow publishes — and installs it the way an operator does:
``wp plugin install <zip>``. It is the only test that proves the shipped zip's
flattened layout (every ``__DIR__ . '/sibling.php'`` require) and bundled
``vendor/`` actually resolve when WordPress unpacks and loads it.
"""
from __future__ import annotations

from pathlib import Path

import pytest
import requests

from fixtures.wordpress import WordPressHandle

pytestmark = pytest.mark.wordpress


def _install_zip(wp: WordPressHandle, zip_path: Path):
    return wp.wp_cli("plugin", "install", str(zip_path), "--activate", check=False)


def test_shipped_zip_installs_and_activates(
    wordpress_bare: WordPressHandle, wp_plugin_zip: Path
) -> None:
    wp = wordpress_bare

    # Precondition: a bare WP has no cashupay plugin at all.
    before = wp.wp_cli("plugin", "list", "--field=name")
    assert "cashupay" not in before.stdout.split(), "bare WP should have no cashupay plugin"

    # Install + activate the real artifact.
    res = _install_zip(wp, wp_plugin_zip)
    assert res.returncode == 0, f"plugin install failed:\n{res.stdout}\n{res.stderr}"

    # wp-cli reports it active — activation ran every flattened __DIR__ require
    # (cashupay.php -> bootstrap.php / activation.php / rewrite-rules.php ...)
    # against the installed tree without a fatal.
    active = wp.wp_cli("plugin", "list", "--field=name", "--status=active").stdout.split()
    assert "cashupay" in active, f"cashupay not active after install; active: {active}"

    # Header metadata parsed from the installed main file.
    version = wp.wp_cli("plugin", "get", "cashupay", "--field=version").stdout.strip()
    assert version, "plugin version header empty — cashupay.php header not parsed"

    # The zip bundled the shared backend + composer deps (the whole point of
    # shipping a zip rather than source): includes/ and vendor/ actually landed.
    plugin_dir = wp.wp_root / "wp-content" / "plugins" / "cashupay"
    assert (plugin_dir / "includes" / "database.php").is_file(), "includes/ missing from installed zip"
    assert (plugin_dir / "vendor" / "autoload.php").is_file(), "composer vendor/ missing from installed zip"

    # Loaded in the WP request context: the plugin's constants are defined,
    # proving cashupay.php -> bootstrap.php -> includes/urls.php all resolved.
    ev = wp.wp_cli(
        "eval",
        "echo (int)defined('CASHUPAY_WORDPRESS') . '|' "
        ". (defined('CASHUPAY_PLUGIN_DIR') ? CASHUPAY_PLUGIN_DIR : 'undef');",
    )
    flag, plugin_dir_const = ev.stdout.strip().split("|", 1)
    assert flag == "1", f"CASHUPAY_WORDPRESS not defined after zip install: {ev.stdout!r} {ev.stderr!r}"
    assert "cashupay" in plugin_dir_const, plugin_dir_const


def test_shipped_zip_setup_route_renders(
    wordpress_bare: WordPressHandle, wp_plugin_zip: Path
) -> None:
    """After installing the zip, the plugin's setup rewrite route serves without
    a PHP fatal — a runtime dispatch through the installed tree, beyond the
    load-time requires the activation check above covers."""
    wp = wordpress_bare
    res = _install_zip(wp, wp_plugin_zip)
    assert res.returncode == 0, f"plugin install failed:\n{res.stdout}\n{res.stderr}"

    # The wizard is reachable via a rewrite rule, honoured only once permalinks
    # are non-default.
    wp.wp_cli("rewrite", "structure", "/%postname%/", "--hard")
    wp.wp_cli("rewrite", "flush", "--hard")

    r = requests.get(f"{wp.url}/cashupay-setup/", timeout=30)
    assert r.status_code < 500, f"setup route 5xx after zip install: {r.status_code}: {r.text[:300]}"
    assert "Fatal error" not in r.text, f"PHP fatal in setup route: {r.text[:500]}"
