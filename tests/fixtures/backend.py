"""Small helpers for backend-conditional test logic.

The serving backend (php -S / Apache / nginx — see tests/fixtures/webserver.py)
changes two things tests may need to reason about:

  - Opportunistic cron: Background::trigger()'s loopback self-request dies
    against the single-worker php -S (100 ms timeout, no free worker), but
    actually executes under Apache/nginx. Assertions of the form "nothing
    settled until I called trigger_cron()" are only deterministic on phps.

  - url_mode: php -S treats /router.php as a real file that bypasses the
    fixture's router wrapper, so DB-seeded fixtures historically pinned
    url_mode='direct'. Apache's .htaccess catch-all and the canonical nginx
    front controller both make 'clean' the mode the wizard's probe would
    actually detect.
"""
from __future__ import annotations

from . import webserver


def backend() -> str:
    return webserver.current_backend()


def is_phps() -> bool:
    return backend() == "phps"


def cron_is_opportunistic() -> bool:
    """True when page loads may run cron work in the background (Apache/nginx),
    i.e. when "not yet processed" assertions between explicit trigger_cron()
    calls are racy."""
    return not is_phps()


def default_url_mode() -> str:
    """The url_mode a DB-seeding fixture should pin: what the wizard's
    client-side probe would detect on this backend."""
    return "direct" if is_phps() else "clean"
