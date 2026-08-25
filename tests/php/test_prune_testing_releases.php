<?php
/**
 * scripts/prune-testing-releases.sh keeps only the newest testing prerelease:
 * every older v*-testing.* prerelease is deleted (tag included), orphaned
 * testing tags with no release behind them are swept, and stable releases /
 * non-testing tags are never touched. The workflow-supplied <keep-tag> must
 * survive even when it is not the newest release in the listing.
 *
 * Runs the real script against a fake `gh` on PATH that serves canned
 * listings and logs every deletion it is asked to perform.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (DIRECTORY_SEPARATOR === '\\') {
    echo "test_prune_testing_releases: skipped (POSIX shell required)\n";
    exit(0);
}

$script = dirname(__DIR__, 2) . '/scripts/prune-testing-releases.sh';
assert_true(is_file($script), 'prune script exists');
assert_true(is_executable($script), 'prune script is executable');

$tmp = sys_get_temp_dir() . '/prune-test-' . bin2hex(random_bytes(6));
mkdir($tmp . '/bin', 0755, true);

// Fake gh: `api .../releases` and `api .../matching-refs` return the canned
// fixtures (what the real jq filters would emit); deletions are appended to
// a log instead of hitting GitHub.
file_put_contents($tmp . '/bin/gh', <<<'SH'
#!/bin/bash
if [ "$1" = "release" ] && [ "$2" = "delete" ]; then
    echo "release-delete $3" >> "$FAKE_GH_LOG"
    exit 0
fi
if [ "$1" = "api" ]; then
    if [ "$2" = "-X" ] && [ "$3" = "DELETE" ]; then
        echo "tag-delete ${4##*refs/tags/}" >> "$FAKE_GH_LOG"
        exit 0
    fi
    for a in "$@"; do
        case "$a" in
            */releases\?*)     cat "$FAKE_GH_RELEASES"; exit 0 ;;
            */matching-refs/*) cat "$FAKE_GH_TAGS";     exit 0 ;;
        esac
    done
fi
echo "unexpected gh invocation: $*" >&2
exit 1
SH);
chmod($tmp . '/bin/gh', 0755);

/**
 * Run the script with the given fixtures; return the deletion log lines.
 * @param string[] $releases  [created_at, tag] pairs as "ts tag" strings
 * @param string[] $tags      full tag names present on the remote
 */
function run_prune(string $script, string $tmp, array $releases, array $tags, string $keep): array
{
    $tsv = '';
    foreach ($releases as $r) {
        [$ts, $tag] = explode(' ', $r);
        $tsv .= "$ts\t$tag\n";
    }
    file_put_contents($tmp . '/releases.tsv', $tsv);
    file_put_contents(
        $tmp . '/tags.txt',
        implode('', array_map(fn($t) => "refs/tags/$t\n", $tags))
    );
    $log = $tmp . '/deletions.log';
    @unlink($log);
    touch($log);

    $cmd = sprintf(
        'PATH=%s:$PATH FAKE_GH_LOG=%s FAKE_GH_RELEASES=%s FAKE_GH_TAGS=%s %s %s %s 2>&1',
        escapeshellarg($tmp . '/bin'),
        escapeshellarg($log),
        escapeshellarg($tmp . '/releases.tsv'),
        escapeshellarg($tmp . '/tags.txt'),
        escapeshellarg($script),
        escapeshellarg('BareBits/cashupayserver'),
        escapeshellarg($keep)
    );
    exec($cmd, $out, $rc);
    assert_eq(0, $rc, 'script exit code (output: ' . implode(' | ', $out) . ')');
    return array_values(array_filter(array_map('trim', file($log))));
}

// NOTE: the releases fixture is pre-filtered TSV — the jq filter that drops
// stable releases and drafts runs inside gh in production, so stable entries
// never appear here. Stable *tags* do appear in the tags fixture, which is
// where the script itself must leave them alone.

// Case 1: three testing prereleases, keep == newest. The two older releases
// go; orphaned testing tags go; the stable tag and the kept tag survive.
$log = run_prune($script, $tmp, [
    '2026-08-24T22:09:14Z v1.2-testing.29',
    '2026-08-23T10:00:00Z v1.2-testing.28',
    '2026-08-22T09:00:00Z v1.2-testing.27',
], [
    'v1.2',
    'v1.2-testing.5',   // orphan: no release behind it
    'v1.2-testing.6',   // orphan
    'v1.2-testing.27',  // release deleted above; tag went with it (--cleanup-tag)
    'v1.2-testing.28',
    'v1.2-testing.29',
], 'v1.2-testing.29');
sort($log);
assert_eq([
    'release-delete v1.2-testing.27',
    'release-delete v1.2-testing.28',
    'tag-delete v1.2-testing.5',
    'tag-delete v1.2-testing.6',
], $log, 'case 1: prunes older releases and orphan tags only');

// Case 2: keep-tag is NOT the newest release. Both the newest and the
// keep-tag survive; only the third release goes.
$log = run_prune($script, $tmp, [
    '2026-08-24T22:09:14Z v1.2-testing.29',
    '2026-08-23T10:00:00Z v1.2-testing.28',
    '2026-08-22T09:00:00Z v1.2-testing.27',
], [
    'v1.2-testing.27',
    'v1.2-testing.28',
    'v1.2-testing.29',
], 'v1.2-testing.28');
assert_eq(['release-delete v1.2-testing.27'], $log,
    'case 2: keep-tag survives even when not newest');

// Case 3: nothing to prune — a single testing release and no orphan tags.
$log = run_prune($script, $tmp, [
    '2026-08-24T22:09:14Z v1.2-testing.29',
], [
    'v1.0',
    'v1.1',
    'v1.2',
    'v1.2-testing.29',
], 'v1.2-testing.29');
assert_eq([], $log, 'case 3: single testing release deletes nothing');

// Case 4: no testing releases at all (first run after a manual wipe) — only
// orphan tags are swept, and the script must not fail on the empty listing.
$log = run_prune($script, $tmp, [], [
    'v1.2',
    'v1.2-testing.4',
], 'v1.2-testing.30');
assert_eq(['tag-delete v1.2-testing.4'], $log,
    'case 4: empty release listing still sweeps orphan tags');

exec('rm -rf ' . escapeshellarg($tmp));
echo "test_prune_testing_releases: ok\n";
