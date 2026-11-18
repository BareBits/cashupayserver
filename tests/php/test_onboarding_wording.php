<?php
/**
 * Operator-facing copy on the onboarding wizard and the matching admin card
 * (wording change of 2026-11-18):
 *
 *  - Anonymous usage statistics (wizard screen two + Settings card): no
 *    cadence/endpoint sentence, the exclusion list drops "deployment ID"
 *    and ends in a PII catch-all.
 *  - Lightning payments: the self-custody pointer (clink / Electrum NWC /
 *    Liquidity Management plugin) sits above the method list, and the LNURL
 *    help box ends after its Strike paragraph.
 *
 * Static source check so the PHP suite catches a regression without a
 * browser; the e2e/ui suites assert the rendered result.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

$root = dirname(__DIR__, 2) . '/app';
$flatten = static fn(string $src): string => preg_replace('/\s+/', ' ', $src);

$setup = $flatten((string)file_get_contents($root . '/setup.php'));
$admin = $flatten((string)file_get_contents($root . '/admin.php'));

// --- Anonymous usage statistics -------------------------------------------
foreach (['setup.php' => $setup, 'admin.php' => $admin] as $file => $src) {
    assert_true(str_contains($src, 'customer or invoice details or other personally-identifiable information.'),
        "$file: exclusion list ends in the PII catch-all");
    assert_true(str_contains($src, 'No report ever includes your store names, mint or Lightning addresses'),
        "$file: exclusion list no longer leads with the deployment ID");
    assert_false(str_contains($src, 'No report ever includes your deployment ID'),
        "$file: stale deployment-ID wording removed");
    assert_true(str_contains($src, 'choose how much is reported and'),
        "$file: 'how much is reported' phrasing");
}
assert_false(str_contains($setup, 'one small report now and then one a week'),
    'setup.php: cadence/endpoint sentence removed from the analytics screen');
assert_false(str_contains($admin, 'a small weekly report'),
    'admin.php: weekly-report sentence removed from the Settings card');

// --- Lightning payments screen --------------------------------------------
$notePos = strpos($setup, 'id="ln-self-custody-note"');
$introPos = strpos($setup, 'Only one method is needed for lightning payments to work');
$lnurlPos = strpos($setup, 'id="lnurl-section"');
assert_true($notePos !== false && $introPos !== false && $lnurlPos !== false, 'anchors present');
assert_true($introPos < $notePos && $notePos < $lnurlPos,
    'self-custody note sits below the intro and above Method 1');

$noteEnd = strpos($setup, '</p>', $notePos);
$note = substr($setup, $notePos, $noteEnd - $notePos);
assert_true(str_contains($note, 'Want full self-custody?'), 'note opens with the self-custody question');
foreach (['https://github.com/BareBits/electrum_clink', 'https://electrum.org', 'https://github.com/BareBits/electrum_liquidity'] as $href) {
    assert_true(str_contains($note, 'href="' . $href . '"'), "note links $href");
}
assert_true(str_contains($note, 'graceful fallback options'), 'note keeps the fallback sentence');

$boxStart = strpos($setup, 'id="ln-help-box"');
$boxEnd = strpos($setup, '</div>', $boxStart);
$box = substr($setup, $boxStart, $boxEnd - $boxStart);
assert_true(str_contains($box, 'Use the <strong>Strike API</strong> method below instead.'),
    'help box keeps the Strike paragraph');
assert_eq(1, substr_count($box, '<p '), 'help box is the Strike paragraph only');
foreach (['self-custody', 'STRONGLY recommended', 'Alternate option', 'cashu mint'] as $gone) {
    assert_false(str_contains($box, $gone), "help box no longer contains '$gone'");
}

echo "test_onboarding_wording: ok\n";
