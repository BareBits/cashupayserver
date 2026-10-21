"""scripts/deploy-wporg-svn.sh against a local file:// SVN repository.

The release workflow's wporg-svn job pushes each stable release's wporg
plugin zip to the wordpress.org plugin directory SVN. These tests run the
real deploy script against a throwaway `svnadmin create` repository (the
script's WPORG_SVN_URL override exists for exactly this), covering:

  - a first deploy into an empty repo (trunk + directory assets + version tag,
    png mime-types set so wp.org serves the art as images)
  - idempotent re-runs (an already-tagged version is never touched again)
  - a follow-up release (trunk file adds/removals reach SVN, new tag cut)
  - the refusal gates: prerelease tags, the full-build zip (installer.php),
    and a zip whose Stable tag disagrees with the release tag

No WordPress instance is involved; the only heavyweight dependency is the
built wporg zip (shared session fixture) and the svn/svnadmin binaries.
"""
from __future__ import annotations

import re
import shutil
import subprocess
import zipfile
from pathlib import Path

import pytest

from fixtures.wordpress import ensure_wp_plugin_wporg_zip

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
DEPLOY_SCRIPT = REPO_ROOT / "scripts" / "deploy-wporg-svn.sh"

pytestmark = pytest.mark.skipif(
    shutil.which("svn") is None or shutil.which("svnadmin") is None,
    reason="svn/svnadmin not installed",
)

# Must mirror the SCREENSHOTS list in deploy-wporg-svn.sh (same count/order).
EXPECTED_ASSETS = {
    "banner-772x250.png",
    "banner-1544x500.png",
    "icon-128x128.png",
    "icon-256x256.png",
    "screenshot-1.png",
    "screenshot-2.png",
    "screenshot-3.png",
    "screenshot-4.png",
    "screenshot-5.png",
}


@pytest.fixture(scope="module")
def wporg_zip() -> Path:
    return ensure_wp_plugin_wporg_zip()


@pytest.fixture(scope="module")
def plugin_version(wporg_zip: Path) -> str:
    with zipfile.ZipFile(wporg_zip) as zf:
        readme = zf.read("barebits/readme.txt").decode()
    m = re.search(r"^Stable tag: (\S+)$", readme, re.M)
    assert m, "built zip has no Stable tag"
    return m.group(1)


@pytest.fixture()
def svn_repo(tmp_path: Path) -> str:
    subprocess.run(["svnadmin", "create", str(tmp_path / "repo")], check=True)
    return (tmp_path / "repo").as_uri()


def run_deploy(repo_url: str, zip_path: Path, tag: str) -> subprocess.CompletedProcess:
    return subprocess.run(
        [str(DEPLOY_SCRIPT), str(zip_path), tag],
        capture_output=True,
        text=True,
        cwd=REPO_ROOT,
        env={
            "PATH": "/usr/bin:/bin",
            "WPORG_SVN_URL": repo_url,
            "WPORG_SVN_PASSWORD": "test-password",
        },
    )


def svn_ls(repo_url: str, path: str = "") -> set[str]:
    out = subprocess.run(
        ["svn", "ls", f"{repo_url}/{path}"], capture_output=True, text=True, check=True
    )
    return {line.rstrip("/") for line in out.stdout.splitlines()}


def svn_youngest(repo_url: str) -> str:
    return subprocess.run(
        ["svn", "info", "--show-item", "revision", repo_url],
        capture_output=True, text=True, check=True,
    ).stdout.strip()


def rezip(src_zip: Path, dest_zip: Path, version: str, mutate) -> None:
    """Copy the plugin zip with the Stable tag rewritten to `version`;
    `mutate(dict)` can add/drop members (keys are in-zip names)."""
    with zipfile.ZipFile(src_zip) as zf:
        members = {info.filename: zf.read(info.filename) for info in zf.infolist()
                   if not info.is_dir()}
    readme = members["barebits/readme.txt"].decode()
    members["barebits/readme.txt"] = re.sub(
        r"^Stable tag: \S+$", f"Stable tag: {version}", readme, flags=re.M
    ).encode()
    mutate(members)
    with zipfile.ZipFile(dest_zip, "w", zipfile.ZIP_DEFLATED) as zf:
        for name, data in sorted(members.items()):
            zf.writestr(name, data)


