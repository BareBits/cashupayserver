<?php
/**
 * BareBits plugin — onboarding flow.
 *
 * Walks the merchant from "no server" to "WooCommerce takes Bitcoin":
 *
 *   1. Choose: connect an existing BareBits server by URL, or install
 *      BareBits alongside WordPress (see installer.php).
 *   2. Get credentials: URL mode pairs via the server's BTCPay-compatible
 *      /api-keys/authorize redirect flow; install mode collects them through
 *      the one-time provisioning handshake after the operator finishes the
 *      BareBits setup wizard.
 *   3. Ask the Bitcoin checkout discount, then wire WooCommerce (gateway
 *      plugin + webhook + branding + ELEX discount rule).
 *
 * Rendering happens inside the wp-admin "BareBits" page (admin-menu.php
 * calls cashupay_render_onboarding()); actions POST to admin-post.php.
 * License: GPLv2 or later.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_post_cashupay_choose_mode', 'cashupay_handle_choose_mode');
add_action('admin_post_cashupay_run_install', 'cashupay_handle_run_install');
add_action('admin_post_cashupay_collect_provision', 'cashupay_handle_collect_provision');
add_action('admin_post_cashupay_provision_return', 'cashupay_handle_provision_return');
// Logged-out variant: a wp-admin session that expired mid-wizard would
// otherwise land the merchant on a blank admin-post.php page. Send them
// through the login form and back to the onboarding page instead.
add_action('admin_post_nopriv_cashupay_provision_return', 'cashupay_handle_provision_return_nopriv');
add_action('admin_post_cashupay_start_pairing', 'cashupay_handle_start_pairing');
add_action('admin_post_cashupay_finish', 'cashupay_handle_finish');
add_action('admin_post_cashupay_reset_onboarding', 'cashupay_handle_reset_onboarding');
// The pairing callback is reached by a POST the BareBits server's approval
// page auto-submits from the merchant's browser. It cannot rely on the
// wp-admin auth cookie (a cross-site POST may not carry it), so it is
// registered for both auth states and authenticated by the single-use state
// token we minted when the pairing started.
add_action('admin_post_cashupay_pairing_callback', 'cashupay_handle_pairing_callback');
add_action('admin_post_nopriv_cashupay_pairing_callback', 'cashupay_handle_pairing_callback');

/** How long a started pairing redirect stays collectable. */
const CASHUPAY_PAIRING_WINDOW_SECONDS = 900;

/** Where every handler sends the merchant back to. */
function cashupay_onboarding_url(): string {
    return admin_url('admin.php?page=cashupay');
}

/**
 * Stash a one-shot notice for the next onboarding render. Site-wide rather
 * than per-user on purpose: the pairing callback runs WITHOUT a wp-admin
 * session (a cross-site POST from the BareBits approval page may not carry
 * the auth cookie), and its outcome still has to reach the admin who started
 * the pairing. Onboarding is inherently a single-admin flow.
 */
function cashupay_flash(string $kind, string $message): void {
    set_transient('cashupay_flash', ['kind' => $kind, 'message' => $message], 300);
}

function cashupay_take_flash(): ?array {
    $flash = get_transient('cashupay_flash');
    if (is_array($flash)) {
        delete_transient('cashupay_flash');
        return $flash;
    }
    return null;
}

/**
 * The onboarding step to render, derived from stored state:
 *   'choose'    no mode picked yet
 *   'install'   install mode, installer not run yet
 *   'provision' install mode, waiting for the BareBits wizard + handshake
 *   'pair'      url mode, no credentials yet
 *   'wire'      credentials in hand; discount question + WooCommerce wiring
 *   'done'      fully wired
 */
function cashupay_onboarding_step(): string {
    if (cashupay_is_configured()) {
        return 'done';
    }
    $mode = cashupay_mode();
    if ($mode === '') {
        return 'choose';
    }
    $haveCreds = get_option('cashupay_store_id', '') !== '' && get_option('cashupay_api_key', '') !== '';
    if ($haveCreds) {
        return 'wire';
    }
    if ($mode === 'install') {
        return get_option('cashupay_install_dir', '') === '' ? 'install' : 'provision';
    }
    return 'pair';
}

