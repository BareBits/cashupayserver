"""Setup wizard re-entry guard.

Audit finding #3: if config.setup_complete is missing (aborted install,
restored backup, manual purge) but the users table already has an admin,
the wizard's password step would silently overwrite the admin password.
After the fix, step 2 must refuse with 403 when any admin exists.
"""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD


def test_setup_step2_refuses_when_admin_already_exists(
    configured: ConfiguredPayserver,
) -> None:
    """Simulate the actual exploit scenario from audit finding #3:
    setup_complete is missing (corrupted, restored backup, etc.) but the
    users table still has an admin. The primary guard in setup.php (redirect
    when setup_complete) would no longer fire — so step 2 itself must refuse.
    """
    # Clear the primary guard so the request reaches step 2.
    with configured.handle.db() as db:
        db.execute("DELETE FROM config WHERE key = 'setup_complete'")

    url = f"{configured.handle.url}/setup.php"
    attacker_session = requests.Session()

    r = attacker_session.post(
        url,
        data={"step": "2", "password": "evil-pw-1234", "confirm_password": "evil-pw-1234"},
        timeout=15,
        allow_redirects=False,
    )
    assert r.status_code == 403, f"expected 403, got {r.status_code}: {r.text[:400]}"

    # Restore the setup_complete marker so the admin endpoint serves JSON again.
    import time
    with configured.handle.db() as db:
        db.execute(
            "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?)",
            ("setup_complete", "true", int(time.time()), int(time.time())),
        )

    # The original admin password must still work.
    login = requests.Session().post(
        f"{configured.handle.url}/admin",
        data={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
        timeout=15,
    )
    assert login.status_code == 200, login.text
    assert login.json()["success"] is True

    # And the attacker's password must NOT work.
    bad = requests.Session().post(
        f"{configured.handle.url}/admin",
        data={"action": "login", "username": "admin", "password": "evil-pw-1234"},
        timeout=15,
    )
    assert bad.status_code == 401
