"""Package marker.

This directory must stay a package: without ``__init__.py`` pytest would
import ``tests/wordpress/conftest.py`` as top-level ``conftest``, clobbering
``sys.modules['conftest']`` (normally tests/conftest.py) and breaking every
e2e/ui module's ``from conftest import ConfiguredPayserver`` in a combined
run. As a package, this suite's conftest is importable as
``wordpress.conftest`` — which is how its test modules import the shared
helpers (wp_login, onboarding_page, ...).
"""
