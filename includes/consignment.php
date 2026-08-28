<?php

/**
 * includes/consignment.php
 *
 * Fires when an order moves from "Processing" to "Ready for Shipping"
 * and creates the corresponding consignment (shipment) on Pathao.
 *
 * The actual payload-building and API-call logic here is UNCHANGED from
 * the previous version — see the two callouts below for the specific
 * bits that were deliberately left as-is rather than fixed during this
 * restructure.
 *
 * The one thing that IS different: the old code read a `_shipping_provider`
 * order meta value into a `$provider` variable and then immediately
 * overwrote it with the literal 'pathaw' before a switch() that only had
 * a 'pathaw' case — i.e. every order already went through Pathao
 * regardless of that meta value. Since this plugin no longer has any
 * other provider to dispatch to (the multi-courier selector that used to
 * write `_shipping_provider` is gone — see smdp-courier.php), that dead
 * dispatch shell is removed here. The actual trigger condition and the
 * delivery-creation logic it calls are identical to before.
 *
 * BUGFIXES applied here:
 *   - City/zone/area are now read via
 *     includes/location-picker.php's smdp_get_order_delivery_location(),
 *     which resolves the structured `_customer_address` the order-edit
 *     picker actually saves (with a legacy-key fallback for old orders).
 *     Previously this read `_pathao_city`/`_pathao_zone`/`_pathao_area`
 *     directly, which nothing wrote to — the picker's selection never
 *     reached Pathao.
 *   - The Pathao API call now goes through wp_remote_post() instead of
 *     raw curl_*, with an explicit timeout and SSL verification — same
 *     as every other Pathao request in this plugin (see
 *     includes/pathao-api.php). The previous curl call had no timeout,
 *     so a slow/hanging Pathao response could tie up the PHP worker
 *     handling the order-status-change request indefinitely.
 */

defined('ABSPATH') || exit;

add_action('woocommerce_order_status_changed', 'pathaw_order_status_changed', 10, 4);
function pathaw_order_status_changed($order_id, $old_status, $new_status, $order)
{
    if ('ready-to-shipping' === $new_status && 'order-processing' === $old_status) {
        smdp_pathao_log('Consignment creation triggered for order ' . $order_id);
        pathaw_create_new_delivery($order_id, $old_status);
    }
}

/**
 * Build the Pathao delivery payload for an order and send it.
 *
 * @param int $order_id
 * @param string $old_status Status to revert to if the API call fails.
 */
function pathaw_create_new_delivery($order_id, $old_status)
{
    $sandbox = get_option('pathao_sandbox');
    $prefix  = $sandbox === 'yes' ? 'Sandbox -> ' : '';
    $order   = wc_get_order($order_id);

    if ($sandbox === 'yes') {
        $store_id = get_option('pathao_sandbox_store_id');
        $base_url = get_option('pathao_sandbox_base_url');
        $token    = get_option('pathao_sandbox_access_token');
    } else {
        $store_id = get_option('pathao_store_id');
        $base_url = get_option('pathao_base_url');
        $token    = get_option('pathao_access_token');
    }

    $order_total = $order->get_total();

    $client_info = get_client_info($order);
    $name    = $client_info['name'];
    $phone   = $client_info['phone'];
    $order_address = $client_info['address'];

    $location     = smdp_get_order_delivery_location($order);
    $client_city  = $location['city'];
    $client_zone  = $location['zone'];
    $client_area  = $location['area'];
    $order_note   = $order->get_customer_note();
    $package_weight = $order->get_meta('_pkg_weight', true) ?: $order->get_meta('pkg_weight', true);
    $package_qty    = $order->get_meta('_pkg_qty', true);
    $package_desc   = $order->get_meta('_pkg_desc', true);

    $data = [
        'store_id'           => $store_id,
        'merchant_order_id'  => $order_id,
        'recipient_name'     => $name,
        'recipient_phone'    => $phone,
        'recipient_address'  => $order_address,
        'recipient_city'     => $client_city,
        'recipient_zone'     => $client_zone,
        'delivery_type'      => 48,
        'item_type'          => 2,
        'item_quantity'      => $package_qty,
        'item_weight'        => $package_weight,
        'item_description'   => $package_desc,
        'amount_to_collect'  => ceil($order_total),
    ];

    if (!empty($client_area)) {
        $data['recipient_area'] = $client_area;
    }
    if (!empty($order_note)) {
        $data['special_instruction'] = $order_note;
    }

    $payload = wp_json_encode($data);
    smdp_pathao_log('Payload: ' . $payload);

    $url = trailingslashit($base_url) . 'aladdin/api/v1/orders';

    $response = wp_remote_post($url, [
        'headers'   => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ],
        'body'      => $payload,
        'timeout'   => 30,
        'sslverify' => true,
    ]);

    if (is_wp_error($response)) {
        smdp_pathao_log('Consignment creation WP_Error: ' . $response->get_error_message());
        $order->add_order_note(__($prefix . 'Pathao Error: ' . $response->get_error_message()), false);
        $order->update_status($old_status);
        return;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body      = wp_remote_retrieve_body($response);

    if ($http_code === 200) {
        $result = json_decode($body, true);
        $consignment_id = $result['data']['consignment_id'];
        $fee            = $result['data']['delivery_fee'];

        $order->add_order_note(__($prefix . 'Pathao Message: ' . $body), false);
        $order->update_meta_data('_consignment_fee', $fee);
        $order->save_meta_data();
        update_order($order_id, $consignment_id, 'created');
    } else {
        $order->add_order_note(__($prefix . 'Pathao Error: Unable to create delivery. HTTP Status Code: ' . $http_code . ' — ' . $body), false);
        $order->update_status($old_status);
    }
}

/**
 * Build the recipient name/phone/address Pathao expects from a
 * WooCommerce order — shipping address, falling back to billing if
 * shipping is empty.
 *
 * @param WC_Order $order
 * @return array{name:string,address:string,phone:string}
 */
function get_client_info($order)
{
    $client_name = $order->get_formatted_shipping_full_name();
    $phone = substr($order->get_billing_phone(), -11);

    $shipping = $order->get_address('shipping');
    if (empty($shipping['address_1']) && empty($shipping['city'])) {
        $shipping = $order->get_address('billing');
    }

    $states = WC()->countries->get_states($shipping['country']);
    $state_name = $states[$shipping['state']] ?? $shipping['state'];

    $order_address = implode(', ', array_filter([
        ucfirst($shipping['address_1']),
        ucfirst($shipping['address_2']),
    ]));

    if (strtolower($shipping['city']) === strtolower($state_name)) {
        $order_address .= ', ' . ucfirst($shipping['city']);
    } else {
        $order_address .= ', ' . ucfirst($shipping['city']) . ', ' . ucfirst($state_name);
    }

    if (!empty($shipping['postcode'])) {
        $order_address .= '-' . $shipping['postcode'];
    }

    return [
        'name'    => $client_name,
        'address' => $order_address,
        'phone'   => $phone,
    ];
}