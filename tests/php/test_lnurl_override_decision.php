<?php
/**
 * Pure-unit truth table for LnUrlReceive::shouldOverride. Single rule:
 *
 *   invoiceAmount < feesDue  →  override (reason=fees_due)
 *
 * The override only fires when the invoice on its own can't cover the
 * accumulated upstream/dev/hosting fees the operator owes. Larger invoices
 * take the LNURL direct path even with some fees outstanding — the next
 * small invoice (or the cron) will catch the debt up.
 *
 * The pure function shape lets us walk the corners exhaustively without
 * any DB or network setup.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/lnurl_receive.php';

// --- No fees owed: every invoice passes through to LNURL ---------------------
$d = LnUrlReceive::shouldOverride(0, 1);
assert_eq(false, $d['override'], 'zero fees, tiny invoice: no override');
assert_eq('none', $d['reason']);

$d = LnUrlReceive::shouldOverride(0, 1_000_000);
assert_eq(false, $d['override'], 'zero fees, large invoice: no override');
assert_eq('none', $d['reason']);

// --- Invoice >= fees due: LNURL path wins ------------------------------------
// The invoice carries enough sats that even if we routed it via the mint and
// settled the full owed amount, there'd still be revenue for the merchant.
// Defer to the cron / next small invoice instead.
$d = LnUrlReceive::shouldOverride(5000, 5000);
assert_eq(false, $d['override'], 'invoice == fees due: not strictly less, pass');
assert_eq('none', $d['reason']);

$d = LnUrlReceive::shouldOverride(5000, 5001);
assert_eq(false, $d['override'], 'invoice just over fees due: pass');
assert_eq('none', $d['reason']);

$d = LnUrlReceive::shouldOverride(25_000, 1_000_000);
assert_eq(false, $d['override'], 'large invoice, modest fees due: pass');
assert_eq('none', $d['reason']);

// --- Invoice < fees due: override fires --------------------------------------
$d = LnUrlReceive::shouldOverride(5000, 4999);
assert_eq(true, $d['override'], 'invoice just under fees due: override');
assert_eq('fees_due', $d['reason']);

$d = LnUrlReceive::shouldOverride(5000, 1);
assert_eq(true, $d['override'], 'tiny invoice with fees owed: override');
assert_eq('fees_due', $d['reason']);

$d = LnUrlReceive::shouldOverride(1_000_000, 999_999);
assert_eq(true, $d['override'], 'huge fees due, invoice just under: override');
assert_eq('fees_due', $d['reason']);

// --- Edge: zero-sat invoice with no fees owed --------------------------------
// Degenerate but should not trip the gate — feesDue must be > 0 for the
// override to fire (otherwise routing a 0-amount invoice through the mint
// achieves nothing).
$d = LnUrlReceive::shouldOverride(0, 0);
assert_eq(false, $d['override'], 'zero invoice, zero fees: no override');
assert_eq('none', $d['reason']);

echo "test_lnurl_override_decision: ok\n";
