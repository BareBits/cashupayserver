"""Attribution rules of the WordPress-compatibility probe (pure Python).

classify_debug_findings (fixtures/wordpress.py) turns a WP_DEBUG_LOG plus the
probe mu-plugin's stack-carrying records into the findings that belong to the
barebits plugin. These cases pin the rules test_wp_core_compat.py relies on
without booting WordPress.
"""
from __future__ import annotations

from pathlib import Path

from fixtures.wordpress import PLUGIN_TEXT_DOMAIN, classify_debug_findings

WP = "/srv/site/wp"
CORE_FN = f"{WP}/wp-includes/functions.php"
CORE_PLUGIN = f"{WP}/wp-includes/plugin.php"
WC = f"{WP}/wp-content/plugins/woocommerce/includes/wc-core-functions.php"
PROBE = f"{WP}/wp-content/mu-plugins/barebits-test-debug-probe.php"


def _plugin_dir(tmp_path: Path, source: str = "<?php // nothing\n") -> Path:
    d = tmp_path / "wp-content" / "plugins" / "barebits"
    d.mkdir(parents=True)
    (d / "barebits.php").write_text(source)
    return d


def _frames(*pairs: tuple[str, str]) -> list[dict]:
    """(function, call-site file) pairs, probe closure first like a real
    debug_backtrace() seen from inside the probe's callback."""
    base = [
        {"function": "{closure}", "file": PROBE, "line": 20},
        {"function": "do_action", "file": CORE_PLUGIN, "line": 517},
    ]
    return base + [{"function": fn, "file": file, "line": 1} for fn, file in pairs]


def test_php_log_lines_are_filtered_by_file(tmp_path: Path) -> None:
    plugin = _plugin_dir(tmp_path)
    ours = f"[26-Nov-2026 10:00:00 UTC] PHP Deprecated:  Creation of dynamic property X::$y is deprecated in {plugin}/onboarding.php on line 12"
    theirs = f"[26-Nov-2026 10:00:01 UTC] PHP Warning:  Undefined index: foo in {WC} on line 99"
    mentions = f"[26-Nov-2026 10:00:02 UTC] PHP Warning:  require({plugin}/missing.php): Failed to open stream in {CORE_FN} on line 5"
    out = classify_debug_findings([ours, theirs, mentions, "unrelated line"], [], plugin_dir=plugin)
    assert out == [ours, mentions]


def test_deprecated_function_is_pinned_on_its_direct_caller(tmp_path: Path) -> None:
    plugin = _plugin_dir(tmp_path)
    # Frame carrying the deprecated function's NAME holds the call site's file.
    called_by_us = {
        "kind": "deprecated_function", "function": "get_page_by_title", "replacement": "WP_Query",
        "version": "6.2.0", "request": "/wp-admin/admin.php?page=barebits",
        "trace": _frames(
            ("_deprecated_function", f"{WP}/wp-includes/post.php"),
            ("get_page_by_title", f"{plugin}/onboarding.php"),
            ("barebits_render", f"{WP}/wp-includes/class-wp-hook.php"),
        ),
    }
    # WooCommerce's internals calling a deprecated WooCommerce helper while
    # servicing a call the plugin made: WooCommerce's problem, not ours.
    called_by_wc = {
        "kind": "deprecated_function", "function": "wc_old_helper", "replacement": "", "version": "9.0",
        "request": "cli",
        "trace": _frames(
            ("_deprecated_function", WC),
            ("wc_old_helper", f"{WP}/wp-content/plugins/woocommerce/includes/class-wc-gateways.php"),
            ("WC_Payment_Gateways::init", f"{plugin}/btcpay-integration.php"),
        ),
    }
    out = classify_debug_findings([], [called_by_us, called_by_wc], plugin_dir=plugin)
    assert len(out) == 1, out
    assert out[0].startswith("deprecated_function: get_page_by_title"), out
    assert "version=6.2.0" in out[0] and "replacement=WP_Query" in out[0]
    assert f"at {plugin}/onboarding.php:1" in out[0]
    assert "during /wp-admin/admin.php?page=barebits" in out[0]


