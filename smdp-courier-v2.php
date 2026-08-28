<?php

/**
 * Plugin Name: SMDP: Pathao Courier V2
 * Plugin URI:  https://github.com/NesarAhmedRazon/SMDP-Courier
 * Description: Pathao courier integration for SMDPicker — configuration panel, consignment (shipment) creation, and the delivery-status webhook. Pathao only, by design.
 * Version:     2.0.0
 * Author:      Nesar Ahmed
 * Author URI:  https://nesarahmed.dev/
 * License:     GPLv2 or later
 * Text Domain: smdp-text-domain
 * Domain Path: /languages/
 *
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * WC tested up to: 11.0.1
 * Requires at least: 6.5
 * Tested up to: 7.1
 * Requires PHP: 7.4
 *
 * ---------------------------------------------------------------------
 * SCOPE (deliberately narrow — this plugin does exactly four things):
 *   1. Pathao API authentication + the WooCommerce settings panel for it
 *      (includes/pathao-api.php, includes/pathao-settings.php)
 *   2. Building and sending a consignment (shipment) to Pathao when an
 *      order is marked "Ready for Shipping"
 *      (includes/consignment.php)
 *   3. Receiving Pathao's delivery-status webhook and updating the order
 *      accordingly (includes/webhook.php)
 *   4. The city/zone/area picker on the order edit screen, which talks
 *      to Pathao's own location API and caches the results
 *      (includes/location-picker.php)
 *
 * NOT in this plugin, on purpose:
 *   - Order status *registration* (Order Placed/Confirmed/Processing and
 *     the full delivery lifecycle) lives in the separate "SMDP: Order
 *     Status" plugin. This plugin only registers a fallback copy of the
 *     courier statuses itself (includes/order-status.php) if that plugin
 *     isn't active, so consignment creation always has a status to move
 *     an order into.
 *   - Customer/product sync to the SMDPicker backend, the generic
 *     multi-courier "Shipping Provider" selector, and the old custom
 *     location-cache database table are all gone — see the v2.0.0 notes
 *     below for why.
 *
 * v2.0.0 — full rework, courier-only scope:
 *   - REMOVED the custom `{prefix}_smdp_locations` table and its
 *     recursive-delete / chunked-bulk-insert PHP layer
 *     (previously inc/db_localtion-table.php). City/zone/area lookups
 *     are now cached with plain WordPress transients (7-day TTL) — no
 *     custom schema to create, migrate, or corrupt, and nothing that
 *     scales with order volume.
 *   - REMOVED the unused `{prefix}_pathaw_order` log table. It was
 *     created on plugin activation but nothing in the codebase ever
 *     inserted into it — the real webhook history lives in each order's
 *     `pathaw_log` meta and is untouched by this removal.
 *   - REMOVED the generic multi-courier "Shipping Provider" selector.
 *     Every order that reached "Ready for Shipping" already went
 *     through Pathao regardless of what was selected there, so the
 *     abstraction added a settings field and a code path that did
 *     nothing.
 *   - REMOVED customer/product sync to the SMDPicker backend — not
 *     courier logic, doesn't belong in this plugin.
 *   - error_log() calls are now gated behind the SMDP_PATHAO_DEBUG
 *     constant (off by default) instead of firing unconditionally.
 *   - Consignment creation and the webhook status mapping are otherwise
 *     UNCHANGED from the previous version, including two known bugs
 *     that were intentionally left in place rather than silently
 *     "fixed" during a restructure — see the callouts in
 *     includes/consignment.php and includes/webhook.php.
 * ---------------------------------------------------------------------
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Declare High-Performance Order Storage (HPOS) compatibility.
 *
 * Every order-meta read/write in this plugin goes through the WC_Order
 * object ($order->get_meta() / $order->update_meta_data() + save()) rather
 * than raw get_post_meta()/update_post_meta() calls on the order ID, so
 * this plugin works correctly whether HPOS is on, off, or mid-migration.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

if (!defined('SMDP_TEXTDOMAIN')) {
    define('SMDP_TEXTDOMAIN', 'smdp-text-domain');
}

if (!defined('SMDP_COURIER_DIR')) {
    define('SMDP_COURIER_DIR', plugin_dir_path(__FILE__));
}

if (!defined('SMDP_COURIER_URL')) {
    define('SMDP_COURIER_URL', plugin_dir_url(__FILE__));
}

if (!defined('SMDP_COURIER_FILE')) {
    define('SMDP_COURIER_FILE', __FILE__);
}

/**
 * Set to true (e.g. in wp-config.php: define('SMDP_PATHAO_DEBUG', true);)
 * to get the Pathao API/webhook trace that used to be logged unconditionally.
 * Left off by default so a busy store isn't writing a debug-log line for
 * every single API call and webhook delivery.
 */
if (!defined('SMDP_PATHAO_DEBUG')) {
    define('SMDP_PATHAO_DEBUG', false);
}

/**
 * Thin wrapper around error_log() that respects SMDP_PATHAO_DEBUG.
 * Every file in this plugin logs through this instead of calling
 * error_log() directly.
 */
function smdp_pathao_log($message)
{
    if (SMDP_PATHAO_DEBUG) {
        error_log('[Pathao] ' . $message);
    }
}

add_action('woocommerce_init', 'smdpc_wooReady');
function smdpc_wooReady()
{
    // Low-level API client: tokens, stores, cities/zones/areas, order-info lookup.
    require_once SMDP_COURIER_DIR . 'includes/pathao-api.php';

    // WooCommerce settings tab: credentials, sandbox toggle, token button.
    require_once SMDP_COURIER_DIR . 'includes/pathao-settings.php';

    // Courier order-status registration (fallback only — see file header).
    require_once SMDP_COURIER_DIR . 'includes/order-status.php';

    // City/zone/area picker on the order edit screen.
    require_once SMDP_COURIER_DIR . 'includes/location-picker.php';

    // Order-edit metabox: package weight/qty/description + webhook log display.
    require_once SMDP_COURIER_DIR . 'includes/order-meta-box.php';

    // Consignment (shipment) creation, triggered on order status change.
    require_once SMDP_COURIER_DIR . 'includes/consignment.php';

    // Pathao's delivery-status webhook receiver.
    require_once SMDP_COURIER_DIR . 'includes/webhook.php';
}