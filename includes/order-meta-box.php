<?php

/**
 * includes/order-meta-box.php
 *
 * Two things on the order edit screen:
 *   1. "Pathao Log" metabox — renders the webhook event history stored
 *      in the order's `pathaw_log` meta (written by includes/webhook.php).
 *   2. Package weight/quantity/description fields, shown after the
 *      billing address. These feed directly into the consignment
 *      payload built in includes/consignment.php.
 */

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

add_action('add_meta_boxes', 'admin_order_custom_metabox');
function admin_order_custom_metabox()
{
    $screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')
        && wc_get_container()->get(CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
        ? wc_get_page_screen_id('shop-order')
        : 'shop_order';

    add_meta_box('custom', 'Pathao Log', 'custom_metabox_content', $screen, 'normal', 'low');
}

function custom_metabox_content($object)
{
    $order = is_a($object, 'WP_Post') ? wc_get_order($object->ID) : $object;
    get_pathaw_log_table_for_order($order->get_order_number());
}

/**
 * Render the webhook event history as a simple table.
 *
 * @param int|string $order_id
 */
function get_pathaw_log_table_for_order($order_id)
{
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    $log_json = $order->get_meta('pathaw_log');
    if (empty($log_json)) {
        echo '<p><em>No delivery logs found.</em></p>';
        return;
    }

    $log_array = json_decode($log_json, true);
    if (!is_array($log_array)) {
        echo '<p><em>Invalid log data format.</em></p>';
        return;
    }

    echo "<table class='pathaw-log-table' style='border: 1px solid #ccc; border-collapse: collapse; width: 100%;'>";
    echo "<thead><tr style='background: #f9f9f9;'>";
    echo "<th style='border: 1px solid #ccc; padding: 8px; text-align: left;'>Event</th>";
    echo "<th style='border: 1px solid #ccc; padding: 8px; text-align: left;'>Updated At</th>";
    echo "</tr></thead><tbody>";

    foreach ($log_array as $entry) {
        $event = $entry['event'] ?? ($entry['payload']['event'] ?? '—');
        $event = str_replace('order.', '', $event ?? '-');
        $updated_at_raw = $entry['updated_at'] ?? ($entry['payload']['updated_at'] ?? null);

        if ($updated_at_raw) {
            try {
                $datetime = new DateTime($updated_at_raw, new DateTimeZone('Asia/Dhaka'));
                $datetime->setTimezone(wp_timezone());
                $updated_at = esc_html($datetime->format('j M, Y h:i A'));
            } catch (Exception $e) {
                $updated_at = esc_html($updated_at_raw);
            }
        } else {
            $updated_at = '—';
        }

        echo '<tr>';
        echo "<td style='border: 1px solid #ccc; padding: 8px;'>" . esc_html(ucfirst(str_replace('_', ' ', $event))) . '</td>';
        echo "<td style='border: 1px solid #ccc; padding: 8px;'>" . $updated_at . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

if (!function_exists('consignment_metas')) {
    add_action('woocommerce_admin_order_data_after_billing_address', 'consignment_metas');
    /**
     * Package weight/quantity/description fields, read directly by
     * includes/consignment.php when building the Pathao delivery payload.
     * Persisted on order save by save_pathao_hpos_meta_fields() below.
     */
    function consignment_metas($order)
    {
        $pkg_weight = $order->get_meta('_pkg_weight', true);
        $pkg_weight = empty($pkg_weight) ? '0.2' : $pkg_weight;

        $pkg_qty = $order->get_meta('_pkg_qty', true);
        $pkg_qty = empty($pkg_qty) ? 1 : $pkg_qty;

        $package_desc = $order->get_meta('_pkg_desc', true);
        $package_desc = $package_desc === '' ? 'Electronics Parts and ICs' : $package_desc;
        ?>
        <div class="meta_item_grid" style="display:grid; grid-template-columns:repeat(6,1fr); gap:10px;">
            <div class="form-field form-field-wide">
                <label for="pkg_weight"><?php echo esc_html__('Weight', SMDP_TEXTDOMAIN); ?>:</label>
                <input type="text" id="pkg_weight" name="pkg_weight" value="<?php echo esc_attr($pkg_weight); ?>" class="pathao-auto-save">
            </div>
            <div class="form-field form-field-wide">
                <label for="pkg_qty"><?php echo esc_html__('Quantity', SMDP_TEXTDOMAIN); ?>:</label>
                <input type="text" id="pkg_qty" name="pkg_qty" value="<?php echo esc_attr($pkg_qty); ?>" class="pathao-auto-save">
            </div>
            <div class="form-field form-field-wide" style="grid-column:span 4;">
                <label for="pkg_desc"><?php echo esc_html__('Package Description', SMDP_TEXTDOMAIN); ?>:</label>
                <input type="text" id="pkg_desc" name="pkg_desc" value="<?php echo esc_attr($package_desc); ?>" class="pathao-auto-save">
            </div>
        </div>
        <?php
    }
}

add_action('woocommerce_process_shop_order_meta', 'save_pathao_hpos_meta_fields', 10, 2);
/**
 * Persist the Weight/Quantity/Description fields rendered by
 * consignment_metas() above. Registered on the same
 * woocommerce_process_shop_order_meta hook WooCommerce itself gates
 * behind its own order-edit nonce, so no separate nonce check is needed
 * here — matching how WooCommerce's own core meta boxes save.
 *
 * @param int $order_id
 * @param WC_Order $order
 */
function save_pathao_hpos_meta_fields($order_id, $order)
{
    if (!current_user_can('edit_shop_orders')) {
        return;
    }

    if (isset($_POST['pkg_weight'])) {
        $order->update_meta_data('_pkg_weight', sanitize_text_field($_POST['pkg_weight']));
    }
    if (isset($_POST['pkg_qty'])) {
        $order->update_meta_data('_pkg_qty', sanitize_text_field($_POST['pkg_qty']));
    }
    if (isset($_POST['pkg_desc'])) {
        $order->update_meta_data('_pkg_desc', sanitize_text_field($_POST['pkg_desc']));
    }
    $order->save_meta_data();
}