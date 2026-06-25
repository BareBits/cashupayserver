<?php
/**
 * CashuPayServer - Payment Page
 *
 * Customer-facing payment page with Lightning QR code.
 */

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/invoice.php';
require_once __DIR__ . '/includes/background.php';
require_once __DIR__ . '/includes/urls.php';
require_once __DIR__ . '/includes/cart.php';

// Check setup
if (!Database::isInitialized() || !Config::isSetupComplete()) {
    http_response_code(503);
    echo 'Service unavailable';
    exit;
}

// Get invoice ID
$invoiceId = $_GET['id'] ?? '';
if (empty($invoiceId)) {
    http_response_code(400);
    echo 'Invoice ID required';
    exit;
}

// CLINK noffer receipt endpoint: the payment page holds a live Nostr
// subscription (native WebSocket) for the merchant's kind-21001 payment
// receipt and forwards the raw signed event here. We verify the merchant's
// Schnorr signature + decrypt server-side before settling, so the browser can
// relay the receipt but cannot forge a payment. No CSRF token — like the
// receipt-email endpoint below, there's no auth context to abuse, and trust
// comes entirely from the merchant's signature.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'noffer_receipt') {
    header('Content-Type: application/json');
    $event = json_decode((string)($_POST['event'] ?? ''), true);
    if (!is_array($event)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid event']);
        exit;
    }
    $settled = Invoice::settleNofferFromReceiptEvent($invoiceId, $event);
    echo json_encode(['settled' => $settled]);
    exit;
}

// JSON polling branch runs first so we can keep its work tight. The
// customer's browser polls this every 2 s; firing the full Background
// fan-out on every poll burns mint API budget and fights cron for the
// same rows (concurrency race in mintAndStoreTokens). pollSingleQuote
// stays — it's the only thing that drives settlement for THIS invoice
// when no external cron is running, and skipping it stalls customers
// on the "Waiting for payment" screen until the next cron tick (which
// is never, for shops that haven't wired cron up).
if (isset($_GET['json'])) {
    Invoice::pollSingleQuote($invoiceId);
    header('Content-Type: application/json');
    $invoice = Invoice::getById($invoiceId);
    if ($invoice === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Invoice not found']);
        exit;
    }
    echo json_encode([
        'status' => $invoice['status'],
        'additionalStatus' => $invoice['additional_status'],
    ]);
    exit;
}

// HTML render: poll this one quote so the initial page reflects current
// state, then kick background work for everything else.
Invoice::pollSingleQuote($invoiceId);
Background::trigger();

// Get invoice
$invoice = Invoice::getById($invoiceId);
if ($invoice === null) {
    http_response_code(404);
    echo 'Invoice not found';
    exit;
}

// Get store name for display
$store = Database::fetchOne(
    "SELECT name FROM stores WHERE id = ?",
    [$invoice['store_id']]
);
$storeName = $store['name'] ?? 'Payment';

// Public POST endpoint: payer enters their email on the payment-complete
// modal. We persist the email + newsletter opt-in on the invoice (always, for
// the merchant's customer list) and, when payer receipts are enabled, also
// queue a confirmation email. No CSRF token because there's no auth context to
// abuse — the page itself is unauthenticated. The per-invoice send cap inside
// NotificationSender bounds receipt-email abuse; the email column is a single
// overwrite, so capture itself is harmless to spam.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_receipt') {
    require_once __DIR__ . '/includes/notification_sender.php';
    header('Content-Type: application/json');

    if ($invoice['status'] !== 'Settled') {
        http_response_code(400);
        echo json_encode(['error' => 'Invoice is not paid yet.']);
        exit;
    }

    $email = trim((string)($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Please enter a valid email address.']);
        exit;
    }
    $newsletterOptIn = ($_POST['newsletter'] ?? '0') === '1' ? 1 : 0;

    // Persist the customer's email + newsletter choice on the invoice. Last
    // submission wins — the payer may resubmit with a changed choice. This is
    // decoupled from receipt sending so it works even without SMTP/receipts.
    Database::update(
        'invoices',
        ['customer_email' => $email, 'newsletter_opt_in' => $newsletterOptIn],
        'id = ?',
        [$invoice['id']]
    );

    // Queue a payment-confirmation email only when receipts are offered. The
    // per-invoice cap still bounds how many receipts can be sent.
    $receiptQueued = false;
    if (NotificationSender::isPayerReceiptOffered()) {
        if (NotificationSender::payerReceiptCountForInvoice($invoice['id'])
                >= NotificationSender::PAYER_RECEIPT_MAX_PER_INVOICE) {
            http_response_code(429);
            echo json_encode(['error' => 'Receipt limit reached for this invoice.']);
            exit;
        }
        $receiptQueued = NotificationSender::queuePayerReceipt($invoice, $email);
        if (!$receiptQueued) {
            // Race: the cap was reached between the check above and the call.
            http_response_code(429);
            echo json_encode(['error' => 'Receipt limit reached for this invoice.']);
            exit;
        }
    }

    echo json_encode(['success' => true, 'receiptQueued' => $receiptQueued]);
    exit;
}

// Get checkout config
$checkoutConfig = $invoice['checkout_config'] ? json_decode($invoice['checkout_config'], true) : [];
$redirectUrl = $checkoutConfig['redirectURL'] ?? null;
$redirectAuto = $checkoutConfig['redirectAutomatically'] ?? true;

