<?php
/**
 * CashuPayServer - Public Self-Serve Invoice Page
 *
 * Lets a customer create AND pay their own invoice without an account, when the
 * merchant has enabled self-serve invoices for the store (site-wide default +
 * per-store override; see includes/selfserve.php). Reached at:
 *
 *   /pay/{storeId}            (router / built-in-server / rewrite mode)
 *   /pay.php?store={storeId}  (direct file access)
 *
 * GET  renders a small form: amount + currency (sat or the store's default
 *      fiat) + optional note.
 * POST validates the (untrusted) input, creates the invoice via Invoice::create
 *      and redirects to the regular /payment page for display + payment.
 *
 * Disabled / unknown stores return a generic 404 so we don't leak which store
 * IDs exist or which have self-serve turned on.
 */

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/selfserve.php';
require_once __DIR__ . '/includes/invoice.php';
require_once __DIR__ . '/includes/rates.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/urls.php';

// Bootstrap checks.
if (!Database::isInitialized() || !Config::isSetupComplete()) {
    http_response_code(503);
    echo 'Service unavailable';
    exit;
}

/** Render a generic 404 and stop. Used for unknown / disabled stores. */
function selfserve_not_found(): void {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$storeId = trim((string)($_GET['store'] ?? ''));
if ($storeId === '') {
    selfserve_not_found();
}

$store = Config::getStore($storeId);
if ($store === null || !SelfServe::isEnabledForStore($storeId)) {
    selfserve_not_found();
}

$storeName = $store['name'] ?? 'Payment';
$allowedCurrencies = SelfServe::allowedCurrencies($storeId);
$maxSats = SelfServe::effectiveMaxSats($storeId);

// Values echoed back into the form so a validation error doesn't wipe what the
// customer typed.
$formAmount = '';
$formCurrency = $allowedCurrencies[0];
$formNotes = '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAmount   = (string)($_POST['amount'] ?? '');
    $formCurrency = (string)($_POST['currency'] ?? $allowedCurrencies[0]);
    $formNotes    = (string)($_POST['notes'] ?? '');

    try {
        // Rate-limit creation per client IP — this endpoint is unauthenticated
        // and each success hits the mint / allocates an address.
        $ip = Security::getClientIp();
        if (!Security::checkRateLimit('selfserve_create', $ip, 10)) {
            throw new SelfServeValidationException('Too many requests. Please wait a minute and try again.');
        }

        $currency = SelfServe::validateCurrency($storeId, $formCurrency);
        $amount   = SelfServe::validateAmount($formAmount, $currency);
        $notes    = SelfServe::validateNotes($formNotes);

        // Convert to sats for the liquidity-cap check. A fiat conversion can
        // fail if no exchange rate is available; surface that cleanly.
        try {
            $sats = ExchangeRates::convertToSats($amount, $currency, 'sat');
        } catch (Throwable $e) {
            throw new SelfServeValidationException('Could not get an exchange rate right now. Please try again shortly.');
        }
        SelfServe::assertWithinMax($storeId, $sats);

        $options = [
            'amount'   => $amount,
            // Uppercase to match the Greenfield API / admin Request modal, which
            // store the currency code uppercased (e.g. SAT, USD).
            'currency' => strtoupper($currency),
        ];
        if ($notes !== '') {
            // itemDesc is the payer-facing note convention used across the app
            // (admin Request modal, payment.php display).
            $options['metadata'] = ['itemDesc' => $notes];
        }

        $invoice = Invoice::create($storeId, $options);

        // Hand off to the normal payment display page.
        header('Location: ' . Urls::payment($invoice['id']));
        exit;
    } catch (SelfServeValidationException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        // Never leak internal detail to the public page.
        error_log('[selfserve] invoice creation failed for store ' . $storeId . ': ' . $e->getMessage());
        $error = 'Could not create the invoice right now. Please try again later.';
    }
}

$hasFiat = count($allowedCurrencies) > 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay <?= htmlspecialchars($storeName) ?></title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚡</text></svg>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --bg-primary: #0f0f23;
            --bg-secondary: #1a1a2e;
            --bg-card: rgba(255, 255, 255, 0.05);
            --text-primary: #ffffff;
            --text-secondary: #a0aec0;
            --accent: #f7931a;
            --accent-hover: #e8820a;
            --error: #e53e3e;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, var(--bg-primary) 0%, var(--bg-secondary) 100%);
            min-height: 100vh;
            min-height: 100dvh;
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            width: 100%;
            max-width: 420px;
            text-align: center;
        }
        .logo { font-size: 2.5rem; margin-bottom: 0.25rem; }
        .merchant-name { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.25rem; }
        .subtitle { font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1.5rem; }
        .form-group { text-align: left; margin-bottom: 1.1rem; }
        .form-label { display: block; font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.4rem; }
        .amount-row { display: flex; gap: 0.5rem; }
        .form-input {
            width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            color: var(--text-primary);
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        .form-input:focus { outline: none; border-color: var(--accent); }
        select.form-input { width: auto; flex: 0 0 auto; }
        .form-help { font-size: 0.72rem; color: var(--text-secondary); margin-top: 0.35rem; }
        .btn {
            width: 100%;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 0.85rem 1rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 0.5rem;
        }
        .btn:hover { background: var(--accent-hover); }
        .error {
            background: rgba(229, 62, 62, 0.15);
            color: #feb2b2;
            border: 1px solid rgba(229, 62, 62, 0.3);
            border-radius: 12px;
            padding: 0.7rem 1rem;
            font-size: 0.85rem;
            margin-bottom: 1.1rem;
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">⚡</div>
        <div class="merchant-name"><?= htmlspecialchars($storeName) ?></div>
        <div class="subtitle">Enter an amount to generate a payment</div>

        <?php if ($error !== null): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group">
                <label class="form-label" for="amount">Amount</label>
                <div class="amount-row">
                    <input type="text" inputmode="decimal" class="form-input" id="amount" name="amount"
                           value="<?= htmlspecialchars($formAmount) ?>" placeholder="0" required>
                    <?php if ($hasFiat): ?>
                        <select class="form-input" id="currency" name="currency">
                            <?php foreach ($allowedCurrencies as $cur): ?>
                                <option value="<?= htmlspecialchars($cur) ?>"
                                    <?= strcasecmp($cur, $formCurrency) === 0 ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(strtoupper($cur)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" name="currency" value="<?= htmlspecialchars($allowedCurrencies[0]) ?>">
                        <span class="form-input" style="display:flex;align-items:center;color:var(--text-secondary);">SATS</span>
                    <?php endif; ?>
                </div>
                <div class="form-help">Maximum <?= number_format($maxSats) ?> sats per invoice.</div>
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Note <span style="opacity:0.6;">(optional)</span></label>
                <input type="text" class="form-input" id="notes" name="notes"
                       maxlength="<?= SelfServe::NOTES_MAX_LEN ?>"
                       value="<?= htmlspecialchars($formNotes) ?>" placeholder="What's this payment for?">
                <div class="form-help">Up to <?= SelfServe::NOTES_MAX_LEN ?> characters.</div>
            </div>

            <button type="submit" class="btn">Continue to payment</button>
        </form>
    </div>
</body>
</html>