// ---------------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------------

function cashupay_require_admin_post(string $nonceAction): void {
    if (!current_user_can('manage_options')) {
        wp_die(__('Sorry, you are not allowed to do that.'), 403);
    }
    check_admin_referer($nonceAction);
}

/** Step 1: the merchant picked a mode (and, for 'url', gave the server URL). */
function cashupay_handle_choose_mode(): void {
    cashupay_require_admin_post('cashupay_choose_mode');

    $mode = sanitize_key($_POST['cashupay_mode'] ?? '');
    if ($mode === 'url') {
        $url = rtrim(trim((string) ($_POST['cashupay_server_url'] ?? '')), '/');
        $probe = cashupay_probe_server($url);
        if (empty($probe['ok'])) {
            cashupay_flash('error', $probe['message']);
            wp_safe_redirect(cashupay_onboarding_url());
            exit;
        }
        if (strpos($url, 'http://') === 0 && !cashupay_is_same_host_url($url)) {
            cashupay_flash('warning', 'That server is reachable, but over plain HTTP. Payments and API keys will travel unencrypted — use HTTPS if at all possible.');
        }
        update_option('cashupay_mode', 'url');
        update_option('cashupay_server_url', $url);
    } elseif ($mode === 'install') {
        $dirname = sanitize_file_name((string) ($_POST['cashupay_install_dirname'] ?? ''));
        update_option('cashupay_mode', 'install');
        update_option('cashupay_install_dirname', $dirname, false);
    } else {
        cashupay_flash('error', 'Pick one of the two options.');
    }
    wp_safe_redirect(cashupay_onboarding_url());
    exit;
}

/** Install mode: download + unpack + configure the BareBits release. */
function cashupay_handle_run_install(): void {
    cashupay_require_admin_post('cashupay_run_install');

    $result = cashupay_run_install((string) get_option('cashupay_install_dirname', ''));
    if (empty($result['ok'])) {
        cashupay_flash('error', $result['message']);
    } else {
        // The next render embeds the setup wizard right below this notice, so
        // no "come back here" choreography — just say where the install went.
        $message = 'BareBits is installed at ' . $result['url'] . '.'
            . (empty($result['verified']) ? ' (Note: this release published no checksums; the download was TLS-protected but not checksum-verified.)' : '');
        // One question gates payments and is worth answering while the
        // merchant is still here: can this site reach its own URLs over HTTP
        // at all? (The WP-cron heartbeat, the API bridge, and checkout's
        // Greenfield calls all ride loopback requests.) The probe goes to the
        // install's api.php directly — see cashupay_install_loopback_verdict
        // on why the canonical /api/v1 form must NOT be probed from here: on
        // rewrite-hostile hosts with tight worker pools (Local WP) that
        // chain starves, and it used to cry "loopback blocked" on sites
        // whose loopback works fine.
        $verdict = cashupay_install_loopback_verdict($result['url']);
        if ($verdict === 'ok') {
            cashupay_flash('success', $message);
        } elseif ($verdict === 'unreachable') {
            cashupay_flash('warning', $message . ' Heads up: this WordPress site cannot make HTTP '
                . 'requests to its own URL (a firewall or hosting "loopback" restriction). Setup '
                . 'can still complete, but taking payments needs those requests — ask your host '
                . 'about allowing loopback requests.');
        } else { // 'unexpected': something answered, but not the install's API
            cashupay_flash('warning', $message . ' Heads up: the install\'s API did not answer as '
                . 'expected — something on this site (a security plugin, or the web server\'s '
                . 'configuration) may be intercepting requests to it. Setup can still complete, '
                . 'but taking payments needs the install\'s API to answer.');
        }
    }
    wp_safe_redirect(cashupay_onboarding_url());
    exit;
}

/**
 * Collect credentials through the handshake, store them, and flash the
 * outcome. Shared by the manual "I finished the wizard" button and the
 * wizard's own return link (cashupay_handle_provision_return).
 */