def test_class_and_constructor_deprecations_follow_the_instantiation_site(tmp_path: Path) -> None:
    plugin = _plugin_dir(tmp_path)
    ours = {
        "kind": "deprecated_class", "class": "WP_User_Search", "replacement": "WP_User_Query", "version": "3.1.0",
        "trace": _frames(
            ("_deprecated_class", f"{WP}/wp-includes/deprecated.php"),
            ("WP_User_Search::__construct", f"{plugin}/admin-menu.php"),
        ),
    }
    theirs = dict(ours, trace=_frames(
        ("_deprecated_class", f"{WP}/wp-includes/deprecated.php"),
        ("WP_User_Search::__construct", WC),
    ))
    out = classify_debug_findings([], [ours, theirs], plugin_dir=plugin)
    assert len(out) == 1 and "deprecated_class: WP_User_Search" in out[0], out


def test_deprecated_hook_is_ours_when_the_plugin_source_names_it(tmp_path: Path) -> None:
    plugin = _plugin_dir(
        tmp_path, "<?php add_filter('woocommerce_old_hook', 'barebits_cb');\n"
    )
    hooked = {
        "kind": "deprecated_hook", "hook": "woocommerce_old_hook", "replacement": "woocommerce_new_hook",
        "version": "9.5", "message": "", "trace": _frames(("apply_filters_deprecated", WC)),
    }
    not_hooked = dict(hooked, hook="woocommerce_other_hook")
    out = classify_debug_findings([], [hooked, not_hooked], plugin_dir=plugin)
    assert len(out) == 1 and "deprecated_hook: woocommerce_old_hook" in out[0], out


def test_early_translation_load_is_attributed_by_text_domain(tmp_path: Path) -> None:
    plugin = _plugin_dir(tmp_path)
    ours = {
        "kind": "doing_it_wrong", "function": "_load_textdomain_just_in_time",
        "message": f"Translation loading for the <code>{PLUGIN_TEXT_DOMAIN}</code> domain was triggered too early.",
        "version": "6.7.0",
        "trace": _frames(
            ("_doing_it_wrong", f"{WP}/wp-includes/l10n.php"),
            ("_load_textdomain_just_in_time", f"{WP}/wp-includes/l10n.php"),
            ("get_translations_for_domain", f"{WP}/wp-includes/l10n.php"),
            ("translate", f"{WP}/wp-includes/l10n.php"),
            ("__", f"{plugin}/barebits.php"),
        ),
    }
    theirs = dict(ours, message="Translation loading for the <code>woocommerce</code> domain was triggered too early.",
                  trace=_frames(("_doing_it_wrong", f"{WP}/wp-includes/l10n.php"), ("__", WC)))
    out = classify_debug_findings([], [ours, theirs], plugin_dir=plugin)
    assert len(out) == 1 and PLUGIN_TEXT_DOMAIN in out[0], out


def test_without_a_named_callee_the_first_wp_content_frame_decides(tmp_path: Path) -> None:
    plugin = _plugin_dir(tmp_path)
    # A direct _deprecated_function() call from a plugin file (the positive
    # control in test_wp_core_compat.py): no frame carries the named function.
    from_plugin_file = {
        "kind": "deprecated_function", "function": "barebits_probe_control_fn", "version": "0.0",
        "trace": _frames(
            ("_deprecated_function", f"{plugin}/zz-probe-control.php"),
            ("include", "phar:///wp-cli.phar/php/commands/eval-file.php"),
        ),
    }
    # The same call from wp-cli eval'd code: only core / phar / eval frames.
    from_eval = dict(from_plugin_file, function="barebits_probe_unattributed_fn", trace=_frames(
        ("_deprecated_function", "phar:///wp-cli.phar/vendor/wp-cli/eval-command/src/Eval_Command.php(29) : eval()'d code"),
    ))
    out = classify_debug_findings([], [from_plugin_file, from_eval], plugin_dir=plugin)
    assert len(out) == 1 and "barebits_probe_control_fn" in out[0], out
