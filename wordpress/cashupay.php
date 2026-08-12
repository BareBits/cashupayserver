<?php
/**
 * Plugin Name: BareBits - Lightning Payments via Bitcoin
 * Plugin URI: https://github.com/BareBits/cashupayserver
 * Description: Accept Bitcoin payments (on-chain and lightning). No approval process, no middlemen. 1% fee.
 * Version: 1.2
 * Requires PHP: 8.0
 * Author: BareBits
 * License: MIT
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load bootstrap (constants, data dir logic)
require_once __DIR__ . '/bootstrap.php';

// Load plugin components
require_once __DIR__ . '/activation.php';
require_once __DIR__ . '/rewrite-rules.php';
require_once __DIR__ . '/admin-menu.php';
// Named cron-integration (not cron.php) deliberately: the build flattens
// wordpress/*.php into the same directory as the top-level cron.php endpoint,
// so a wordpress/cron.php would collide with it in the shipped plugin.
require_once __DIR__ . '/cron-integration.php';

// Register activation/deactivation hooks
register_activation_hook(__FILE__, 'cashupay_activate');
register_deactivation_hook(__FILE__, 'cashupay_deactivate');