function cashupay_collect_provision_and_store(): void {
    $result = cashupay_collect_provision();
    if ($result['status'] === 'pending') {
        cashupay_flash('warning', 'BareBits setup is not finished yet — complete the wizard, then try again.');
    } elseif ($result['status'] === 'ready') {
        update_option('cashupay_store_id', $result['storeId']);
        update_option('cashupay_api_key', $result['apiKey'], false);
        update_option('cashupay_cron_key', $result['cronKey'], false);
        cashupay_cron_reschedule();
        // Prove the heartbeat loop RIGHT NOW, with the merchant watching:
        // one synchronous ping with the freshly collected key. Success seeds
        // cashupay_cron_last_ok so the stale-heartbeat warning starts from a
        // known-good point; failure is worth a warning while the merchant is
        // still here to act on it, instead of a silent 10-minute backoff.
        // Ping mode: proves routing + key without triggering the install's
        // first-ever FULL cron pass inside this blocked interactive request
        // (see cashupay_fire_cron_endpoint) — the scheduled tick, due a
        // minute out, does the first real run.
        if (cashupay_fire_cron_endpoint(15, true)) {
            cashupay_flash('success', 'Connected! One more step below.');
        } else {
            cashupay_flash('warning', 'Connected! One more step below. (Heads up: a test request to the '
                . 'install\'s background-task endpoint failed — payments will still work, but '
                . 'confirmations may lag until your host allows this site to request its own URLs.)');
        }
    } else {
        cashupay_flash('error', $result['message']);
    }
}

/** Install mode: try to collect credentials through the handshake. */
function cashupay_handle_collect_provision(): void {
    cashupay_require_admin_post('cashupay_collect_provision');

    cashupay_collect_provision_and_store();
    wp_safe_redirect(cashupay_onboarding_url());
    exit;
}

/**
 * Install mode: the wizard's completion screen sent the operator back here
 * (CASHUPAY_MANAGED_RETURN_URL, written by the installer). Collect the
 * credentials and land on the next onboarding step — the merchant clicks
 * nothing on the WordPress side.
 *
 * Capability-gated but deliberately nonce-free: the link is minted at
 * install time and rendered by the BareBits wizard, which cannot create
 * WordPress nonces (and must not — it knows nothing of WordPress). The
 * action is idempotent and merely advances the plugin's own onboarding
 * state, the same thing the nonce-protected manual button does.
 */
function cashupay_handle_provision_return(): void {
    if (!current_user_can('manage_options')) {
        wp_die(__('Sorry, you are not allowed to do that.'), 403);
    }
    // Nothing left to collect (already collected, or not in install mode):
    // just land on the onboarding page at whatever step it is on. Covers a
    // re-click of the wizard's finish button after the handshake completed.
    if (cashupay_mode() === 'install'
            && (string) get_option('cashupay_provision_token', '') !== '') {
        cashupay_collect_provision_and_store();
    }
    wp_safe_redirect(cashupay_onboarding_url());
    exit;
}

/** See the admin_post_nopriv registration above. */
function cashupay_handle_provision_return_nopriv(): void {
    wp_safe_redirect(wp_login_url(cashupay_onboarding_url()));
    exit;
}

/** URL mode: mint the state token and send the merchant to the approval page. */
function cashupay_handle_start_pairing(): void {
    cashupay_require_admin_post('cashupay_start_pairing');

    $server = cashupay_server_url();
    if ($server === '') {
        wp_safe_redirect(cashupay_onboarding_url());
        exit;
    }

    $state = bin2hex(random_bytes(16));
    update_option('cashupay_pairing_expected', ['state' => $state, 'at' => time()], false);

    $redirect = admin_url('admin-post.php') . '?action=cashupay_pairing_callback&state=' . $state;
    $query = [
        'applicationName=' . rawurlencode(get_bloginfo('name') ?: 'WordPress'),
        'applicationIdentifier=' . rawurlencode('cashupay-wordpress'),
        'redirect=' . rawurlencode($redirect),
        'strict=true',
    ];
    // Repeated bare permissions= parameters, the BTCPay convention the
    // server's authorize endpoint parses natively.
    foreach ([
        'btcpay.store.canviewinvoices',
        'btcpay.store.cancreateinvoice',
        'btcpay.store.canmodifyinvoices',
        'btcpay.store.webhooks.canmodifywebhooks',
    ] as $permission) {
        $query[] = 'permissions=' . rawurlencode($permission);
    }

    // The alongside install may sit on a host that ignores its .htaccess and
    // supports no PATH_INFO (Local WP's nginx), where the extension-less
    // /api-keys/authorize never reaches BareBits. The real file at
    // authorize.php answers on every host, and it builds its self-post URL
    // from its own request path, so the whole approval flow stays on the
    // .php form. Remote servers keep the pretty BTCPay-convention URL their
    // operator's setup already proved.
    $authorizePath = ($server !== '' && $server === cashupay_install_url())
        ? '/api-keys/authorize.php'
        : '/api-keys/authorize';
    wp_redirect($server . $authorizePath . '?' . implode('&', $query));
    exit;
}

