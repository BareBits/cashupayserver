<?php
/**
 * CashuPay Bitcoin-discount auto-configuration.
 *
 * Follow-through for the onboarding wizard's discount screen: install the free
 * ELEX Discount Per Payment Method plugin from wordpress.org and create a
 * percentage discount rule for the BTCPay WooCommerce gateway, so customers
 * who pick the Bitcoin payment method at checkout get the merchant's chosen
 * discount. Mirrors the guarded install pattern of btcpay-integration.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fully qualified plugin file for the ELEX discount plugin, as WordPress
 * identifies it (folder/entry-file).
 */
function cashupay_elex_plugin_file(): string {
    return 'elex-discount-per-payment-method/elex-discount-per-payment-method.php';
}

/**
 * Add a percentage discount rule for $gatewayId to an ELEX rule array,
 * or leave the array alone when a rule for that gateway already exists.
 *
 * Pure on purpose — no WordPress calls — so the merge behaviour is unit
 * testable. The row shape matches what the ELEX settings form (v1.3.2)
 * submits: `type` is the gateway's display name (shown read-only in its rules
 * table), `value` is stored as a string like every form field, and
 * `checkbox_value` is the enable toggle.
 *
 * Never overwrites: an existing rule for the gateway — whatever its value —
 * is a merchant decision, and re-renders of the completion screen must not
 * undo it. Rows that are not arrays (a corrupt option) are dropped rather
 * than fataling the completion screen.
 *
 * @param array $rules Current option value (may be an empty array).
 * @return array{rules: array, action: 'added'|'kept_existing'}
 */
function cashupay_elex_upsert_discount_rule(
    array $rules,
    string $gatewayId,
    int $percent,
    string $gatewayTitle,
    string $label
): array {
    $clean = [];
    foreach ($rules as $rule) {
        if (is_array($rule)) {
            $clean[] = $rule;
        }
    }
    foreach ($clean as $rule) {
        if (($rule['id'] ?? '') === $gatewayId) {
            return ['rules' => $clean, 'action' => 'kept_existing'];
        }
    }
    $clean[] = [
        'id' => $gatewayId,
        'type' => $gatewayTitle,
        'discount_type' => 'percentage',
        'value' => (string)$percent,
        'row_label' => $label,
        'checkbox_value' => 'yes',
    ];
    return ['rules' => $clean, 'action' => 'added'];
}

/**
 * Install (from the WordPress.org plugin directory) and activate the ELEX
 * Discount Per Payment Method plugin.
 *
 * Idempotent: already present → only (re)activated; already active → no-op.
 * 'installed' is true only when this call freshly downloaded the plugin, so
 * the completion screen knows whether to show the "we installed a plugin for
 * you" notice.
 *
 * @return array{success:bool, installed:bool, error?:string, message?:string}
 */
function cashupay_install_elex_plugin(): array {
    $pluginFile = cashupay_elex_plugin_file();

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $alreadyInstalled = array_key_exists($pluginFile, get_plugins());
    $freshlyInstalled = false;

    if (!$alreadyInstalled) {
        if (!cashupay_can_install_plugins()) {
            return [
                'success' => false,
                'installed' => false,
                'error' => 'filesystem',
                'message' => 'This host does not allow unattended plugin installs.',
            ];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $api = plugins_api('plugin_information', [
            'slug' => 'elex-discount-per-payment-method',
            'fields' => ['sections' => false],
        ]);
        if (is_wp_error($api)) {
            return [
                'success' => false,
                'installed' => false,
                'error' => 'plugins_api',
                'message' => $api->get_error_message(),
            ];
        }

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->install($api->download_link);
        if (is_wp_error($result)) {
            return [
                'success' => false,
                'installed' => false,
                'error' => 'install',
                'message' => $result->get_error_message(),
            ];
        }
        if (!$result) {
            return [
                'success' => false,
                'installed' => false,
                'error' => 'install',
                'message' => 'The plugin could not be installed.',
            ];
        }
        $freshlyInstalled = true;
    }

    if (!is_plugin_active($pluginFile)) {
        $activated = activate_plugin($pluginFile);
        if (is_wp_error($activated)) {
            return [
                'success' => false,
                'installed' => $freshlyInstalled,
                'error' => 'activate',
                'message' => $activated->get_error_message(),
            ];
        }
    }

    return ['success' => true, 'installed' => $freshlyInstalled];
}

/**
 * One call that makes the merchant's chosen Bitcoin discount live at checkout:
 * ensure the ELEX plugin is installed + active, then create the percentage
 * rule for the BTCPay gateway. Safe to call on every completion-screen render.
 *
 * Returns a status the completion screen renders from:
 *   - 'skipped'           the merchant chose 0% — nothing installed or written.
 *   - 'ready'             plugin active and a rule exists for the gateway;
 *                         'rule' says whether we added it ('added') or found a
 *                         pre-existing merchant rule we left alone
 *                         ('kept_existing'); 'auto_installed' says whether we
 *                         freshly downloaded the plugin.
 *   - 'needs_woocommerce' WooCommerce isn't active (the caller normally gates
 *                         on the gateway wiring being ready, so this is a
 *                         belt-and-braces answer).
 *   - 'needs_plugin'      the plugin is missing and could not be installed
 *                         unattended (merchant must add it by hand and reload).
 *   - 'error'             the plugin is present but could not be activated.
 *
 * @return array{status:string, auto_installed:bool, rule?:string, message?:string}
 */
function cashupay_ensure_elex_discount(int $percent): array {
    if ($percent <= 0) {
        return ['status' => 'skipped', 'auto_installed' => false];
    }

    if (!class_exists('WooCommerce')) {
        return ['status' => 'needs_woocommerce', 'auto_installed' => false];
    }

    // cashupay_can_install_plugins lives in btcpay-integration.php, which the
    // completion screen loads first — but don't depend on that ordering. Both
    // helpers sit in the same directory in every plugin layout.
    if (!function_exists('cashupay_can_install_plugins')) {
        require_once __DIR__ . '/btcpay-integration.php';
    }
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $autoInstalled = false;
    if (!is_plugin_active(cashupay_elex_plugin_file())) {
        $install = cashupay_install_elex_plugin();
        if (empty($install['success'])) {
            return [
                'status' => ($install['error'] ?? '') === 'activate' ? 'error' : 'needs_plugin',
                'auto_installed' => !empty($install['installed']),
                'message' => $install['message'] ?? '',
            ];
        }
        $autoInstalled = !empty($install['installed']);
    }

    // Display name for the ELEX rules table; the branding pass normally sets
    // this title, so fall back to the same string it would write.
    $gatewaySettings = get_option('woocommerce_btcpaygf_default_settings', []);
    $gatewayTitle = (is_array($gatewaySettings) && !empty($gatewaySettings['title']))
        ? (string)$gatewaySettings['title']
        : 'BareBits (Bitcoin + Lightning)';

    $existing = get_option('elex_discount_per_payment_method_options', []);
    if (!is_array($existing)) {
        $existing = [];
    }
    // btcpaygf_default is the BTCPay Greenfield gateway id — the same one
    // cashupay_enable_btcpay_gateway flips on at checkout.
    $result = cashupay_elex_upsert_discount_rule(
        $existing, 'btcpaygf_default', $percent, $gatewayTitle, 'Bitcoin discount'
    );
    if ($result['action'] === 'added') {
        update_option('elex_discount_per_payment_method_options', $result['rules']);
    }

    return [
        'status' => 'ready',
        'auto_installed' => $autoInstalled,
        'rule' => $result['action'],
    ];
}
