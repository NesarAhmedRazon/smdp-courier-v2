<?php

/**
 * includes/order-status.php
 *
 * Registering the courier delivery-lifecycle statuses (Ready to Ship,
 * Pickup Requested, Delivered, etc.) is normally the job of the
 * standalone "SMDP: Order Status" plugin, since those statuses aren't
 * specific to any one courier.
 *
 * This file is only a fallback: if that plugin isn't installed or
 * active, we register the same statuses ourselves so consignment
 * creation (includes/consignment.php) and the webhook handler
 * (includes/webhook.php) — both of which move orders through these
 * exact status keys — keep working standalone.
 */

defined('ABSPATH') || exit;

if (!defined('SMDP_ORDER_STATUS_PLUGIN_LOADED')) {
    require_once SMDP_COURIER_DIR . 'includes/fallback-courier-statuses.php';
}
