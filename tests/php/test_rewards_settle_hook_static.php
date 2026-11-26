<?php
/**
 * Static guard: every place that fires the InvoiceSettled webhook must run
 * RewardsFlow::onSettled first, inside the same function.
 *
 * The webhook payload is frozen when the delivery row is enqueued
 * (WebhookSender::buildPayload reads the invoice row it is handed), and it
 * carries the `rewards` block — the reservation commit and the earn have to
 * be on the row by then. Settlement is spread over several paths
 * (Invoice::updateStatus, the direct-receive mark*Paid methods, the mint
 * paths, orphan recovery, the swap settlement context), so a new path that
 * forgets the hook would ship a payload without rewards and leave the
 * payer's reservation uncommitted. This test greps the source so that
 * omission fails CI rather than a customer.
 *
 * Rule: in the files below, every line that mentions 'InvoiceSettled' in
 * code (string literal; comment lines are skipped) must be preceded, within
 * the enclosing function, by a RewardsFlow::onSettled( call.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

$root = dirname(__DIR__, 2) . '/app/includes/';
$files = [
    $root . 'invoice.php',
    $root . 'swap/settlement_context.php',
];

$violations = [];
$checked = 0;
foreach ($files as $file) {
    assert_true(is_file($file), "source present: $file");
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $i => $line) {
        if (strpos($line, 'InvoiceSettled') === false) {
            continue;
        }
        $trim = ltrim($line);
        // Doc blocks and line comments talk about the webhook a lot.
        if ($trim === '' || $trim[0] === '*' || str_starts_with($trim, '//') || str_starts_with($trim, '/*')) {
            continue;
        }
        if (!preg_match("/['\"]InvoiceSettled['\"]/", $line)) {
            continue;
        }
        // Walk back to the enclosing function declaration.
        $fnLine = null;
        $fnName = null;
        for ($j = $i; $j >= 0; $j--) {
            if (preg_match('/^\s*(?:public|private|protected)?\s*(?:static\s+)?function\s+(\w+)\s*\(/', $lines[$j], $m)) {
                $fnLine = $j;
                $fnName = $m[1];
                break;
            }
        }
        assert_not_null($fnLine, basename($file) . ':' . ($i + 1) . ' is inside a function');
        $checked++;
        $hooked = false;
        for ($j = $fnLine; $j < $i; $j++) {
            if (strpos($lines[$j], 'RewardsFlow::onSettled(') !== false) {
                $hooked = true;
                break;
            }
        }
        if (!$hooked) {
            $violations[] = sprintf('%s:%d (%s) fires InvoiceSettled without RewardsFlow::onSettled before it',
                basename($file), $i + 1, $fnName);
        }
    }
}

// Sanity: the rule actually inspected the known settlement paths.
assert_true($checked >= 8, "found the settlement call sites (got $checked)");
foreach (['markLnAddressPaid', 'markNwcPaid', 'markStrikePaid', 'markNofferPaid', 'mintAndStoreTokens',
          'completeIssuedInvoice', 'recoverOrphanedInvoices', 'updateStatus'] as $fn) {
    $src = file_get_contents($root . 'invoice.php');
    assert_true(strpos($src, "function {$fn}(") !== false, "settlement path {$fn} still exists");
}
assert_true(strpos(file_get_contents($root . 'swap/settlement_context.php'), 'RewardsFlow::onSettled(') !== false,
    'swap settlement context runs the hook');

// The webhook payload builder actually carries the block.
$ws = file_get_contents($root . 'webhook_sender.php');
assert_true(strpos($ws, 'RewardsFlow::apiBlock(') !== false, 'webhook payload includes the rewards block');

if ($violations !== []) {
    fail(implode("\n", $violations));
}
echo "test_rewards_settle_hook_static: ok ($checked call sites)\n";