// Pull the payer-facing note out of the invoice's metadata. itemDesc is what
// admin.php's "Create invoice" wizard stores for the memo field; other callers
// of the API may use the same key. Anything else in metadata is internal.
$invoiceMetadata = $invoice['metadata'] ? json_decode($invoice['metadata'], true) : null;
$invoiceNote = is_array($invoiceMetadata) ? trim((string)($invoiceMetadata['itemDesc'] ?? '')) : '';

// Decide whether to render the payer-receipt form. The check is composed of
// site-wide master switch + per-type toggle + SMTP. When false, the success
// modal shows a "screenshot this page" fallback instead.
require_once __DIR__ . '/includes/notification_sender.php';
$payerReceiptOffered = NotificationSender::isPayerReceiptOffered();

// Resolve the newsletter checkbox's initial state for this store (per-store
// override → site-wide default). The email/newsletter capture form is shown
// regardless of whether receipts are offered.
$newsletterDefaultChecked = Config::getNewsletterDefaultChecked($invoice['store_id']);

// Format amount for display - use store's mint unit
$mintUnit = Config::getStoreMintUnit($invoice['store_id']);
$displayAmount = $invoice['amount'] . ' ' . strtoupper($invoice['currency']);

// Show secondary amount info based on currency relationships
$requestCurrency = strtoupper($invoice['currency']);
$mintUnitUpper = strtoupper($mintUnit);

if ($invoice['amount_sats'] && $requestCurrency !== 'SAT' && $requestCurrency !== 'SATS') {
    if ($mintUnitUpper === 'SAT') {
        // Mint uses sats - show sat equivalent
        $displayAmount .= ' (' . number_format($invoice['amount_sats']) . ' sats)';
    } elseif ($requestCurrency !== $mintUnitUpper) {
        // Different currency than mint unit - show mint unit equivalent
        // For fiat mints (EUR, USD), amount_sats is actually in cents
        $mintAmount = $invoice['amount_sats'] / 100; // Convert cents to main unit
        $displayAmount .= ' (' . number_format($mintAmount, 2) . ' ' . $mintUnitUpper . ')';
    }
    // If request currency matches mint unit, no secondary display needed
}