/**
 * URL mode: the approval page POSTed the minted key back (apiKey, storeId,
 * permissions). Authenticated by the single-use state token, then the key is
 * verified against the server before anything is stored.
 */
function cashupay_handle_pairing_callback(): void {
    $expected = get_option('cashupay_pairing_expected');
    delete_option('cashupay_pairing_expected'); // single use, success or not

    $state = (string) ($_GET['state'] ?? '');
    if (!is_array($expected)
            || $state === ''
            || !hash_equals((string) ($expected['state'] ?? ''), $state)
            || (time() - (int) ($expected['at'] ?? 0)) > CASHUPAY_PAIRING_WINDOW_SECONDS) {
        wp_die('This pairing link is no longer valid. Start the pairing again from the BareBits page in wp-admin.', 403);
    }

    if (isset($_GET['error']) || empty($_POST['apiKey']) || empty($_POST['storeId'])) {
        cashupay_flash('error', 'Pairing was denied or came back incomplete. You can start it again below.');
        wp_safe_redirect(cashupay_onboarding_url());
        exit;
    }

    $apiKey = (string) wp_unslash($_POST['apiKey']);
    $storeId = (string) wp_unslash($_POST['storeId']);

    // Prove the pair is real against the server before trusting it: listing
    // the store's webhooks needs both a valid key and access to that store.
    $check = cashupay_api_request('GET', '/api/v1/stores/' . rawurlencode($storeId) . '/webhooks', null, $apiKey);
    if ($check['code'] !== 200) {
        cashupay_flash('error', 'The server rejected the pairing result (HTTP ' . $check['code'] . '). Start the pairing again.');
        wp_safe_redirect(cashupay_onboarding_url());
        exit;
    }

    update_option('cashupay_store_id', $storeId);
    update_option('cashupay_api_key', $apiKey, false);
    cashupay_flash('success', 'Paired with your BareBits server! One more step below.');
    wp_safe_redirect(cashupay_onboarding_url());
    exit;
}

/**
 * Final step: save the discount answer (when asked), record takeover consent
 * (when granted), and run the WooCommerce wiring.
 */
function cashupay_handle_finish(): void {
    cashupay_require_admin_post('cashupay_finish');

    if (isset($_POST['cashupay_discount_percent'])) {
        $percent = cashupay_parse_discount_percent((string) wp_unslash($_POST['cashupay_discount_percent']));
        if ($percent === null) {
            cashupay_flash('error', 'The discount must be a whole number between 0 and 100.');
            wp_safe_redirect(cashupay_onboarding_url());
            exit;
        }
        update_option('cashupay_discount_percent', $percent);
    }

    if (!empty($_POST['cashupay_btcpay_override_consent'])) {
        cashupay_record_btcpay_override_consent();
    }

    $storeId = (string) get_option('cashupay_store_id', '');
    $apiKey = (string) get_option('cashupay_api_key', '');
    $percent = (int) get_option('cashupay_discount_percent', 0);
    $status = cashupay_ensure_woocommerce_integration($storeId, $apiKey, $percent);

    if (($status['status'] ?? '') === 'ready') {
        update_option('cashupay_wired_at', time());
        if ($percent > 0) {
            cashupay_ensure_elex_discount($percent);
        }
        cashupay_flash('success', 'Done — WooCommerce now takes Bitcoin through BareBits.');
    } elseif (($status['status'] ?? '') === 'existing_btcpay') {
        cashupay_flash('warning', 'A BTCPay Server is already connected at ' . ($status['current_url'] ?? '') . '. Tick the consent box below to replace that connection.');
    } elseif (($status['status'] ?? '') === 'needs_woocommerce') {
        cashupay_flash('warning', 'WooCommerce is not active. Install and activate WooCommerce, then click "Finish" again.');
    } else {
        cashupay_flash('error', 'Wiring failed: ' . ($status['message'] ?? $status['status'] ?? 'unknown error'));
    }
    wp_safe_redirect(cashupay_onboarding_url());
    exit;
}