def test_first_deploy_populates_trunk_assets_and_tag(
    svn_repo: str, wporg_zip: Path, plugin_version: str
) -> None:
    result = run_deploy(svn_repo, wporg_zip, f"v{plugin_version}")
    assert result.returncode == 0, result.stderr

    assert svn_ls(svn_repo) == {"trunk", "assets", "tags"}
    trunk = svn_ls(svn_repo, "trunk")
    assert "barebits.php" in trunk
    assert "readme.txt" in trunk
    assert "installer.php" not in trunk
    assert svn_ls(svn_repo, "assets") == EXPECTED_ASSETS
    assert svn_ls(svn_repo, "tags") == {plugin_version}
    assert "readme.txt" in svn_ls(svn_repo, f"tags/{plugin_version}")

    mime = subprocess.run(
        ["svn", "propget", "svn:mime-type",
         f"{svn_repo}/assets/banner-772x250.png"],
        capture_output=True, text=True, check=True,
    ).stdout.strip()
    assert mime == "image/png"


def test_rerun_of_deployed_version_is_a_noop(
    svn_repo: str, wporg_zip: Path, plugin_version: str
) -> None:
    assert run_deploy(svn_repo, wporg_zip, f"v{plugin_version}").returncode == 0
    before = svn_youngest(svn_repo)

    rerun = run_deploy(svn_repo, wporg_zip, f"v{plugin_version}")
    assert rerun.returncode == 0, rerun.stderr
    assert "already deployed" in rerun.stdout
    assert svn_youngest(svn_repo) == before


def test_second_release_syncs_adds_and_removals(
    svn_repo: str, wporg_zip: Path, plugin_version: str, tmp_path: Path
) -> None:
    assert run_deploy(svn_repo, wporg_zip, f"v{plugin_version}").returncode == 0

    next_version = "999.0"
    next_zip = tmp_path / "next.zip"

    def mutate(members: dict) -> None:
        del members["barebits/state.php"]
        members["barebits/brand-new-file.php"] = b"<?php // new in 999.0\n"

    rezip(wporg_zip, next_zip, next_version, mutate)
    result = run_deploy(svn_repo, next_zip, f"v{next_version}")
    assert result.returncode == 0, result.stderr

    trunk = svn_ls(svn_repo, "trunk")
    assert "brand-new-file.php" in trunk
    assert "state.php" not in trunk
    assert svn_ls(svn_repo, "tags") == {plugin_version, next_version}
    # the first release's tag is immutable — still carries the old file set
    old_tag = svn_ls(svn_repo, f"tags/{plugin_version}")
    assert "state.php" in old_tag
    assert "brand-new-file.php" not in old_tag


@pytest.mark.parametrize(
    "tag",
    ["v1.5.1-testing.3", "v1.6-rc1", "v1.6-alpha", "1.6", "main", "v1.6.0.1"],
)
def test_non_stable_tags_are_refused(
    svn_repo: str, wporg_zip: Path, tag: str
) -> None:
    result = run_deploy(svn_repo, wporg_zip, tag)
    assert result.returncode != 0
    assert "not a stable release tag" in result.stderr
    assert svn_youngest(svn_repo) == "0"


def test_full_build_zip_is_refused(
    svn_repo: str, wporg_zip: Path, plugin_version: str, tmp_path: Path
) -> None:
    full_zip = tmp_path / "full.zip"
    rezip(
        wporg_zip, full_zip, plugin_version,
        lambda members: members.update(
            {"barebits/installer.php": b"<?php // install-alongside\n"}
        ),
    )
    result = run_deploy(svn_repo, full_zip, f"v{plugin_version}")
    assert result.returncode != 0
    assert "not the wporg variant" in result.stderr
    assert svn_youngest(svn_repo) == "0"


def test_stable_tag_mismatch_is_refused(
    svn_repo: str, wporg_zip: Path, plugin_version: str, tmp_path: Path
) -> None:
    stale_zip = tmp_path / "stale.zip"
    rezip(wporg_zip, stale_zip, "0.0.1", lambda members: None)
    result = run_deploy(svn_repo, stale_zip, f"v{plugin_version}")
    assert result.returncode != 0
    assert "Stable tag" in result.stderr
    assert svn_youngest(svn_repo) == "0"
