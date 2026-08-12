"""WP-cron drives the full cron.php via authenticated loopback.

wordpress/cron-integration.php replaces the old quotes-only WP-cron callback:
firing the `cashupay_poll_quotes` event now performs a loopback request to the
plugin's cron endpoint with the real cron key, which runs the complete task
set and stamps `last_external_cron_at` — the same signal a manual crontab
entry produces, so the dashboard's cron staleness warning clears while (and
only while) the mechanism actually works.

These tests run the real callback and self-test inside a live WordPress via
wp-cli eval; the loopback goes over HTTP to the fixture's php -S server
(PHP_CLI_SERVER_WORKERS makes self-requests safe there).
"""
from __future__ import annotations

import pytest

from fixtures.wordpress import WordPressHandle

pytestmark = pytest.mark.wordpress


def _complete_setup(wp: WordPressHandle) -> None:
    wp.wp_cli(
        "eval",
        "require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';"
        "require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';"
        "Database::ensureExists(); Database::initialize();"
        "Config::set('setup_complete', true);",
    )


def _flush_rewrites(wp: WordPressHandle) -> None:
    """The cron endpoint lives behind the plugin's rewrite rule, which
    WordPress only honours once permalinks are non-default."""
    wp.wp_cli("rewrite", "structure", "/%postname%/", "--hard")
    wp.wp_cli("rewrite", "flush", "--hard")


def _config(wp: WordPressHandle, key: str):
    with wp.db() as db:
        row = db.execute("SELECT value FROM config WHERE key = ?", (key,)).fetchone()
    return None if row is None else row["value"]


def _selftest(wp: WordPressHandle) -> str:
    out = wp.wp_cli("eval", "var_export(cashupay_wp_cron_selftest());")
    return out.stdout.strip().splitlines()[-1]


def test_wp_cron_event_runs_full_cron(wordpress: WordPressHandle) -> None:
    _flush_rewrites(wordpress)
    _complete_setup(wordpress)
    assert _config(wordpress, "cron_key"), "install seeds a cron key"
    assert _config(wordpress, "last_external_cron_at") is None

    # Fire the scheduled event's callback exactly as WP-cron would.
    wordpress.wp_cli("eval", "do_action('cashupay_poll_quotes');")

    # cron.php ran as an EXTERNAL cron (real key), so it stamped the marker
    # the dashboard staleness warning keys off.
    assert int(_config(wordpress, "last_external_cron_at") or 0) > 0, (
        "the WP-cron callback must drive cron.php with the external key"
    )
    # A successful loopback must not set the retry backoff.
    backoff = wordpress.wp_cli(
        "option", "get", "cashupay_cron_loopback_retry_at", check=False
    )
    assert backoff.returncode != 0, "no backoff marker after a successful loopback"


def test_selftest_reports_reachability_honestly(wordpress: WordPressHandle) -> None:
    """The self-test must pass only when cron.php actually answers.

    With default (plain) permalinks the /cashupay/cron rewrite is not
    honoured — the request falls through to WordPress's front controller,
    which can even answer 200 — so the self-test must fail (the JSON-body
    check catches this). After permalinks are flushed it passes and stamps
    config; losing the cron key flips it back to failing without touching
    the earlier stamp's meaning for the completion screen.
    """
    _complete_setup(wordpress)

    # Plain permalinks: endpoint unreachable, self-test must say so.
    assert _selftest(wordpress) == "false"
    assert _config(wordpress, "wp_cron_selftest_ok_at") is None

    # Pretty permalinks: the loopback path works end to end.
    _flush_rewrites(wordpress)
    assert _selftest(wordpress) == "true"
    assert int(_config(wordpress, "wp_cron_selftest_ok_at") or 0) > 0

    # No key (host can't authenticate): failure again, so the wizard keeps
    # the manual instructions.
    with wordpress.db() as db:
        db.execute("DELETE FROM config WHERE key = 'cron_key'")
    assert _selftest(wordpress) == "false"
