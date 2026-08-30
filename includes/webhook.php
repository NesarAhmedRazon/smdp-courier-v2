<?php

/**
 * includes/webhook.php
 *
 * Receives Pathao's delivery-status webhook and updates the matching
 * WooCommerce order. The status-mapping logic in update_order() is
 * UNCHANGED from the previous version (see the callout below for one
 * known bug that was deliberately left in place).
 *
 * v2.0.0 change: the previous version of this file also contained two
 * functions that were never called from anywhere —
 * update_pathaw_order_statusx() (a fully-commented duplicate of
 * update_pathaw_order_status() below) and dep_update_order() (an
 * explicitly-flagged-deprecated duplicate of update_order() below).
 * Both were dead code and have been removed; nothing was routed through
 * them.
 */

defined('ABSPATH') || exit;

add_action('rest_api_init', function () {
    register_rest_route('shipper/webhook', '/pathao', [
        'methods'  => ['POST'],
        'callback' => 'pathao_webhook_callback',
        // Auth is handled inside the callback (shared-secret header check)
        // rather than here, so this permission_callback always returns
        // true — it isn't acting as a real gate. See pathao_webhook_callback().
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Validate and process an incoming Pathao webhook request.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function pathao_webhook_callback(WP_REST_Request $request)
{
    $signature = $request->get_header('x-smdp-signature') ?? '';
    $webhook_secret = get_option('pathao_webhook_secret');
    $response_header_secret = get_option('pathao_response_header_secret');
    $body = $request->get_params();

    if (empty($webhook_secret)) {
        return new WP_REST_Response(['message' => 'You are not ready yet!'], 401);
    }

    if (!hash_equals($webhook_secret, $signature)) {
        smdp_pathao_log('Webhook signature mismatch');
        return new WP_REST_Response(['message' => 'you are not okz!'], 401);
    }

    if (empty($body)) {
        return new WP_REST_Response(['message' => 'Invalid payload'], 400);
    }

    $response_headers = [
        'X-Pathao-Merchant-Webhook-Integration-Secret' => $response_header_secret,
    ];

    if (empty($body['consignment_id']) && empty($body['event'])) {
        return new WP_REST_Response(['message' => 'Either consignment_id or event must be provided.'], 400, $response_headers);
    }

    update_pathaw_order_status($body);

    return new WP_REST_Response(['message' => 'Thank you for the Update of ' . $body['event']], 202, $response_headers);
}

/**
 * Locate the order a webhook payload refers to, append the raw payload
 * to its `pathaw_log` meta for audit purposes, and delegate the actual
 * status mapping to update_order().
 *
 * @param array $payload
 */
function update_pathaw_order_status($payload)
{
    $consignment_id = $payload['consignment_id'] ?? null;

    if (empty($consignment_id)) {
        smdp_pathao_log('Webhook payload missing consignment_id: ' . json_encode($payload));
        return;
    }

    $order_id = $payload['merchant_order_id'] ?? $payload['order_id'] ?? '-';
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    if ($order->get_status() === 'completed') {
        smdp_pathao_log("Order {$order_id} already completed, skipping webhook update");
        return;
    }

    $event  = str_replace('order.', '', $payload['event'] ?? '-');
    $reason = $payload['reason'] ?? null;
    $fee    = $payload['delivery_fee'] ?? 0;

    // Append raw payload to the order's webhook log (used by the
    // "Pathao Log" metabox in includes/order-meta-box.php).
    $existing_log = $order->get_meta('pathaw_log');
    $log_array = [];
    if (!empty($existing_log)) {
        $decoded = json_decode($existing_log, true);
        if (is_array($decoded)) {
            $log_array = $decoded;
        }
    }
    $log_array[] = $payload;
    $order->update_meta_data('pathaw_log', wp_json_encode($log_array));
    $order->save();

    update_order($order_id, $consignment_id, $event, $fee, $reason);
}

/**
 * Map a single Pathao lifecycle event to a WooCommerce order status +
 * order note. This is the single source of truth both the webhook
 * (above) and includes/consignment.php's initial "created" event go
 * through.
 *
 * KNOWN ISSUE — FIXED: the 'delivery-failed' case below used to call
 * update_status('wc-delivery-failed') with a hyphen, but the actually-
 * registered status key uses an underscore ('wc-delivery_failed' — see
 * includes/fallback-courier-statuses.php and the standalone SMDP: Order
 * Status plugin). That mismatch meant a delivery-failed webhook event
 * never moved the order into any registered status; it's now corrected
 * to use the underscore key.
 *
 * @param int|string $order_id
 * @param string $consignment_id
 * @param string $status Normalized event name (e.g. 'delivered', 'picked').
 * @param float $fee
 * @param string|null $reason
 */
function update_order($order_id, $consignment_id, $status, $fee = 0, $reason = null)
{
    $order_id = (int) $order_id;
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    if ($status === 'created') {
        // '_consignment_id' is the guard against double-processing —
        // never overwritten once set.
        $existing_consignment_id = $order->get_meta('_consignment_id', true);
        if ($existing_consignment_id) {
            return;
        }

        $data = $order->get_data();
        $phone = substr($data['billing']['phone'], -11);

        $order->update_status('wc-ready-to-shipping');
        $order->add_order_note(__('Parcel Tracking: <a href="https://merchant.pathao.com/tracking?consignment_id=' . $consignment_id . '&phone=' . $phone . '">' . $consignment_id . '</a>'), true);
        $order->update_meta_data('_consignment_id', $consignment_id);
        $order->update_meta_data('_consignment_fee', (float) $fee);
        $order->save_meta_data();
    }

    switch ($status) {
        case 'picked':
            $order->update_status('wc-pickup_ok');
            $order->add_order_note(__('Parcel Picked Successfully'), false);
            break;
        case 'updated':
            $order->update_status('wc-pickup_updated');
            break;
        case 'pickup-requested':
            $order->update_status('wc-pickup_requested');
            $order->add_order_note(__('Waitting for Pickup'), true);
            break;
        case 'assigned-for-pickup':
            $order->add_order_note(__('Assigned for Pickup'), false);
            break;
        case 'pickup-failed':
            $order->update_status('wc-pickup_error');
            $order->add_order_note(__('Pickup Failed'), false);
            break;
        case 'pickup-cancelled':
            $order->update_status('wc-pickup_cancelled');
            break;
        case 'at-the-sorting-hub':
            $order->update_status('wc-at_sorting_hub');
            $order->add_order_note(__('At the Sorting Hub'), false);
            break;
        case 'in-transit':
            $order->update_status('wc-on_the_way');
            $order->add_order_note(__('On the way'), true);
            break;
        case 'received-at-last-mile-hub':
            $order->update_status('wc-last_mile_hub');
            $order->add_order_note(__('Parcel arrived at your city'), true);
            break;
        case 'assigned-for-delivery':
            $order->update_status('wc-ready_to_delivery');
            $order->add_order_note(__('On the way to Delivery'), false);
            break;
        case 'partial-delivery':
            $order->add_order_note(__('Partially deliverted: ' . $reason), false);
            break;
        case 'delivered':
            $order->update_status('wc-delivery_success');
            $order->add_order_note(__('পার্সেলটি সফলভাবে ডেলিভারি করা হয়েছে!'), true);
            break;
        case 'delivery-failed':
            $order->add_order_note(__('Delivery Failed: ' . $reason), true);
            $order->update_status('wc-delivery_failed');
            break;
        case 'returned':
            $order->add_order_note(__('Returned: ' . $reason), true);
            $order->update_status('wc-returned');
            break;
        case 'on-hold':
            $order->add_order_note(__('On Hold: ' . $reason), true);
            $order->update_status('wc-delivery_hold');
            break;
        case 'paid':
            $order->add_order_note(__('Paid'), false);
            $order->update_status('wc-completed');
            break;
        case 'paid-return':
            $order->add_order_note(__('Paid Returned: ' . $reason), false);
            break;
        case 'exchanged':
            $order->add_order_note(__('Exchanged: ' . $reason), false);
            break;
        default:
            break;
    }
}