$baseUrl = Config::getBaseUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Pay Invoice - <?= htmlspecialchars($storeName) ?></title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚡</text></svg>">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg-primary: #0f0f23;
            --bg-secondary: #1a1a2e;
            --bg-card: rgba(255, 255, 255, 0.05);
            --text-primary: #ffffff;
            --text-secondary: #a0aec0;
            --accent: #f7931a;
            --accent-hover: #e8820a;
            --success: #48bb78;
            --error: #e53e3e;
            --warning: #ed8936;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, var(--bg-primary) 0%, var(--bg-secondary) 100%);
            min-height: 100vh;
            min-height: 100dvh;
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
        }

        .container {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 0;
            width: 100%;
        }

        .payment-card {
            background: var(--bg-card);
            padding: 2rem;
            width: 100%;
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(20px);
            text-align: center;
        }

        /* Keep the checkout content readable on wide screens while the card
           itself fills the full viewport. */
        .payment-card > * {
            width: 100%;
            max-width: 480px;
        }

        .logo {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        .merchant-name {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
        }

        .amount {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .amount-secondary {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
        }

        .qr-container {
            background: #fff;
            border-radius: 16px;
            padding: 1rem;
            margin: 0 auto 1.5rem;
            display: inline-block;
        }

        .qr-container svg,
        .qr-container canvas {
            display: block;
            width: 220px;
            height: 220px;
        }

        @media (max-width: 360px) {
            .qr-container svg,
            .qr-container canvas {
                width: 180px;
                height: 180px;
            }
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
        }

        .status-badge.new {
            background: rgba(247, 147, 26, 0.2);
            color: var(--accent);
        }

        .status-badge.processing {
            background: rgba(66, 153, 225, 0.2);
            color: #63b3ed;
        }

        .status-badge.settled {
            background: rgba(72, 187, 120, 0.2);
            color: var(--success);
        }

        .status-badge.expired {
            background: rgba(229, 62, 62, 0.2);
            color: var(--error);
        }

        .status-badge .spinner {
            width: 14px;
            height: 14px;
            border: 2px solid transparent;
            border-top-color: currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .status-badge .checkmark {
            width: 14px;
            height: 14px;
        }

        .invoice-input {
            width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            color: var(--text-primary);
            font-size: 0.75rem;
            font-family: monospace;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s;
            word-break: break-all;
        }

        /* Lightning <-> on-chain method tabs (shown only when both methods are
           configured for the invoice). */
        .method-tabs {
            display: flex;
            gap: 0.5rem;
            margin: 0 0 1rem 0;
            background: rgba(0, 0, 0, 0.25);
            border-radius: 12px;
            padding: 0.25rem;
        }
        .method-tab {
            flex: 1;
            background: transparent;
            color: var(--text-secondary);
            border: none;
            padding: 0.6rem 0.75rem;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
        }
        .method-tab.active {
            background: var(--accent);
            color: var(--text-primary);
        }
        .method-tab:hover:not(.active) {
            color: var(--text-primary);
        }
        .method-block.hidden {
            display: none !important;
        }

        .invoice-input:hover {
            border-color: var(--accent);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            padding: 1rem 1.5rem;
            background: var(--accent);
            color: var(--text-primary);
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            text-decoration: none;
            margin-top: 1rem;
        }

        .btn:hover {
            background: var(--accent-hover);
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            margin-top: 0.5rem;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        .timer {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 1rem;
        }

        .timer.urgent {
            color: var(--warning);
        }

        .timer.expired {
            color: var(--error);
        }

        /* "Pay with [logos] or any Bitcoin wallet" row.
           Each SVG carries its own brand fill; the row only sets size. */
        .payment-methods {
            margin-top: 0.85rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 0.35rem 0.5rem;
            font-size: 0.75rem;
            color: var(--text-secondary);
            line-height: 1.4;
        }
        .payment-methods .pm-label {
            white-space: nowrap;
        }
        .payment-methods .pm-logos {
            display: inline-flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .pm-logo {
            display: inline-block;
            height: 44px;
            width: auto;
            vertical-align: middle;
        }
        .pm-logo.pm-strike { border-radius: 10px; }
        /* Venmo and PayPal use thin blue wordmarks that disappear against
           the dark card; give them white backgrounds the way each brand
           presents itself in its own marketing. */
        .pm-logo.pm-card {
            background: #fff;
            padding: 6px 8px;
            border-radius: 8px;
            box-sizing: border-box;
        }
        /* On the Lightning view, hide brands that don't natively send LN. */
        .payment-methods[data-method="lightning"] .pm-no-lightning {
            display: none;
        }

        .barebits-notice {
            margin-top: 0.65rem;
            font-size: 1.05rem;
            color: var(--text-secondary);
            opacity: 0.85;
            line-height: 1.45;
        }
        .barebits-notice a {
            color: var(--text-secondary);
            text-decoration: underline;
        }
        .barebits-notice a:hover {
            color: var(--text-primary);
        }

        .success-animation {
            display: none;
            flex-direction: column;
            align-items: center;
            padding: 2rem 0;
        }

        .success-animation.show {
            display: flex;
        }

        .success-icon {
            width: 80px;
            height: 80px;
            background: var(--success);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            animation: popIn 0.5s ease;
        }

        @keyframes popIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon svg {
            width: 40px;
            height: 40px;
            color: white;
        }

        .hidden {
            display: none;
        }

        /* Invoice ID + note block shown on both pending and success screens.
           Compact, monospace for the ID, wraps the note. */
        .invoice-details {
            margin: 1rem 0;
            padding: 0.75rem 1rem;
            background: rgba(0, 0, 0, 0.25);
            border-radius: 10px;
            text-align: left;
            font-size: 0.8rem;
            color: var(--text-secondary);
            line-height: 1.5;
        }
        .invoice-details .label {
            font-weight: 600;
            color: var(--text-primary);
            margin-right: 0.4rem;
        }
        .invoice-details .invoice-id-value {
            font-family: monospace;
            word-break: break-all;
        }
        .invoice-details .invoice-note-value {
            word-break: break-word;
        }
        .invoice-details > div + div {
            margin-top: 0.35rem;
        }

        /* Payer-receipt opt-in form on the success modal. */
        .receipt-form {
            margin: 1rem 0 0;
            text-align: left;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        .receipt-form .receipt-prompt {
            margin-bottom: 0.5rem;
            text-align: center;
            color: var(--text-primary);
        }
        .receipt-form .receipt-prompt small {
            display: block;
            margin-top: 0.15rem;
            color: var(--text-secondary);
            font-size: 0.75rem;
        }
        .receipt-form .form-input {
            width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            color: var(--text-primary);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        .receipt-form .form-input:focus {
            outline: none;
            border-color: var(--accent);
        }
        .receipt-form .receipt-newsletter {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0 0 0.6rem;
            color: var(--text-secondary);
            font-size: 0.82rem;
            cursor: pointer;
        }
        .receipt-form .receipt-newsletter input {
            width: 1rem;
            height: 1rem;
            accent-color: var(--accent);
            cursor: pointer;
            flex-shrink: 0;
        }
        .receipt-form .receipt-skip {
            display: block;
            margin-top: 0.75rem;
            text-align: center;
            color: var(--text-secondary);
            text-decoration: underline;
            font-size: 0.8rem;
            cursor: pointer;
            background: none;
            border: none;
            width: 100%;
        }
        .receipt-form .receipt-status {
            margin-top: 0.5rem;
            text-align: center;
            font-size: 0.8rem;
        }
        .receipt-form .receipt-status.error {
            color: var(--error);
        }
        .receipt-form .receipt-status.success {
            color: var(--success);
        }
        .receipt-fallback {
            margin: 1rem 0 0;
            font-size: 0.8rem;
            color: var(--text-secondary);
            text-align: center;
        }

        .footer {
            padding: 1rem;
            text-align: center;
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .footer a {
            color: var(--text-secondary);
            text-decoration: none;
        }

        .copy-toast {
            position: fixed;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 0.875rem;
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .copy-toast.show {
            transform: translateX(-50%) translateY(0);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="payment-card">
            <div class="logo">&#9889;</div>
            <div class="merchant-name"><?= htmlspecialchars($storeName) ?></div>

            <?php
            $hasLightning = !empty($invoice['bolt11']);
            $hasOnchain = !empty($invoice['onchain_address']);
            $onchainSat = (int)($invoice['onchain_amount_sat'] ?? 0);
            $onchainBtc = $onchainSat > 0
                ? rtrim(rtrim(bcdiv((string)$onchainSat, '100000000', 8), '0'), '.')
                : '0';
            if ($onchainBtc === '') { $onchainBtc = '0'; }
            $bip21 = $hasOnchain
                ? 'bitcoin:' . $invoice['onchain_address'] . '?amount=' . $onchainBtc
                : '';
            $shortOnchainAddr = $hasOnchain
                ? htmlspecialchars($invoice['onchain_address'])
                : '';

            // Cashu ecash: any configured store can be paid by presenting a
            // token. The request is built without contacting the mint, so it
            // works even while the server is mint-offline (the whole point of
            // offline acceptance). The NUT-18 request id is the invoice id so
            // the receipt attaches back to THIS invoice.
            $hasCashu = false;
            $cashuRequest = '';
            if (Config::isStoreConfigured($invoice['store_id'])
                && in_array($invoice['status'], ['New', 'Provisional'], true)
                && (int)($invoice['amount_sats'] ?? 0) > 0) {
                try {
                    $cMintUrl = Config::getStoreMintUrl($invoice['store_id']);
                    $cUnit = Config::getStoreMintUnit($invoice['store_id']);
                    $cWallet = new \Cashu\Wallet($cMintUrl, $cUnit);
                    $cPr = $cWallet->createHttpPaymentRequest(
                        (int)$invoice['amount_sats'],
                        Urls::receive(),
                        $invoiceNote !== '' ? $invoiceNote : null
                    );
                    $cPr->id = $invoice['id'];
                    $cashuRequest = $cPr->serialize();
                    $hasCashu = true;
                } catch (\Throwable $e) {
                    $hasCashu = false;
                }
            }
            $methodCount = ($hasLightning ? 1 : 0) + ($hasOnchain ? 1 : 0) + ($hasCashu ? 1 : 0);
            ?>
            <div id="payment-pending" class="<?= $invoice['status'] !== 'New' ? 'hidden' : '' ?>">
                <div class="amount"><?= htmlspecialchars($displayAmount) ?></div>

                <?php
                // Itemized cart breakdown (when this invoice came from the cart
                // checkout). Each line shows sats with the store-currency
                // equivalent in parentheses, per the store's display currency.
                $lineItems = Cart::getItems($invoice['id']);
                if (!empty($lineItems)):
                ?>
                <div style="margin:0 0 1rem 0; text-align:left; border:1px solid rgba(0,0,0,0.08); border-radius:12px; overflow:hidden;">
                    <?php foreach ($lineItems as $li):
                        $itype = (string)($li['image_type'] ?? 'none');
                        $ival = (string)($li['image_value'] ?? '');
                        $paren = (!empty($li['display_amount']) && strtoupper((string)$li['display_currency']) !== 'SAT')
                            ? ' (' . htmlspecialchars($li['display_amount']) . ' ' . htmlspecialchars(strtoupper((string)$li['display_currency'])) . ')'
                            : '';
                    ?>
                    <div style="display:flex; align-items:center; gap:0.6rem; padding:0.55rem 0.7rem; border-bottom:1px solid rgba(0,0,0,0.06);">
                        <div style="width:38px; height:38px; border-radius:8px; background:rgba(0,0,0,0.06); display:flex; align-items:center; justify-content:center; font-size:1.3rem; overflow:hidden; flex-shrink:0;"><?php
                            if ($itype === 'upload' && $ival !== '') {
                                echo '<img src="' . htmlspecialchars(Product::imageUrl($ival)) . '" alt="" style="width:100%;height:100%;object-fit:cover;">';
                            } elseif ($itype === 'emoji' && $ival !== '') {
                                echo htmlspecialchars($ival);
                            } else {
                                echo "\u{1F4E6}";
                            }
                        ?></div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-weight:600; font-size:0.92rem;"><?= htmlspecialchars($li['title']) ?></div>
                            <div style="font-size:0.8rem; color:#666;">&times;<?= (int)$li['quantity'] ?></div>
                        </div>
                        <div style="text-align:right; font-size:0.85rem; white-space:nowrap;"><?= number_format((int)$li['amount_sats']) ?> sats<span style="color:#666;"><?= $paren ?></span></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="status-badge new">
                    <div class="spinner"></div>
                    Waiting for payment
                </div>

                <div class="invoice-details">
                    <div><span class="label">Invoice:</span><span class="invoice-id-value"><?= htmlspecialchars($invoice['id']) ?></span></div>
                    <?php if ($invoiceNote !== ''): ?>
                    <div><span class="label">Note:</span><span class="invoice-note-value"><?= htmlspecialchars($invoiceNote) ?></span></div>
                    <?php endif; ?>
                </div>

                <?php
                $firstMethod = $hasLightning ? 'lightning' : ($hasOnchain ? 'onchain' : 'cashu');
                if ($methodCount >= 2): ?>
                <div class="method-tabs" role="tablist">
                    <?php if ($hasLightning): ?>
                    <button type="button" class="method-tab <?= $firstMethod === 'lightning' ? 'active' : '' ?>" data-method="lightning" role="tab">Lightning</button>
                    <?php endif; ?>
                    <?php if ($hasOnchain): ?>
                    <button type="button" class="method-tab <?= $firstMethod === 'onchain' ? 'active' : '' ?>" data-method="onchain" role="tab">On-chain</button>
                    <?php endif; ?>
                    <?php if ($hasCashu): ?>
                    <button type="button" class="method-tab <?= $firstMethod === 'cashu' ? 'active' : '' ?>" data-method="cashu" role="tab">Cashu</button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasLightning): ?>
                <div class="method-block" data-method-block="lightning">
                    <div class="qr-container" id="qr-lightning"></div>
                    <div class="invoice-input" data-copy="<?= htmlspecialchars($invoice['bolt11']) ?>">
                        <?= htmlspecialchars(substr($invoice['bolt11'], 0, 40) . '...' . substr($invoice['bolt11'], -10)) ?>
                    </div>
                    <a href="lightning:<?= htmlspecialchars($invoice['bolt11']) ?>" class="btn">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                        </svg>
                        Open in Wallet
                    </a>
                    <button type="button" class="btn btn-secondary" data-copy="<?= htmlspecialchars($invoice['bolt11']) ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                        </svg>
                        Copy Invoice
                    </button>
                </div>
                <?php endif; ?>

                <?php if ($hasOnchain): ?>
                <div class="method-block <?= $hasLightning ? 'hidden' : '' ?>" data-method-block="onchain">
                    <div class="qr-container" id="qr-onchain"></div>
                    <div class="invoice-input" data-copy="<?= htmlspecialchars($bip21) ?>">
                        <?= $shortOnchainAddr ?>
                        <div style="margin-top:.35rem;font-size:.85rem;color:var(--text-secondary)">
                            <?= number_format($onchainSat) ?> sat
                        </div>
                    </div>
                    <a href="<?= htmlspecialchars($bip21) ?>" class="btn">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <path d="M9.5 8h4a2.5 2.5 0 0 1 0 5h-4v-5zm0 5h4.5a2.5 2.5 0 0 1 0 5h-4.5v-5z"></path>
                        </svg>
                        Open in Wallet
                    </a>
                    <button type="button" class="btn btn-secondary" data-copy="<?= htmlspecialchars($bip21) ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                        </svg>
                        Copy Address
                    </button>
                </div>
                <?php endif; ?>

                <?php if ($hasCashu): ?>
                <div class="method-block <?= $firstMethod === 'cashu' ? '' : 'hidden' ?>" data-method-block="cashu">
                    <div class="qr-container" id="qr-cashu"></div>
                    <div class="invoice-input" data-copy="<?= htmlspecialchars($cashuRequest) ?>">
                        <?= htmlspecialchars(substr($cashuRequest, 0, 40) . '…') ?>
                    </div>
                    <button type="button" class="btn btn-secondary" data-copy="<?= htmlspecialchars($cashuRequest) ?>">
                        Copy Cashu Request
                    </button>
                    <div style="margin-top:0.75rem; text-align:left;">
                        <label style="font-size:0.8rem; color:var(--text-secondary);">…or paste a Cashu token to pay</label>
                        <textarea id="cashu-token-input" rows="3" placeholder="cashuB..."
                                  style="width:100%; margin-top:0.35rem; padding:0.6rem; border-radius:8px; border:1px solid var(--border); background:rgba(0,0,0,0.2); color:inherit; font-size:0.75rem; resize:vertical;"></textarea>
                        <button type="button" class="btn" id="cashu-submit" style="margin-top:0.5rem;">Pay with Cashu token</button>
                        <div id="cashu-result" style="margin-top:0.6rem; font-size:0.82rem;"></div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="timer" id="timer"></div>

                <?php
                $initialMethod = $firstMethod;
                $imgBase = Urls::images('payment-methods/');
                ?>
                <div class="payment-methods" id="payment-methods" data-method="<?= $initialMethod ?>">
                    <span class="pm-label">Pay with</span>
                    <span class="pm-logos">
                        <img class="pm-logo" src="<?= htmlspecialchars($imgBase) ?>cashapp.svg" alt="Cash App" title="Cash App">
                        <img class="pm-logo pm-strike" src="<?= htmlspecialchars($imgBase) ?>strike.png" alt="Strike" title="Strike">
                        <img class="pm-logo" src="<?= htmlspecialchars($imgBase) ?>coinbase.svg" alt="Coinbase" title="Coinbase">
                        <img class="pm-logo" src="<?= htmlspecialchars($imgBase) ?>kraken.svg" alt="Kraken" title="Kraken">
                        <img class="pm-logo pm-card pm-no-lightning" src="<?= htmlspecialchars($imgBase) ?>venmo.svg" alt="Venmo" title="Venmo">
                        <img class="pm-logo pm-card pm-no-lightning" src="<?= htmlspecialchars($imgBase) ?>paypal.svg" alt="PayPal" title="PayPal">
                    </span>
                    <span class="pm-label">or any Bitcoin wallet</span>
                </div>

                <div class="barebits-notice">
                    Payments by <a href="https://getbarebits.com" target="_blank" rel="noopener">BareBits</a>. Powered by the Bitcoin Lightning Network &mdash; pay with any Lightning or Cashu wallet.
                </div>
            </div>

            <div id="payment-processing" class="<?= $invoice['status'] !== 'Processing' ? 'hidden' : '' ?>">
                <div class="status-badge processing">
                    <div class="spinner"></div>
                    Processing payment...
                </div>
                <p style="color: var(--text-secondary); margin-top: 1rem;">
                    Payment detected. Please wait...
                </p>
            </div>

            <div id="payment-success" class="success-animation <?= $invoice['status'] === 'Settled' ? 'show' : '' ?>">
                <div class="success-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
                <div class="amount"><?= htmlspecialchars($displayAmount) ?></div>
                <div class="status-badge settled">
                    Payment Complete
                </div>
                <div class="invoice-details">
                    <div><span class="label">Invoice:</span><span class="invoice-id-value"><?= htmlspecialchars($invoice['id']) ?></span></div>
                    <?php if ($invoiceNote !== ''): ?>
                    <div><span class="label">Note:</span><span class="invoice-note-value"><?= htmlspecialchars($invoiceNote) ?></span></div>
                    <?php endif; ?>
                </div>
                <form class="receipt-form" id="receipt-form" data-receipt-offered="<?= $payerReceiptOffered ? '1' : '0' ?>" novalidate>
                    <div class="receipt-prompt">
                        <?= $payerReceiptOffered ? 'Email me a payment confirmation' : 'Leave your email' ?>
                        <small>Optional</small>
                    </div>
                    <input type="email" class="form-input" id="receipt-email"
                           placeholder="you@example.com" autocomplete="email" required>
                    <label class="receipt-newsletter" for="receipt-newsletter">
                        <input type="checkbox" id="receipt-newsletter" <?= $newsletterDefaultChecked ? 'checked' : '' ?>>
                        Subscribe to our newsletter
                    </label>
                    <button type="submit" class="btn" id="receipt-submit"><?= $payerReceiptOffered ? 'Send receipt' : 'Submit' ?></button>
                    <button type="button" class="receipt-skip" id="receipt-skip">No thanks</button>
                    <div class="receipt-status hidden" id="receipt-status"></div>
                </form>
                <?php if (!$payerReceiptOffered): ?>
                <div class="receipt-fallback">
                    Screenshot this page or save your invoice ID for your records.
                </div>
                <?php endif; ?>
                <?php if ($redirectUrl): ?>
                    <a href="<?= htmlspecialchars($redirectUrl) ?>" class="btn" id="redirect-btn">
                        Continue to Store
                    </a>
                <?php endif; ?>
            </div>

            <div id="payment-expired" class="<?= $invoice['status'] === 'Expired' ? '' : 'hidden' ?>">
                <div class="status-badge expired">
                    Invoice Expired
                </div>
                <p style="color: var(--text-secondary); margin-top: 1rem;">
                    This invoice has expired. Please request a new one.
                </p>
                <?php if ($redirectUrl): ?>
                    <a href="<?= htmlspecialchars($redirectUrl) ?>" class="btn" style="margin-top: 1.5rem;">
                        Return to Shop
                    </a>
                <?php endif; ?>
            </div>

            <div id="payment-provisional" class="<?= $invoice['status'] === 'Provisional' ? '' : 'hidden' ?>">
                <div class="status-badge processing">
                    Accepted offline — pending settlement
                </div>
                <p style="color: var(--text-secondary); margin-top: 1rem;">
                    This Cashu payment was accepted while the mint was unreachable. It is
                    <strong>provisional</strong> and will be confirmed automatically once the mint
                    is reachable again.
                </p>
            </div>
        </div>
    </div>

    <div class="footer">
        Powered by <a href="#">BareBits</a>
    </div>

    <div class="copy-toast" id="copy-toast">Copied to clipboard!</div>

    <script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>
    <script>
        const invoice = <?= json_encode($invoice['bolt11']) ?>;
        const onchainAddress = <?= json_encode($invoice['onchain_address'] ?? null) ?>;
        const onchainBip21 = <?= json_encode($bip21 ?? '') ?>;
        const invoiceId = <?= json_encode($invoiceId) ?>;
        const expirationTime = <?= (int)$invoice['expiration_time'] ?>;
        const redirectUrl = <?= json_encode($redirectUrl) ?>;
        const payerReceiptOffered = <?= json_encode($payerReceiptOffered) ?>;
        // CLINK noffer receive: subscription params so the page can watch the
        // offer's relay for the merchant's kind-21001 payment receipt. Null
        // unless this invoice rides the noffer rail.
        const nofferSub = <?= json_encode(
            (($invoice['payment_rail'] ?? '') === 'noffer' && !empty($invoice['noffer_relay']))
                ? [
                    'relay' => (string)$invoice['noffer_relay'],
                    'pubkey' => (string)$invoice['noffer_ephemeral_pubkey'],
                    'requestId' => (string)$invoice['noffer_request_event_id'],
                    'since' => (int)($invoice['noffer_created_at'] ?? 0),
                ]
                : null
        ) ?>;
        let currentStatus = <?= json_encode($invoice['status']) ?>;

        function renderQR(targetId, data) {
            const target = document.getElementById(targetId);
            if (!target) return;
            target.innerHTML = '';
            if (typeof QRious === 'undefined') {
                target.innerHTML = '<p style="color:#666;padding:2rem;">QR code failed to load</p>';
                return;
            }
            const canvas = document.createElement('canvas');
            target.appendChild(canvas);
            new QRious({
                element: canvas, value: data, size: 220,
                backgroundAlpha: 1, foreground: '#000000', background: '#ffffff', level: 'M',
            });
        }

        const cashuRequest = <?= json_encode($cashuRequest) ?>;
        const storeIdForCashu = <?= json_encode($invoice['store_id']) ?>;
        const invoiceIdForCashu = <?= json_encode($invoice['id']) ?>;

        if (currentStatus === 'New') {
            if (invoice) renderQR('qr-lightning', 'lightning:' + invoice.toUpperCase());
            if (onchainBip21) renderQR('qr-onchain', onchainBip21);
            if (cashuRequest) renderQR('qr-cashu', cashuRequest);
        }

        // Pay-with-Cashu-token: POST the pasted token to receive.php, keyed to
        // this invoice. Online -> Settled; mint-unreachable -> Provisional. The
        // normal status poller then flips the page.
        const cashuSubmitBtn = document.getElementById('cashu-submit');
        if (cashuSubmitBtn) {
            cashuSubmitBtn.addEventListener('click', async () => {
                const token = (document.getElementById('cashu-token-input').value || '').trim();
                const resultEl = document.getElementById('cashu-result');
                if (!token) { resultEl.style.color = 'var(--danger)'; resultEl.textContent = 'Paste a Cashu token first.'; return; }
                cashuSubmitBtn.disabled = true;
                resultEl.style.color = 'var(--text-secondary)';
                resultEl.textContent = 'Submitting…';
                try {
                    const r = await fetch('receive.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ store_id: storeIdForCashu, id: invoiceIdForCashu, token })
                    });
                    const data = await r.json();
                    if (r.ok && data.success) {
                        if (data.settlement === 'offline_provisional') {
                            resultEl.style.color = '#fbd38d';
                            resultEl.textContent = '⚠ Accepted offline — provisional until the mint is reachable.';
                        } else {
                            resultEl.style.color = '#9ae6b4';
                            resultEl.textContent = '✓ Payment received.';
                        }
                        pollStatus();
                    } else {
                        resultEl.style.color = 'var(--danger)';
                        resultEl.textContent = data.error || 'Payment failed.';
                        cashuSubmitBtn.disabled = false;
                    }
                } catch (e) {
                    resultEl.style.color = 'var(--danger)';
                    resultEl.textContent = 'Network error submitting token.';
                    cashuSubmitBtn.disabled = false;
                }
            });
        }

        // Tab switching between Lightning and on-chain payment methods.
        document.querySelectorAll('.method-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const method = tab.dataset.method;
                document.querySelectorAll('.method-tab').forEach(t => t.classList.toggle('active', t === tab));
                document.querySelectorAll('[data-method-block]').forEach(block => {
                    block.classList.toggle('hidden', block.dataset.methodBlock !== method);
                });
                const pm = document.getElementById('payment-methods');
                if (pm) pm.dataset.method = method;
            });
        });

        // Copy any string to clipboard. Falls back to a hidden textarea + execCommand
        // if the page isn't a secure context (clipboard API requires HTTPS or localhost).
        function copyText(value) {
            if (!value) return;
            const showToast = () => {
                const toast = document.getElementById('copy-toast');
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 2000);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(value).then(showToast).catch(() => fallback(value));
            } else {
                fallback(value);
            }
            function fallback(text) {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); showToast(); }
                catch (e) { console.error('copy failed', e); }
                document.body.removeChild(ta);
            }
        }
        // Attach copy handlers to any element with data-copy="...".
        document.querySelectorAll('[data-copy]').forEach(el => {
            el.addEventListener('click', () => copyText(el.dataset.copy));
            el.style.cursor = 'pointer';
        });
        // Backwards-compat alias for any handlers that still reference copyInvoice.
        function copyInvoice() { copyText(invoice); }

        // Update timer
        function updateTimer() {
            const now = Math.floor(Date.now() / 1000);
            const remaining = expirationTime - now;

            if (remaining <= 0) {
                document.getElementById('timer').textContent = 'Invoice expired';
                document.getElementById('timer').className = 'timer expired';
                return;
            }

            const timerEl = document.getElementById('timer');
            const hours = Math.floor(remaining / 3600);
            const minutes = Math.floor((remaining % 3600) / 60);
            const seconds = remaining % 60;

            let timeStr;
            if (remaining < 600) {
                // Less than 10 minutes: show minutes:seconds
                timeStr = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            } else if (hours > 0) {
                // 1+ hours: show Xh Ym
                timeStr = `${hours}h ${minutes}m`;
            } else {
                // 10-59 minutes: show just minutes
                timeStr = `${minutes} min`;
            }

            timerEl.textContent = `Expires in ${timeStr}`;
            timerEl.className = remaining < 300 ? 'timer urgent' : 'timer';
        }

        // CLINK noffer: hold a live Nostr subscription on the offer's relay and
        // forward the merchant's kind-21001 payment receipt to the server, which
        // verifies the signature + decrypts before settling. This is the
        // reliable settlement path for the noffer rail (cron is best-effort,
        // since the receipt is an ephemeral event relays may not retain). Uses
        // the browser's native WebSocket — no library/bundle needed.
        function startNofferReceiptWatch() {
            if (!nofferSub || !nofferSub.relay) return;
            let ws = null;
            let stopped = false;
            const subId = 'clink-' + Math.random().toString(36).slice(2, 10);

            function connect() {
                if (stopped) return;
                try { ws = new WebSocket(nofferSub.relay); }
                catch (e) { return; }

                ws.onopen = function () {
                    ws.send(JSON.stringify(['REQ', subId, {
                        kinds: [21001],
                        '#p': [nofferSub.pubkey],
                        '#e': [nofferSub.requestId],
                        since: Math.max(0, nofferSub.since - 1),
                    }]));
                };
                ws.onmessage = async function (msg) {
                    let data;
                    try { data = JSON.parse(msg.data); } catch (e) { return; }
                    if (!Array.isArray(data) || data[0] !== 'EVENT' || data[1] !== subId || !data[2]) return;
                    try {
                        const body = new URLSearchParams();
                        body.set('action', 'noffer_receipt');
                        body.set('event', JSON.stringify(data[2]));
                        const postUrl = new URL(window.location.href);
                        postUrl.searchParams.set('id', invoiceId);
                        const r = await fetch(postUrl.toString(), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: body.toString(),
                        });
                        const res = await r.json().catch(() => ({}));
                        if (res && res.settled) {
                            stopped = true;
                            try { ws.close(); } catch (e) {}
                            pollStatus(); // flip the UI to Settled promptly
                        }
                    } catch (e) { /* ignore; cron is the fallback */ }
                };
                ws.onclose = function () {
                    // Relays drop idle connections; reconnect while still open.
                    if (!stopped && currentStatus === 'New') {
                        setTimeout(connect, 3000);
                    }
                };
                ws.onerror = function () { try { ws.close(); } catch (e) {} };
            }
            connect();

            // Stop once the invoice leaves the New state.
            const guard = setInterval(function () {
                if (currentStatus !== 'New') {
                    stopped = true;
                    try { if (ws) ws.close(); } catch (e) {}
                    clearInterval(guard);
                }
            }, 2000);
        }

        // Poll for status
        async function pollStatus() {
            if (currentStatus === 'Settled' || currentStatus === 'Expired' || currentStatus === 'Invalid') {
                return;
            }

            try {
                const pollUrl = new URL(window.location.href);
                pollUrl.searchParams.set('id', invoiceId);
                pollUrl.searchParams.set('json', '1');
                const response = await fetch(pollUrl.toString());
                const data = await response.json();

                if (data.status !== currentStatus) {
                    currentStatus = data.status;
                    updateUI(data.status);
                }
            } catch (e) {
                console.error('Poll error:', e);
            }

            setTimeout(pollStatus, 2000);
        }

        // Update UI based on status
        function updateUI(status) {
            document.getElementById('payment-pending').classList.add('hidden');
            document.getElementById('payment-processing').classList.add('hidden');
            document.getElementById('payment-success').classList.remove('show');
            document.getElementById('payment-expired').classList.add('hidden');
            const provisionalEl = document.getElementById('payment-provisional');
            if (provisionalEl) provisionalEl.classList.add('hidden');

            switch (status) {
                case 'New':
                    document.getElementById('payment-pending').classList.remove('hidden');
                    break;
                case 'Processing':
                    document.getElementById('payment-processing').classList.remove('hidden');
                    break;
                case 'Provisional':
                    if (provisionalEl) provisionalEl.classList.remove('hidden');
                    break;
                case 'Settled':
                    document.getElementById('payment-success').classList.add('show');
                    onSettled();
                    break;
                case 'Expired':
                case 'Invalid':
                    document.getElementById('payment-expired').classList.remove('hidden');
                    break;
            }
        }

        // Start polling and timer
        if (currentStatus === 'New' || currentStatus === 'Processing' || currentStatus === 'Provisional') {
            pollStatus();
            if (currentStatus === 'New') {
                updateTimer();
                setInterval(updateTimer, 1000);
                startNofferReceiptWatch();
            }
        }

        // Settled-state entry point. Wires up the receipt form (if offered)
        // so the payer can opt into a receipt. We deliberately do NOT auto-
        // redirect: the success modal stays visible until the payer clicks
        // the "Continue to Store" link (when the merchant configured one) or
        // navigates away themselves. Auto-redirect used to flash the modal
        // for ~2 seconds, which made the invoice ID + note unreadable.
        function onSettled() {
            if (payerReceiptOffered) {
                wireReceiptForm();
            }
        }

        // Wire up the receipt form: submit POSTs to this same URL with
        // action=send_receipt. "No thanks" just hides the form so the
        // modal isn't cluttered. Neither path navigates the user away —
        // they leave via the "Continue to Store" link (if present).
        let receiptFormWired = false;
        function wireReceiptForm() {
            if (receiptFormWired) return;
            const form = document.getElementById('receipt-form');
            if (!form) return;
            receiptFormWired = true;

            const submitBtn = document.getElementById('receipt-submit');
            const skipBtn = document.getElementById('receipt-skip');
            const emailInput = document.getElementById('receipt-email');
            const newsletterInput = document.getElementById('receipt-newsletter');
            const statusEl = document.getElementById('receipt-status');
            const receiptOffered = form.getAttribute('data-receipt-offered') === '1';

            function setStatus(msg, kind) {
                statusEl.textContent = msg;
                statusEl.classList.remove('hidden', 'error', 'success');
                if (kind) statusEl.classList.add(kind);
            }

            skipBtn.addEventListener('click', () => {
                form.style.display = 'none';
            });

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const email = emailInput.value.trim();
                if (!email) { setStatus('Please enter an email address.', 'error'); return; }
                submitBtn.disabled = true;
                setStatus(receiptOffered ? 'Sending…' : 'Saving…', null);
                try {
                    const body = new URLSearchParams();
                    body.set('action', 'send_receipt');
                    body.set('email', email);
                    body.set('newsletter', (newsletterInput && newsletterInput.checked) ? '1' : '0');
                    const pollUrl = new URL(window.location.href);
                    pollUrl.searchParams.set('id', invoiceId);
                    const response = await fetch(pollUrl.toString(), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString(),
                    });
                    const data = await response.json().catch(() => ({}));
                    if (response.ok && data.success) {
                        setStatus(data.receiptQueued
                            ? 'Receipt queued — check your inbox.'
                            : 'Thanks — your email has been saved.', 'success');
                        emailInput.disabled = true;
                        if (newsletterInput) newsletterInput.disabled = true;
                        submitBtn.style.display = 'none';
                        skipBtn.style.display = 'none';
                    } else {
                        setStatus(data.error || 'Could not save your email.', 'error');
                        submitBtn.disabled = false;
                    }
                } catch (err) {
                    setStatus('Network error — please try again.', 'error');
                    submitBtn.disabled = false;
                }
            });
        }

        // Handle settled state on initial page load.
        if (currentStatus === 'Settled') {
            onSettled();
        }
    </script>
</body>
</html>