/**
 * Start over: forget the chosen mode and connection details. Never touches an
 * installed BareBits server or its data — it only resets the plugin's own
 * state.
 *
 * When an alongside install exists, the install RECORD survives the reset:
 * its location, its admin password, its SSO key, and its cron key. That
 * server keeps running with real money behind that password — the merchant
 * never chose it and can't recover it, so deleting our only copy would lock
 * them out of their own wallet UI. The chooser renders a reconnect hint
 * (with a password reveal) from these surviving options, and the WP-cron
 * pinger keeps ticking the install (it has no crontab of its own — the
 * plugin promised it the heartbeat at provision time).
 */
function cashupay_handle_reset_onboarding(): void {
    cashupay_require_admin_post('cashupay_reset_onboarding');

    $options = [
        'cashupay_mode', 'cashupay_server_url', 'cashupay_store_id',
        'cashupay_api_key', 'cashupay_cron_key', 'cashupay_wired_at',
        'cashupay_discount_percent', 'cashupay_pairing_expected',
        'cashupay_provision_token', 'cashupay_admin_password',
        'cashupay_sso_key', 'cashupay_install_dir',
        'cashupay_install_url', 'cashupay_install_data_dir',
        'cashupay_install_dirname', 'cashupay_btcpay_override_consent',
    ];
    $hasInstall = (string) get_option('cashupay_install_dir', '') !== '';
    if ($hasInstall) {
        // Make sure the install's own URL is recorded (backfills installs
        // that predate the option) BEFORE the mode is forgotten — afterwards
        // it can no longer be derived from the connected-server URL.
        cashupay_install_url();
        $options = array_values(array_diff($options, [
            'cashupay_server_url', 'cashupay_install_dir',
            'cashupay_install_url', 'cashupay_install_data_dir',
            'cashupay_install_dirname', 'cashupay_admin_password',
            'cashupay_sso_key',
            // The cron key too: the install has no crontab of its own (this
            // plugin promised it the heartbeat at provision time) and the
            // provisioning handshake that minted the key is one-time. The
            // pinger keeps ticking the install through the reset.
            'cashupay_cron_key',
        ]));
    }
    foreach ($options as $option) {
        delete_option($option);
    }
    if (!cashupay_cron_needed()) {
        cashupay_cron_unschedule();
    }
    cashupay_flash('success', $hasInstall
        ? 'Onboarding reset. Your BareBits install keeps running and nothing on its side was '
            . 'removed; its address and admin password stay saved here so you can reconnect it below.'
        : 'Onboarding reset. Nothing on the BareBits side was removed.');
    wp_safe_redirect(cashupay_onboarding_url());
    exit;
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

/** Render the onboarding UI for the current step (called by admin-menu.php). */
function cashupay_render_onboarding(): void {
    $step = cashupay_onboarding_step();
    $flash = cashupay_take_flash();
    ?>
    <div class="wrap" style="max-width: 720px;">
        <h1>BareBits</h1>
        <?php if ($flash): ?>
            <div class="notice notice-<?= esc_attr($flash['kind'] === 'error' ? 'error' : ($flash['kind'] === 'warning' ? 'warning' : 'success')) ?>"><p><?= esc_html($flash['message']) ?></p></div>
        <?php endif; ?>
        <?php
        switch ($step) {
            case 'choose':    cashupay_render_step_choose(); break;
            case 'install':   cashupay_render_step_install(); break;
            case 'provision': cashupay_render_step_provision(); break;
            case 'pair':      cashupay_render_step_pair(); break;
            case 'wire':      cashupay_render_step_wire(); break;
        }
        if ($step !== 'choose') {
            cashupay_render_reset_form();
        }
        ?>
    </div>
    <?php
}

function cashupay_render_step_choose(): void {
    // A surviving install record (a "Start over" or an earlier plugin
    // removal left an alongside install running) gets a reconnect hint: the
    // install's own address prefilled for URL mode, and the saved admin
    // password revealable — pairing needs it, and the merchant never chose
    // one.
    $existingInstall = cashupay_install_url();
    ?>
    <p>Accept Bitcoin (on-chain and Lightning) in WooCommerce. Where should your BareBits server live?</p>
    <?php if ($existingInstall !== ''): ?>
        <div class="notice notice-info inline" style="margin: 0 0 1em;">
            <p>
                A BareBits server installed earlier by this plugin is still running at
                <code><?= esc_html($existingInstall) ?></code> (its data and funds are untouched).
                To reconnect it, pick "I already run a BareBits server" below — the address is
                prefilled — and sign in with its saved admin password when asked:
                <code id="cashupay-admin-password">••••••••••••</code>
                <button type="button" class="button button-small" id="cashupay-reveal-password"
                        data-nonce="<?= esc_attr(wp_create_nonce('cashupay_reveal_password')) ?>">Reveal</button>
            </p>
            <script>
            document.getElementById('cashupay-reveal-password').addEventListener('click', function () {
                const btn = this;
                const body = new URLSearchParams();
                body.set('action', 'cashupay_reveal_password');
                body.set('nonce', btn.dataset.nonce);
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                    credentials: 'same-origin'
                }).then(r => r.json()).then(res => {
                    if (res && res.success && res.data) {
                        document.getElementById('cashupay-admin-password').textContent = res.data;
                        btn.remove();
                    }
                });
            });
            </script>
        </div>
    <?php endif; ?>
    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
        <?php wp_nonce_field('cashupay_choose_mode'); ?>
        <input type="hidden" name="action" value="cashupay_choose_mode">
        <table class="form-table" role="presentation">
            <tr>
                <td style="vertical-align: top;"><input type="radio" name="cashupay_mode" value="url" id="cashupay-mode-url" checked></td>
                <td>
                    <label for="cashupay-mode-url"><strong>I already run a BareBits server</strong></label>
                    <p class="description">Connect this shop to an existing server by URL.</p>
                    <input type="url" name="cashupay_server_url" id="cashupay-server-url" class="regular-text"
                           placeholder="https://pay.example.com" value="<?= esc_attr($existingInstall) ?>">
                </td>
            </tr>
            <tr>
                <td style="vertical-align: top;"><input type="radio" name="cashupay_mode" value="install" id="cashupay-mode-install"></td>
                <td>
                    <label for="cashupay-mode-install"><strong>Install BareBits alongside WordPress</strong></label>
                    <p class="description">Downloads the latest stable BareBits release from GitHub and installs it next to this WordPress site (its own folder, its own license, updated independently of this plugin).</p>
                    <details>
                        <summary>Advanced: folder name</summary>
                        <input type="text" name="cashupay_install_dirname" class="regular-text" placeholder="barebits">
                        <p class="description">Folder under your site the server is installed into (default <code>barebits</code>, served at <?= esc_html(site_url('/barebits')) ?>).</p>
                    </details>
                </td>
            </tr>
        </table>
        <?php submit_button('Continue'); ?>
    </form>
    <script>
    // The URL field must only take part in the browser's form validation
    // while "I already run a BareBits server" is the selected mode. Any text
    // in a type="url" input that is not a scheme-qualified URL (a pasted
    // "pay.example.com", browser autofill) otherwise blocks the WHOLE form
    // with "Please enter a URL" — making the install option unselectable.
    // Disabling also drops the field from the POST, which the install branch
    // never reads anyway. Without JavaScript the field stays enabled and
    // optional, and the server-side probe validates it as before.
    (function () {
        const urlField = document.getElementById('cashupay-server-url');
        const sync = function () {
            const urlMode = document.getElementById('cashupay-mode-url').checked;
            urlField.disabled = !urlMode;
            urlField.required = urlMode;
        };
        document.querySelectorAll('input[name="cashupay_mode"]').forEach(function (radio) {
            radio.addEventListener('change', sync);
        });
        // Browsers restore form state on back-navigation after scripts ran.
        window.addEventListener('pageshow', sync);
        sync();
    })();
    </script>
    <?php
}

