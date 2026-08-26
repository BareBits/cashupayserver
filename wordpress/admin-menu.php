<?php
/**
 * BareBits plugin — wp-admin menu, status page, and notices.
 *
 * Adds the top-level "BareBits" page: it renders the onboarding flow until
 * the shop is wired, then a status panel linking out to the BareBits server's
 * own admin. License: GPLv2 or later.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'cashupay_admin_menu');
add_action('admin_notices', 'cashupay_admin_notice');
add_action('wp_ajax_cashupay_dismiss_review', 'cashupay_dismiss_review_notice');

// Review-banner dismissal state, stored site-wide (one admin dismissing hides
// it for everyone): ['dismissed_at' => unix ts of last dismissal, 'count' =>
// total dismissals]. Each dismissal hides the banner for 30 days; after
// CASHUPAY_REVIEW_MAX_DISMISSALS it is hidden permanently.
const CASHUPAY_REVIEW_OPTION = 'cashupay_review_banner';
const CASHUPAY_REVIEW_HIDE_SECONDS = 30 * DAY_IN_SECONDS;
const CASHUPAY_REVIEW_MAX_DISMISSALS = 3;

function cashupay_admin_menu(): void {
    add_menu_page(
        'BareBits',
        'BareBits',
        'manage_options',
        'cashupay',
        'cashupay_admin_page',
        'dashicons-money-alt',
        58
    );
}

/**
 * The BareBits page: onboarding until wired, then the status panel.
 */
function cashupay_admin_page(): void {
    if (!cashupay_is_configured()) {
        cashupay_render_onboarding();
        return;
    }

    $mode = cashupay_mode();
    $server = cashupay_server_url();
    $flash = cashupay_take_flash();
    ?>
    <div class="wrap" style="max-width: 720px;">
        <h1>BareBits</h1>
        <?php if ($flash): ?>
            <div class="notice notice-<?= esc_attr($flash['kind'] === 'error' ? 'error' : ($flash['kind'] === 'warning' ? 'warning' : 'success')) ?>"><p><?= esc_html($flash['message']) ?></p></div>
        <?php endif; ?>
        <p>✅ WooCommerce is connected to your BareBits server.</p>
        <table class="widefat striped" style="max-width: 680px;">
            <tbody>
                <tr><td>Server</td><td><a href="<?= esc_url($server) ?>" target="_blank" rel="noopener"><?= esc_html($server) ?></a></td></tr>
                <tr><td>Store ID</td><td><code><?= esc_html((string) get_option('cashupay_store_id', '')) ?></code></td></tr>
                <tr><td>Mode</td><td><?= $mode === 'install' ? 'Installed alongside WordPress' : 'Existing server (connected by URL)' ?></td></tr>
                <?php if ($mode === 'install'): ?>
                    <tr><td>Install directory</td><td><code><?= esc_html((string) get_option('cashupay_install_dir', '')) ?></code></td></tr>
                    <tr>
                        <td>Data directory</td>
                        <td>
                            <code><?= esc_html((string) get_option('cashupay_install_data_dir', '')) ?></code>
                            <p class="description">Holds the wallet database — real money. It is never deleted by this plugin; back up your recovery phrase.</p>
                        </td>
                    </tr>
                    <tr><td>Background jobs</td><td>Driven by WP-cron (this plugin pings the server every minute).</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p style="margin-top: 1em;">
            <a href="<?= esc_url($server . '/admin.php') ?>" target="_blank" rel="noopener" class="button button-primary">Open the BareBits admin</a>
        </p>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="margin-top: 1em;">
            <?php wp_nonce_field('cashupay_finish'); ?>
            <input type="hidden" name="action" value="cashupay_finish">
            <p class="description">If the WooCommerce gateway or webhook got misconfigured, re-run the wiring:</p>
            <?php submit_button('Re-run WooCommerce wiring', 'secondary'); ?>
        </form>
    </div>
    <?php
}

function cashupay_admin_notice(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!cashupay_is_configured()) {
        // Not on the plugin's own page — it already renders the flow.
        if (($_GET['page'] ?? '') === 'cashupay') {
            return;
        }
        echo '<div class="notice notice-info"><p>';
        echo '<strong>BareBits</strong> is almost ready — finish setup to start accepting Lightning payments via Bitcoin. ';
        echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=cashupay')) . '">Configure BareBits</a>';
        echo '</p></div>';
        return;
    }

    cashupay_review_notice();
}

/**
 * "Leave us a review" banner, shown once setup is complete. Dismissing it
 * (the standard notice X) hides it site-wide for 30 days; after three
 * dismissals it never comes back. State lives in a WP option — see
 * CASHUPAY_REVIEW_OPTION above.
 */
function cashupay_review_notice(): void {
    if (!cashupay_review_notice_visible()) {
        return;
    }

    $nonce = wp_create_nonce('cashupay_dismiss_review');
    echo '<div class="notice notice-info is-dismissible" id="cashupay-review-notice" data-nonce="' . esc_attr($nonce) . '"><p>';
    echo 'Enjoying having control of your money with <strong>BareBits</strong>? ';
    echo '<a href="https://wordpress.org/plugins/search/barebits/" target="_blank" rel="noopener noreferrer">Leave us a review!</a>';
    echo '</p></div>';

    // WP core adds the dismiss (X) button to .is-dismissible notices after DOM
    // ready, so a delegated listener is the reliable way to catch the click.
    // The X already hides the notice for this page load; we just persist it.
    ?>
    <script>
    document.addEventListener('click', function (e) {
        const notice = e.target.closest('#cashupay-review-notice');
        if (!notice || !e.target.closest('.notice-dismiss')) {
            return;
        }
        const body = new URLSearchParams();
        body.set('action', 'cashupay_dismiss_review');
        body.set('nonce', notice.dataset.nonce);
        fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        });
    });
    </script>
    <?php
}

/**
 * Whether the review banner should render, given the stored dismissal state.
 */
function cashupay_review_notice_visible(): bool {
    $state = get_option(CASHUPAY_REVIEW_OPTION, []);
    if (!is_array($state)) {
        $state = [];
    }
    $count = (int)($state['count'] ?? 0);
    if ($count >= CASHUPAY_REVIEW_MAX_DISMISSALS) {
        return false;
    }
    $dismissedAt = (int)($state['dismissed_at'] ?? 0);
    if ($dismissedAt > 0 && (time() - $dismissedAt) < CASHUPAY_REVIEW_HIDE_SECONDS) {
        return false;
    }
    return true;
}

function cashupay_dismiss_review_notice(): void {
    check_ajax_referer('cashupay_dismiss_review', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
    }

    $state = get_option(CASHUPAY_REVIEW_OPTION, []);
    if (!is_array($state)) {
        $state = [];
    }
    $state['count'] = (int)($state['count'] ?? 0) + 1;
    $state['dismissed_at'] = time();
    // autoload=false: only admin_notices reads this, no need on every request.
    update_option(CASHUPAY_REVIEW_OPTION, $state, false);

    wp_send_json_success($state);
}
