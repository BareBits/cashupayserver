<?php
/**
 * The wizard's "(Optional) Enable Rewards" screen (setup.php step=rewards).
 *
 * First run: it is the first post-completion screen — it follows the last
 * rail screen (the install is already complete) and hands over to the
 * wallet-tabs question. Continue writes the answer through
 * Rewards::setStoreSettings (ticked = on at the typed percentage, unticked =
 * off); a refused percentage re-renders the same screen with the banner and
 * the typed values; Skip writes nothing. add_store: it sits between mints and
 * wallet_tabs (which remains the hand-off screen) and its form carries the
 * mode. Driven over real HTTP (php -S) because the handler redirects and
 * sessions.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/http_driver.php';

$dir = sys_get_temp_dir() . '/cashupay_test_' . bin2hex(random_bytes(6));
mkdir($dir, 0750, true);
define('CASHUPAY_DATA_DIR', $dir);
register_shutdown_function(function () use ($dir) { @cleanup_db($dir); });
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/rewards.php';

$srv = HttpDriver::start($dir);

function store_id_of(string $name): ?string {
    $row = Database::fetchOne("SELECT id FROM stores WHERE name = ?", [$name]);
    return $row ? (string)$row['id'] : null;
}
function rewards_of(string $name): array {
    $id = store_id_of($name);
    assert_not_null($id, "store $name exists");
    return Rewards::forStore($id);
}
function heading(string $body): string {
    if (!preg_match('/<h2[^>]*>(.*?)<\/h2>/s', $body, $m)) { return ''; }
    $h = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
    // Drop a leading emoji / pictograph (anything >= U+2000) and whitespace.
    return trim(preg_replace('/^[\x{2000}-\x{10FFFF}\s]+/u', '', $h));
}
function error_banner(string $body): ?string {
    return preg_match('/<div class="error" id="setup-error">(.*?)<\/div>/s', $body, $m)
        ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)) : null;
}
function box_checked(string $body): bool {
    return preg_match('/id="rewards_enabled" name="rewards_enabled" value="1" checked>/', $body) === 1;
}
function percent_value(string $body): ?string {
    return preg_match('/id="rewards_percent" name="rewards_percent"[^>]*value="([^"]*)"/', $body, $m) ? $m[1] : null;
}
function back_target(string $body): ?string {
    return preg_match('/step=([a-z_]+)[^"]*"[^>]*id="wizard-back-link"/', $body, $m) ? $m[1] : null;
}

// ---- first run up to the rewards screen ----------------------------------------
$r = $srv->get('/setup.php');
$csrf = HttpDriver::wizardCsrf($r['body']);
assert_not_null($csrf, 'wizard renders a csrf_token field');
$post = function (array $data) use ($srv, &$csrf): array {
    $data['csrf_token'] = $csrf;
    $r = $srv->post('/setup.php', $data);
    assert_true($r['status'] < 400, ($data['step'] ?? '?') . ' -> ' . $r['status']);
    assert_false(str_contains($r['body'], '<div class="error"'), ($data['step'] ?? '?') . ' rendered an error: ' . substr($r['body'], 0, 300));
    return $r;
};
$post(['step' => 'terms', 'terms_legal' => '1', 'terms_warranty' => '1', 'terms_fee' => '1']);
$post(['step' => 'analytics', 'usage_stats' => '0']);
$post(['step' => 'install_type', 'install_type' => 'other']);
$post(['step' => 'security', 'security_acknowledged' => '1']);
$post(['step' => 'password', 'password' => 'rewards-pw-1234', 'confirm_password' => 'rewards-pw-1234']);
$post(['step' => 'email', 'email_action' => 'skip']);
$post(['step' => 'store', 'store_name' => 'Rewards Store', 'default_currency' => 'sat']);
$post(['step' => 'onchain', 'onchain_action' => 'skip']);
$post(['step' => 'lightning', 'lightning_action' => 'skip']);
$r = $post(['step' => 'mints', 'mints_enabled' => '0']);

// The screen itself.
assert_eq('rewards', HttpDriver::wizardStep($r['body']), 'declining mints lands on the rewards screen');
assert_eq('(Optional) Enable Rewards', heading($r['body']), 'screen heading (emoji stripped)');
assert_true(str_contains($r['body'], '🎁 (Optional) Enable Rewards'), 'the heading carries its emoji');
assert_true(str_contains($r['body'], 'BareBits comes with a built-in rewards mechanism to provide a'), 'the operator-approved pitch');
assert_true(str_contains($r['body'], 'you can offer rewards and <em>still</em> be'), '"still" is emphasised');
assert_true(str_contains($r['body'], 'Users can spend their rewards by using them on a'), 'the spend explanation');
assert_true(str_contains($r['body'], 'id="rewards_enabled" name="rewards_enabled" value="1">'), 'the enable checkbox, unticked on a store that never answered');
assert_false(box_checked($r['body']), 'not pre-ticked');
assert_true(str_contains($r['body'], 'Enable rewards'), 'checkbox label');
assert_true(preg_match('/id="rewards_percent" name="rewards_percent" inputmode="decimal"[^>]*placeholder="1"/', $r['body']) === 1, 'the percent input is a decimal text field with placeholder 1');
assert_eq('', percent_value($r['body']), 'no percentage prefilled on a store that never answered');
assert_true(str_contains($r['body'], '1% on a 100 sat payment credits 1 sat'), 'help text carries the worked example');
assert_true(str_contains($r['body'], 'Rewards are experimental'), 'the experimental warning');
assert_true(preg_match('/<div class="warning">\s*⚠️/u', $r['body']) === 1, 'the warning is a .warning callout with the warning sign');
assert_true(str_contains($r['body'], 'name="rewards_action" value="save"'), 'Continue button');
assert_true(str_contains($r['body'], 'name="rewards_action" value="skip"'), 'Skip button');
assert_true(str_contains($r['body'], 'Skip for now'), 'Skip label');
assert_true(str_contains($r['body'], 'kept in <strong>sats</strong>'), 'a sat store shows its balances currency as "sats"');
assert_true(str_contains($r['body'], 'wizard-back-link'), 'the screen has a Back link');
assert_eq('mints', back_target($r['body']), 'Back leads to the last rail screen');
assert_false(str_contains($r['body'], '<input type="hidden" name="mode" value="add_store">'), 'no mode field on a first run');
// Counter: this rig's data dir is outside the web root, so the security
// screen is dropped — 14 screens without zeroconf/seed (terms analytics
// install_type password email store onchain lightning mints rewards
// wallet_tabs updates cron done), rewards the 10th.
assert_true(str_contains($r['body'], 'Step 10 of 14'), 'step counter counts the new screen: ' . (preg_match('/Step \d+ of \d+/', $r['body'], $m) ? $m[0] : '?'));
assert_true(Config::isSetupComplete(), 'the install is already complete on this screen');
assert_eq(['enabled' => false, 'percent' => 0.0, 'currency' => null], rewards_of('Rewards Store'), 'nothing written until answered');

// GET landing renders the same screen.
$r = $srv->get('/setup.php?step=rewards');
assert_eq(200, $r['status'], 'the completing session may GET the screen');
assert_eq('rewards', HttpDriver::wizardStep($r['body']), 'GET renders the rewards screen');
assert_null(error_banner($r['body']), 'no banner on a plain GET');

// Refused percentages: same screen, banner, typed value and tick kept,
// nothing written.
foreach ([
    ['0', 'between'],
    ['51', 'between'],
    ['abc', 'must be a number'],
    ['1.23456', 'at most 4 decimal places'],
    ['', 'must be a number'],
] as [$bad, $expect]) {
    $r = $srv->post('/setup.php', ['step' => 'rewards', 'rewards_enabled' => '1', 'rewards_percent' => $bad, 'rewards_action' => 'save', 'csrf_token' => $csrf]);
    assert_eq(200, $r['status'], "percent '$bad' re-renders (200)");
    assert_eq('rewards', HttpDriver::wizardStep($r['body']), "percent '$bad' re-renders the rewards screen");
    $banner = error_banner($r['body']);
    assert_not_null($banner, "percent '$bad' shows the error banner");
    assert_true(str_contains((string)$banner, $expect), "percent '$bad' banner says '$expect': $banner");
    assert_true(box_checked($r['body']), "percent '$bad': the tick is kept");
    assert_eq($bad, percent_value($r['body']), "percent '$bad' is echoed back for correction");
    assert_eq(['enabled' => false, 'percent' => 0.0, 'currency' => null], rewards_of('Rewards Store'), "percent '$bad' writes nothing");
}

// Skip leaves the settings untouched and hands over to wallet tabs.
$r = $post(['step' => 'rewards', 'rewards_enabled' => '1', 'rewards_percent' => '3', 'rewards_action' => 'skip']);
assert_eq('wallet_tabs', HttpDriver::wizardStep($r['body']), 'Skip hands over to the wallet-tabs screen');
assert_eq('Popular wallet tabs', heading($r['body']), 'and renders it');
assert_eq('rewards', back_target($r['body']), 'Back from wallet tabs leads to the rewards screen');
assert_true(str_contains($r['body'], 'Step 11 of 14'), 'wallet tabs is the 11th screen: ' . (preg_match('/Step \d+ of \d+/', $r['body'], $m) ? $m[0] : '?'));
assert_eq(['enabled' => false, 'percent' => 0.0, 'currency' => null], rewards_of('Rewards Store'), 'Skip writes nothing even with the box ticked');

// Back onto the screen and save enabled with a float percentage.
$r = $srv->get('/setup.php?step=rewards');
assert_eq('rewards', HttpDriver::wizardStep($r['body']), 'Back onto the rewards screen');
$r = $post(['step' => 'rewards', 'rewards_enabled' => '1', 'rewards_percent' => '2.5', 'rewards_action' => 'save']);
assert_eq('wallet_tabs', HttpDriver::wizardStep($r['body']), 'Continue hands over to the wallet-tabs screen');
assert_eq(['enabled' => true, 'percent' => 2.5, 'currency' => 'sat'], rewards_of('Rewards Store'), 'enabled at 2.5% in the store currency');
assert_true(Rewards::isEnabledForStore((string)store_id_of('Rewards Store')), 'the feature is live for the store');

// Back shows the saved answer, not the defaults.
$r = $srv->get('/setup.php?step=rewards');
assert_true(box_checked($r['body']), 'saved: ticked');
assert_eq('2.5', percent_value($r['body']), 'saved: the percentage in its natural spelling');
assert_true(str_contains($r['body'], 'kept in <strong>sats</strong>'), 'the pinned rewards currency is shown');

// Saving unticked disables; the posted percentage is ignored but the stored
// one is remembered for a later re-enable.
$r = $post(['step' => 'rewards', 'rewards_percent' => '2.5', 'rewards_action' => 'save']);
assert_eq('wallet_tabs', HttpDriver::wizardStep($r['body']), 'Continue with the box unticked advances too');
assert_eq(['enabled' => false, 'percent' => 2.5, 'currency' => 'sat'], rewards_of('Rewards Store'), 'unticked = off, percentage and currency remembered');
assert_false(Rewards::isEnabledForStore((string)store_id_of('Rewards Store')), 'the feature is off again');
$r = $srv->get('/setup.php?step=rewards');
assert_false(box_checked($r['body']), 'after disabling: unticked');
assert_eq('2.5', percent_value($r['body']), 'after disabling: the remembered percentage is still offered');

// An unticked save with garbage in the (ignored) percent field is fine.
$r = $post(['step' => 'rewards', 'rewards_percent' => 'abc', 'rewards_action' => 'save']);
assert_eq('wallet_tabs', HttpDriver::wizardStep($r['body']), 'the percent is ignored when the box is unticked');
assert_eq(['enabled' => false, 'percent' => 2.5, 'currency' => 'sat'], rewards_of('Rewards Store'), 'and nothing changed');

// Re-enable with a whole number, then finish the first run.
$r = $post(['step' => 'rewards', 'rewards_enabled' => '1', 'rewards_percent' => '1', 'rewards_action' => 'save']);
assert_eq(['enabled' => true, 'percent' => 1.0, 'currency' => 'sat'], rewards_of('Rewards Store'), 're-enabled at 1%');
$post(['step' => 'wallet_tabs', 'wallet_tabs_action' => 'skip']);
$post(['step' => 'updates', 'auto_update' => '0']);
$post(['step' => 'cron']);

// ---- post-completion gate -----------------------------------------------------
// A stranger is redirected; a signed-in admin's tail POST reaches the
// handler (which, without the wizard's store marker, refuses with the
// generic error rather than touching a store).
$srv->newSession();
$r = $srv->post('/setup.php', ['step' => 'rewards', 'rewards_enabled' => '1', 'rewards_percent' => '9', 'rewards_action' => 'save']);
assert_eq(302, $r['status'], 'anonymous step=rewards on a completed install is redirected');
assert_eq(1.0, rewards_of('Rewards Store')['percent'], 'the anonymous POST wrote nothing');
$r = $srv->post('/admin.php', ['action' => 'login', 'username' => 'admin', 'password' => 'rewards-pw-1234']);
assert_eq(200, $r['status'], 'admin login works: ' . $r['body']);
$adminCsrf = json_decode($r['body'], true)['csrfToken'] ?? null;
assert_not_null($adminCsrf, 'login response carries the csrf token');
$r = $srv->post('/setup.php', ['step' => 'rewards', 'rewards_enabled' => '1', 'rewards_percent' => '9', 'rewards_action' => 'save', 'csrf_token' => $adminCsrf]);
assert_eq(200, $r['status'], 'a signed-in admin may POST the tail screen (POST_COMPLETION)');
assert_true(str_contains((string)error_banner($r['body']), 'Store not found'), 'without a wizard store the handler refuses: ' . error_banner($r['body']));
assert_eq(1.0, rewards_of('Rewards Store')['percent'], 'and touched no store');
$r = $srv->get('/setup.php?step=rewards');
assert_eq(302, $r['status'], 'a logged-in GET of a tail screen still goes to admin (no wizard re-entry)');

// ---- add_store: between mints and wallet_tabs, form carries the mode ------------
$r = $srv->get('/setup.php?mode=add_store');
assert_eq(200, $r['status'], 'add_store opens');
assert_true(str_contains($r['body'], 'Step 1 of 6'), 'add_store counts the rewards screen: ' . (preg_match('/Step \d+ of \d+/', $r['body'], $m) ? $m[0] : '?'));
$csrf = HttpDriver::wizardCsrf($r['body']);
$apost = function (array $data) use ($srv, &$csrf): array {
    $data['csrf_token'] = $csrf;
    $data['mode'] = 'add_store';
    $r = $srv->post('/setup.php', $data);
    assert_true($r['status'] < 400, 'add_store ' . ($data['step'] ?? '?') . ' -> ' . $r['status']);
    return $r;
};
$apost(['step' => 'store', 'store_name' => 'Second Store', 'default_currency' => 'USD']);
$apost(['step' => 'onchain', 'onchain_action' => 'skip']);
$apost(['step' => 'lightning', 'lightning_action' => 'skip']);
$r = $apost(['step' => 'mints', 'mints_enabled' => '0']);
assert_eq(200, $r['status'], 'declining mints in add_store does not redirect');
assert_eq('rewards', HttpDriver::wizardStep($r['body']), 'add_store asks about rewards after mints');
assert_eq('(Optional) Enable Rewards', heading($r['body']), 'same screen in add_store');
assert_true(str_contains($r['body'], 'Step 5 of 6'), 'rewards is the fifth of six add_store screens');
assert_eq('mints', back_target($r['body']), 'add_store Back from rewards leads to mints');
assert_true(str_contains($r['body'], 'kept in <strong>USD</strong>'), 'a fiat store shows its currency code');
assert_true(str_contains($r['body'], '<input type="hidden" name="mode" value="add_store">'), 'the add_store form carries the mode field');
assert_eq(['enabled' => false, 'percent' => 0.0, 'currency' => null], rewards_of('Second Store'), 'not answered yet');

// Post it the way the browser does: the mode rides in the form field only.
$r = $srv->post('/setup.php', ['step' => 'rewards', 'rewards_enabled' => '1', 'rewards_percent' => '0.25', 'rewards_action' => 'save', 'mode' => 'add_store', 'csrf_token' => $csrf]);
assert_eq(200, $r['status'], 'answering rewards in add_store renders the next screen (no hand-off yet)');
assert_eq('wallet_tabs', HttpDriver::wizardStep($r['body']), 'wallet tabs follow rewards in add_store');
assert_true(str_contains($r['body'], 'Step 6 of 6'), 'wallet tabs is the last add_store screen');
assert_eq('rewards', back_target($r['body']), 'add_store Back from wallet tabs leads to rewards');
assert_true(str_contains($r['body'], '<input type="hidden" name="mode" value="add_store">'), 'the wallet-tabs form still carries the mode');
assert_eq(['enabled' => true, 'percent' => 0.25, 'currency' => 'USD'], rewards_of('Second Store'), 'the add_store answer is written to the new store in its own currency');
assert_eq(['enabled' => true, 'percent' => 1.0, 'currency' => 'sat'], rewards_of('Rewards Store'), 'the first store is untouched');

// wallet_tabs is still the hand-off.
$r = $srv->post('/setup.php', ['step' => 'wallet_tabs', 'wallet_tabs_action' => 'skip', 'mode' => 'add_store', 'csrf_token' => $csrf]);
assert_eq(302, $r['status'], 'answering the last add_store screen hands off to admin');
assert_true(str_contains((string)($r['headers']['location'] ?? ''), 'store_created='), 'redirect carries store_created: ' . ($r['headers']['location'] ?? ''));

echo "ok\n";