function cashupay_render_step_install(): void {
    $checks = cashupay_install_preflight();
    $allOk = true;
    ?>
    <h2>Install BareBits alongside WordPress</h2>
    <table class="widefat striped" style="max-width: 680px;">
        <tbody>
        <?php foreach ($checks as $label => $check): $allOk = $allOk && $check['ok']; ?>
            <tr>
                <td><?= $check['ok'] ? '✅' : '❌' ?></td>
                <td><?= esc_html($label) ?></td>
                <td class="description"><?= esc_html($check['detail']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($allOk): ?>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="margin-top: 1em;">
            <?php wp_nonce_field('cashupay_run_install'); ?>
            <input type="hidden" name="action" value="cashupay_run_install">
            <p>This downloads the latest stable release (a few MB) and installs it. It can take a minute on slow hosts.</p>
            <?php submit_button('Download and install BareBits'); ?>
        </form>
    <?php else: ?>
        <p><strong>Fix the failed checks above, then reload this page.</strong> If your host cannot pass them, you can still run BareBits on another host and connect it by URL (use "Start over" below).</p>
    <?php endif; ?>
    <?php
}

function cashupay_render_step_provision(): void {
    $setupUrl = cashupay_server_url() . '/setup.php';
    ?>
    <h2>Finish the BareBits setup</h2>
    <p>BareBits is installed. Walk through its setup wizard below — it configures your store, wallets and payment rails, and shows you the recovery phrase to write down.
       When the wizard says you're done, its finish button brings you straight back here.</p>
    <p>
        <button type="button" class="button button-primary button-hero" id="cashupay-wizard-expand">Continue — open the wizard full screen</button>
    </p>
    <style>
    #cashupay-wizard-shell { position: relative; }
    /* Expanded: fill the whole wp-admin viewport except the admin bar and the
       left menu, tracking WordPress's own menu breakpoints (folded 36px,
       hidden + 46px-tall bar on mobile). z-index sits just under the admin
       bar's 99999 so the bar stays usable. */
    #cashupay-wizard-shell.cashupay-expanded {
        position: fixed;
        top: 32px;
        left: 160px;
        right: 0;
        bottom: 0;
        z-index: 99998;
        margin: 0;
        background: #fff;
    }
    #cashupay-wizard-shell.cashupay-expanded iframe {
        width: 100% !important;
        height: 100% !important;
        border: 0 !important;
        border-radius: 0 !important;
        display: block;
    }
    body.folded #cashupay-wizard-shell.cashupay-expanded { left: 36px; }
    @media screen and (max-width: 960px) {
        #cashupay-wizard-shell.cashupay-expanded { left: 36px; }
    }
    @media screen and (max-width: 782px) {
        #cashupay-wizard-shell.cashupay-expanded { left: 0; top: 46px; }
    }
    #cashupay-wizard-exit {
        display: none;
        position: absolute;
        top: 8px;
        right: 24px;
        z-index: 1;
    }
    #cashupay-wizard-shell.cashupay-expanded #cashupay-wizard-exit { display: block; }
    </style>
    <!-- Same-origin embed: the alongside install lives under this site's own
         origin, so the wizard runs inside wp-admin just like the old bundled
         plugin's did. -->
    <div id="cashupay-wizard-shell">
        <button type="button" class="button" id="cashupay-wizard-exit">Exit full screen</button>
        <iframe src="<?= esc_url($setupUrl) ?>" title="BareBits setup"
                style="width: 100%; height: 70vh; border: 1px solid #c3c4c7; border-radius: 4px; background: #fff;"></iframe>
    </div>
    <script>
    (function () {
        const shell = document.getElementById('cashupay-wizard-shell');
        const setExpanded = function (on) {
            shell.classList.toggle('cashupay-expanded', on);
            // Survive reloads mid-wizard: an accidental refresh (or the
            // page revisited while setup is unfinished) returns to the
            // view the merchant chose.
            try { sessionStorage.setItem('cashupayWizardExpanded', on ? '1' : '0'); } catch (e) {}
        };
        document.getElementById('cashupay-wizard-expand').addEventListener('click', function () { setExpanded(true); });
        document.getElementById('cashupay-wizard-exit').addEventListener('click', function () { setExpanded(false); });
        // Full screen by default — the wizard is the whole point of this
        // step, so it opens expanded with no click. Only a stored '0' (the
        // merchant clicked "Exit full screen" this session) keeps it
        // collapsed; blocked sessionStorage also falls back to expanded.
        let collapsed = false;
        try { collapsed = sessionStorage.getItem('cashupayWizardExpanded') === '0'; } catch (e) {}
        setExpanded(!collapsed);
    })();
    </script>
    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="margin-top: 1em;">
        <?php wp_nonce_field('cashupay_collect_provision'); ?>
        <input type="hidden" name="action" value="cashupay_collect_provision">
        <p class="description">Finished the wizard but still seeing this page? Continue manually:</p>
        <?php submit_button('I finished the wizard — continue', 'secondary'); ?>
    </form>
    <?php
}

