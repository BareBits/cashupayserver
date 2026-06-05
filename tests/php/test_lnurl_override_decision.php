<?php
/**
 * Pure-unit truth table for LnUrlReceive::shouldOverride. Two skip rules:
 *
 *   1. feesDue > FORCE          → override (reason=fees_force)
 *      Applies regardless of invoice size — when accumulated debt is too
 *      large, every invoice routes through the mint until the cron clears
 *      the debt.
 *
 *   2. feesDue > AMOUNT  AND  invoiceAmount < FORCE  → override (fees_threshold)
 *      Applies only when the invoice is small enough that pushing it through
 *      the mint to cover fees is the right call. Large invoices below the
 *      FORCE feesDue threshold still take the LNURL direct path so the merchant
 *      doesn't see huge payments stalled behind small fee debts.
 *
 * The pure function shape lets us walk the AMOUNT/FORCE corners exhaustively
 * without any DB or network setup.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/lnurl_receive.php';

$A = 5000;   // FEE_OVERRIDE_AMOUNT
$F = 20000;  // FEE_OVERRIDE_FORCE_AMOUNT

// --- No override: feesDue below the gate threshold ---------------------------
$d = LnUrlReceive::shouldOverride(0, 1000, $A, $F);
assert_eq(false, $d['override'], 'zero fees due: no override');
assert_eq('none', $d['reason']);

$d = LnUrlReceive::shouldOverride($A, 1000, $A, $F);
assert_eq(false, $d['override'], 'feesDue == AMOUNT (not strictly greater): no override');
assert_eq('none', $d['reason']);

// --- fees_threshold: feesDue > AMOUNT, invoice small -------------------------
// feesDue (5001) > AMOUNT (5000) AND invoice (1000) < FORCE (20000) → skip LNURL.
$d = LnUrlReceive::shouldOverride($A + 1, 1000, $A, $F);
assert_eq(true, $d['override'], 'feesDue > AMOUNT + small invoice: gate fires');
assert_eq('fees_threshold', $d['reason']);

// Edge: invoice exactly at FORCE — not strictly less, so threshold rule
// doesn't apply. And force rule needs feesDue > FORCE, which we don't have.
$d = LnUrlReceive::shouldOverride($A + 1, $F, $A, $F);
assert_eq(false, $d['override'], 'feesDue just over AMOUNT, invoice == FORCE: pass');
assert_eq('none', $d['reason']);

// Edge: invoice just below FORCE — threshold rule applies.
$d = LnUrlReceive::shouldOverride($A + 1, $F - 1, $A, $F);
assert_eq(true, $d['override'], 'feesDue just over AMOUNT, invoice just under FORCE: gate fires');
assert_eq('fees_threshold', $d['reason']);

// Edge: feesDue between AMOUNT and FORCE, large invoice → LNURL wins.
// Large invoice carries enough revenue that we don't need to claw fees back
// from THIS payment; let the operator take it direct.
$d = LnUrlReceive::shouldOverride($A + 5000, 100000, $A, $F);
assert_eq(false, $d['override'], 'feesDue > AMOUNT but large invoice: pass through');
assert_eq('none', $d['reason']);

// --- fees_force: feesDue > FORCE — every invoice routes via mint -------------
$d = LnUrlReceive::shouldOverride($F + 1, 1000, $A, $F);
assert_eq(true, $d['override'], 'feesDue > FORCE + tiny invoice: force fires');
assert_eq('fees_force', $d['reason']);

// Even huge invoices forced through the mint when debt is over FORCE.
$d = LnUrlReceive::shouldOverride($F + 1, 1_000_000_000, $A, $F);
assert_eq(true, $d['override'], 'feesDue > FORCE + huge invoice: force fires');
assert_eq('fees_force', $d['reason']);

// --- Edge: feesDue == FORCE → not strictly greater, no force fire. -----------
// But it IS > AMOUNT, so threshold rule applies if invoice < FORCE.
$d = LnUrlReceive::shouldOverride($F, 1000, $A, $F);
assert_eq(true, $d['override'], 'feesDue == FORCE, small invoice: threshold fires');
assert_eq('fees_threshold', $d['reason']);

$d = LnUrlReceive::shouldOverride($F, $F + 1000, $A, $F);
assert_eq(false, $d['override'], 'feesDue == FORCE, large invoice: pass');
assert_eq('none', $d['reason']);

// --- Custom thresholds (verify the function honours its parameters, not the
// global constants). Operator who has bumped AMOUNT to 50k.
$d = LnUrlReceive::shouldOverride(40000, 1000, 50000, 100000);
assert_eq(false, $d['override'], 'custom thresholds: feesDue below custom AMOUNT');

$d = LnUrlReceive::shouldOverride(60000, 1000, 50000, 100000);
assert_eq(true, $d['override'], 'custom thresholds: feesDue over custom AMOUNT + small invoice');
assert_eq('fees_threshold', $d['reason']);

$d = LnUrlReceive::shouldOverride(150000, 1000, 50000, 100000);
assert_eq(true, $d['override'], 'custom thresholds: feesDue over custom FORCE');
assert_eq('fees_force', $d['reason']);

echo "test_lnurl_override_decision: ok\n";
