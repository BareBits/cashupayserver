#!/bin/bash
# Prune superseded testing-channel prereleases so the releases page stays
# dominated by stable releases.
#
# Testing prereleases pile up fast (one per push to `testing`), and GitHub's
# releases listing is strictly chronological with no way to pin stable
# releases above them. The "Latest" badge already sticks to the newest stable
# release (release.yml publishes testing tags with prerelease=true /
# make_latest=false); this script handles the listing clutter by keeping only
# the most recent testing prerelease and deleting the rest, tags included.
#
# Also sweeps orphaned v*-testing.* git tags whose release no longer exists —
# manual release deletions before this script existed left the tags behind.
#
# The newest testing prerelease is never deleted, and neither is <keep-tag>
# (the tag the calling workflow just published — normally the same thing, but
# passing it explicitly means a concurrent or reordered run can't delete a
# release it didn't know about). Stable releases and non-testing tags are
# never touched.
#
# Usage: scripts/prune-testing-releases.sh <owner/repo> <keep-tag>
#   Needs a `gh` authenticated with contents:write on <owner/repo>; in CI the
#   default GITHUB_TOKEN suffices (deletions don't need to re-trigger anything,
#   so the PAT caveat from testing-release.yml doesn't apply here).

set -euo pipefail

REPO="${1:-}"
KEEP="${2:-}"
if [ -z "$REPO" ] || [ -z "$KEEP" ]; then
    echo "usage: scripts/prune-testing-releases.sh <owner/repo> <keep-tag>" >&2
    exit 2
fi

# Published testing prereleases, newest first. ISO-8601 created_at sorts
# lexically; sort locally because gh applies --jq per page under --paginate.
RELEASES="$(gh api --paginate "repos/${REPO}/releases?per_page=100" \
    --jq '.[] | select(.prerelease and (.draft | not))
              | select(.tag_name | test("-testing\\.[0-9]+$"))
              | "\(.created_at)\t\(.tag_name)"' | sort -r | cut -f2-)"

NEWEST=""
while IFS= read -r tag; do
    [ -z "$tag" ] && continue
    if [ -z "$NEWEST" ]; then
        NEWEST="$tag"
        continue
    fi
    [ "$tag" = "$KEEP" ] && continue
    echo "deleting superseded testing prerelease: $tag"
    gh release delete "$tag" --repo "$REPO" --yes --cleanup-tag
done <<< "$RELEASES"

# Orphaned testing tags: a v*-testing.* tag with no release behind it serves
# nothing. Tags of releases deleted above are already gone via --cleanup-tag.
ALL_TAGS="$(gh api --paginate "repos/${REPO}/git/matching-refs/tags/" \
    --jq '.[].ref' | sed 's|^refs/tags/||')"

while IFS= read -r tag; do
    [ -z "$tag" ] && continue
    case "$tag" in
        *-testing.*) ;;
        *) continue ;;
    esac
    [ "$tag" = "$KEEP" ] || [ "$tag" = "$NEWEST" ] && continue
    if ! grep -qFx -- "$tag" <<< "$RELEASES"; then
        echo "deleting orphaned testing tag: $tag"
        gh api -X DELETE "repos/${REPO}/git/refs/tags/${tag}"
    fi
done <<< "$ALL_TAGS"

echo "prune complete (kept: ${NEWEST:-none})"