function cashupay_render_step_pair(): void {
    ?>
    <h2>Pair with your BareBits server</h2>
    <p>Connected to <code><?= esc_html(cashupay_server_url()) ?></code>. Next, authorize this shop: you'll be sent to your server to sign in and approve an API key, then brought straight back.</p>
    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
        <?php wp_nonce_field('cashupay_start_pairing'); ?>
        <input type="hidden" name="action" value="cashupay_start_pairing">
        <?php submit_button('Pair with BareBits'); ?>
    </form>
    <?php
}

function cashupay_render_step_wire(): void {
    $takeover = cashupay_btcpay_takeover_state();
    $hasWoo = class_exists('WooCommerce');
    $saved = get_option('cashupay_discount_percent', null);
    ?>
    <h2>Last step: connect WooCommerce</h2>
    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
        <?php wp_nonce_field('cashupay_finish'); ?>
        <input type="hidden" name="action" value="cashupay_finish">
        <?php if ($hasWoo): ?>
            <p><label for="cashupay-discount">Offer customers a discount for paying with Bitcoin (Bitcoin payments have no card fees or chargebacks):</label></p>
            <p>
                <input type="number" min="0" max="100" step="1" id="cashupay-discount"
                       name="cashupay_discount_percent" value="<?= esc_attr($saved === null ? '0' : (string) (int) $saved) ?>" style="width: 5em;"> %
                <span class="description">0 = no discount. Applied automatically at checkout via the free "ELEX Discount Per Payment Method" plugin.</span>
            </p>
        <?php else: ?>
            <p><strong>WooCommerce is not active.</strong> Install and activate WooCommerce first, then click Finish.</p>
        <?php endif; ?>
        <?php if ($takeover === 'needs_consent'): ?>
            <p style="border-left: 4px solid #d63638; padding-left: 8px;">
                <label>
                    <input type="checkbox" name="cashupay_btcpay_override_consent" value="1">
                    A BTCPay Server is already connected (<code><?= esc_html((string) get_option('btcpay_gf_url', '')) ?></code>).
                    Replace that connection and all its gateway settings with BareBits.
                </label>
            </p>
        <?php endif; ?>
        <p class="description">This installs and configures the "BTCPay for WooCommerce" gateway plugin, registers the payment webhook with your BareBits server, and enables Bitcoin at checkout.</p>
        <?php submit_button('Finish'); ?>
    </form>
    <?php
}

function cashupay_render_reset_form(): void {
    ?>
    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="margin-top: 2em;">
        <?php wp_nonce_field('cashupay_reset_onboarding'); ?>
        <input type="hidden" name="action" value="cashupay_reset_onboarding">
        <button type="submit" class="button-link" style="color: #b32d2e;"
                onclick="return confirm('Start over? This only resets the plugin\'s connection state — an installed BareBits server and its funds are not touched.');">
            Start over
        </button>
    </form>
    <?php
}
