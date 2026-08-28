<?php

/**
 * includes/location-picker.php
 *
 * The City / Zone / Area cascading dropdowns on the order edit screen,
 * plus the AJAX endpoint that feeds them.
 *
 * v2.0.0 change: this used to cache Pathao's location lists in a custom
 * `{prefix}_smdp_locations` database table (inc/db_localtion-table.php),
 * with its own recursive-delete and chunked-bulk-insert logic. That
 * table is gone. Caching is now a single WordPress transient per list
 * (7-day TTL) — one row in wp_options (or your object cache, if the site
 * has one) instead of a whole extra table, and nothing to migrate or
 * corrupt.
 *
 * Since this plugin only ever talks to Pathao, the old "provider" column
 * (a leftover from a multi-courier design that was never actually used)
 * is gone too — every cache key is implicitly Pathao's.
 *
 * BUGFIX: this file now exports smdp_get_order_delivery_location(),
 * and includes/consignment.php reads city/zone/area through it instead
 * of the legacy `_pathao_city`/`_pathao_zone`/`_pathao_area` keys that
 * nothing wrote to. Previously, selecting a city/zone/area here had no
 * effect on the consignment sent to Pathao — that's now fixed.
 */

defined('ABSPATH') || exit;

const SMDP_LOCATION_CACHE_TTL = 7 * DAY_IN_SECONDS;

// ---------------------------------------------------------------------
// Transient-backed cache helpers
// ---------------------------------------------------------------------

/**
 * @return array City list: [['label' => ..., 'sys_id' => ...], ...]
 */
function smdp_get_cities_cached()
{
    $cached = get_transient('smdp_pathao_cities');
    if ($cached !== false) {
        return $cached;
    }
    return [];
}

/**
 * @param int|string $parent_id A city ID (for zones) or a zone ID (for areas)
 * @param string $find 'zone' or 'area'
 * @return array
 */
function smdp_get_children_cached($find, $parent_id)
{
    $cached = get_transient("smdp_pathao_{$find}_{$parent_id}");
    if ($cached !== false) {
        return $cached;
    }
    return [];
}

/**
 * @param array $list
 */
function smdp_cache_cities($list)
{
    set_transient('smdp_pathao_cities', $list, SMDP_LOCATION_CACHE_TTL);
}

/**
 * @param string $find 'zone' or 'area'
 * @param int|string $parent_id
 * @param array $list
 */
function smdp_cache_children($find, $parent_id, $list)
{
    set_transient("smdp_pathao_{$find}_{$parent_id}", $list, SMDP_LOCATION_CACHE_TTL);
}

// ---------------------------------------------------------------------
// Order edit screen: City / Zone / Area fields
// ---------------------------------------------------------------------

/**
 * Resolve the delivery city/zone/area for an order: the structured
 * `_customer_address` meta (written by the picker below) is the primary
 * source, with a fallback to older single-key meta names for orders
 * created before that structured format existed.
 *
 * Shared by the order-edit screen display below and by
 * includes/consignment.php when building the Pathao payload — both need
 * the exact same resolution so what's shown on screen matches what's
 * actually sent.
 *
 * @param WC_Order $order
 * @return array{city:?string,zone:?string,area:?string}
 */
function smdp_get_order_delivery_location($order)
{
    $address_data = $order->get_meta('_customer_address', true);
    $city = $zone = $area = null;
    if (is_array($address_data) && !empty($address_data)) {
        $city = $address_data['city'] ?? null;
        $zone = $address_data['zone'] ?? null;
        $area = $address_data['area'] ?? null;
    }

    $city = $city ?: ($order->get_meta('_pathao_city', true) ?: $order->get_meta('_consignment_city', true));
    $zone = $zone ?: ($order->get_meta('_pathao_zone', true) ?: $order->get_meta('_consignment_zone', true));
    $area = $area ?: ($order->get_meta('_pathao_area', true) ?: $order->get_meta('_consignment_area', true));

    return ['city' => $city, 'zone' => $zone, 'area' => $area];
}

if (!function_exists('consignment_metas_shipping')) {
    add_action('woocommerce_admin_order_data_after_shipping_address', 'consignment_metas_shipping');
    function consignment_metas_shipping($order)
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        $order_id = $order->get_id();
        $consignment_id = $order->get_meta('_consignment_id', true);

        $location = smdp_get_order_delivery_location($order);
        $city = $location['city'];
        $zone = $location['zone'];
        $area = $location['area'];

        $order_city = smdp_get_shipping_state_label($order);

        $cities_list = smdp_get_cities_cached();
        $zone_list   = !empty($city) ? smdp_get_children_cached('zone', $city) : [];
        $area_list   = !empty($zone) ? smdp_get_children_cached('area', $zone) : [];

        wp_nonce_field('locations_save', 'meta_locations_nonce');
        ?>
        <div class="address-section">
            <h4 style="margin-top:0;"><?php _e('Delivery Information', SMDP_TEXTDOMAIN); ?></h4>
            <div class="meta_item_grid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap:10px;">
                <div class="form-field form-field-wide">
                    <label for="consignment_city"><?php _e('City', SMDP_TEXTDOMAIN); ?>:</label>
                    <select id="consignment_city" name="consignment_city" class="location-select" data-order-id="<?php echo esc_attr($order_id); ?>" data-level="city" data-loaded="<?php echo count($cities_list); ?>" data-current="<?php echo esc_attr($city); ?>">
                        <option value=""><?php _e('Select City', SMDP_TEXTDOMAIN); ?></option>
                        <?php foreach ($cities_list as $item) :
                            $selected = ($city == $item['sys_id']) ? ' selected' : '';
                            if (empty($city) && $order_city == $item['label']) {
                                $selected = ' selected';
                            }
                        ?>
                            <option value="<?php echo esc_attr($item['sys_id']); ?>"<?php echo $selected; ?>><?php echo esc_html($item['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="loading" id="city-loading" style="display:none;">⟳</span>
                </div>

                <div class="form-field form-field-wide">
                    <label for="consignment_zone"><?php _e('Zone', SMDP_TEXTDOMAIN); ?>:</label>
                    <select id="consignment_zone" name="consignment_zone" class="location-select" data-order-id="<?php echo esc_attr($order_id); ?>" data-level="zone" data-loaded="<?php echo count($zone_list); ?>" data-current="<?php echo esc_attr($zone); ?>">
                        <option value=""><?php _e('Select Zone', SMDP_TEXTDOMAIN); ?></option>
                        <?php if (!empty($zone_list)) : foreach ($zone_list as $item) :
                            $selected = ($zone == $item['sys_id']) ? ' selected' : '';
                        ?>
                            <option value="<?php echo esc_attr($item['sys_id']); ?>"<?php echo $selected; ?>><?php echo esc_html($item['label']); ?></option>
                        <?php endforeach; else : ?>
                            <option value=""><?php _e('No Zone Found', SMDP_TEXTDOMAIN); ?></option>
                        <?php endif; ?>
                    </select>
                    <span class="loading" id="zone-loading" style="display:none;">⟳</span>
                </div>

                <div class="form-field form-field-wide">
                    <label for="consignment_area"><?php _e('Area', SMDP_TEXTDOMAIN); ?>:</label>
                    <select id="consignment_area" name="consignment_area" class="location-select" data-order-id="<?php echo esc_attr($order_id); ?>" data-level="area" data-loaded="<?php echo count($area_list); ?>" data-current="<?php echo esc_attr($area); ?>">
                        <option value=""><?php _e('Select Area', SMDP_TEXTDOMAIN); ?></option>
                        <?php if (!empty($area_list)) : foreach ($area_list as $item) :
                            $selected = ($area == $item['sys_id']) ? ' selected' : '';
                        ?>
                            <option value="<?php echo esc_attr($item['sys_id']); ?>"<?php echo $selected; ?>><?php echo esc_html($item['label']); ?></option>
                        <?php endforeach; else : ?>
                            <option value=""><?php _e('No Area Found', SMDP_TEXTDOMAIN); ?></option>
                        <?php endif; ?>
                    </select>
                    <span class="loading" id="area-loading" style="display:none;">⟳</span>
                </div>

                <div class="form-field form-field-wide">
                    <label for="consignment_id"><?php _e('Consignment ID', SMDP_TEXTDOMAIN); ?>:</label>
                    <input type="text" id="consignment_id" name="consignment_id" value="<?php echo esc_attr($consignment_id); ?>" readonly>
                </div>
            </div>
        </div>
        <?php
    }
}

/**
 * Resolve the shipping state label for an order (used to auto-select a
 * matching city in the dropdown above when no city has been chosen yet).
 *
 * @param WC_Order $order
 * @return string
 */
function smdp_get_shipping_state_label($order)
{
    $shipping = $order->get_address('shipping');
    if (empty($shipping['state'])) {
        return '';
    }
    $states = WC()->countries->get_states($shipping['country']);
    return $states[$shipping['state']] ?? $shipping['state'];
}

add_action('admin_enqueue_scripts', 'smdp_enqueue_consignment_script');
function smdp_enqueue_consignment_script($hook)
{
    if (!class_exists('WooCommerce')) {
        return;
    }

    $screen = get_current_screen();
    if (empty($screen)) {
        return;
    }

    $is_order_edit_screen =
        (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && $screen->id === 'woocommerce_page_wc-orders') ||
        ($screen->id === 'shop_order' && isset($_GET['post']));

    if (!$is_order_edit_screen) {
        return;
    }

    wp_enqueue_style('smdp-consignment', SMDP_COURIER_URL . 'assets/css/smdp-consignment.css', [], '2.0.0');

    wp_register_script('smdp-consignment-js', SMDP_COURIER_URL . 'assets/js/smdp-consignment.js', ['jquery'], '2.0.0', true);
    wp_localize_script('smdp-consignment-js', 'smdp_admin', [
        'ajaxurl'                 => admin_url('admin-ajax.php'),
        'nonce_pathao_locations'  => wp_create_nonce('locations_nonce'),
    ]);
    wp_enqueue_script('smdp-consignment-js');
}

if (!function_exists('location_save_meta_fields_hpos')) {
    add_action('woocommerce_process_shop_order_meta', 'location_save_meta_fields_hpos', 10, 2);
    /**
     * Save the selected city/zone/area from the order edit screen into a
     * single structured `_customer_address` meta value. Read back by
     * smdp_get_order_delivery_location() above, which is what both this
     * screen and includes/consignment.php's payload-builder use.
     */
    function location_save_meta_fields_hpos($order_id, $order)
    {
        if (empty($_POST['meta_locations_nonce']) || !wp_verify_nonce($_POST['meta_locations_nonce'], 'locations_save')) {
            return;
        }
        if (!current_user_can('edit_shop_orders')) {
            return;
        }

        $order->update_meta_data('_customer_address', [
            'city' => isset($_POST['consignment_city']) ? sanitize_text_field($_POST['consignment_city']) : '',
            'zone' => isset($_POST['consignment_zone']) ? sanitize_text_field($_POST['consignment_zone']) : '',
            'area' => isset($_POST['consignment_area']) ? sanitize_text_field($_POST['consignment_area']) : '',
        ]);
        $order->save();
    }
}

// ---------------------------------------------------------------------
// AJAX: populate city/zone/area dropdowns
// ---------------------------------------------------------------------

add_action('wp_ajax_get_locations', 'get_locations_list');
/**
 * Serve a city/zone/area list to the order-edit dropdowns. Transient
 * cache first; on a miss, fetches from Pathao and caches the result for
 * next time.
 */
function get_locations_list()
{
    check_ajax_referer('locations_nonce', 'nonce');

    if (!current_user_can('edit_shop_orders')) {
        wp_send_json_error('Insufficient permissions');
    }

    $pid  = isset($_POST['parent']) ? intval($_POST['parent']) : 0;
    $find = isset($_POST['find']) ? sanitize_text_field($_POST['find']) : 'city';

    $list = ($find === 'city') ? smdp_get_cities_cached() : smdp_get_children_cached($find, $pid);

    if (!empty($list)) {
        wp_send_json_success($list);
    }

    $is_sandbox = get_option('pathao_sandbox') === 'yes';
    $token      = pathao_get_valid_token($is_sandbox);
    if (!$token) {
        wp_send_json_error('No valid access token available');
    }

    $prefix   = $is_sandbox ? 'pathao_sandbox_' : 'pathao_';
    $base_url = get_option($prefix . 'base_url');

    switch ($find) {
        case 'zone':
            $raw = get_pathao_zones($token, $base_url, $pid);
            break;
        case 'area':
            $raw = get_pathao_area($token, $base_url, $pid);
            break;
        default:
            $raw = get_pathao_cities($token, $base_url);
            break;
    }

    if (empty($raw) || !is_array($raw)) {
        wp_send_json_error("Failed to fetch {$find}s from API");
    }

    $loc_data = [];
    foreach ($raw as $item) {
        $name_key = "{$find}_name";
        $id_key   = "{$find}_id";
        if (!empty($item[$name_key])) {
            $loc_data[] = [
                'label'     => $item[$name_key],
                'sys_id'    => $item[$id_key],
                'parent_id' => $pid,
            ];
        }
    }

    if (!empty($loc_data)) {
        if ($find === 'city') {
            smdp_cache_cities($loc_data);
        } else {
            smdp_cache_children($find, $pid, $loc_data);
        }
    }

    wp_send_json_success($loc_data);
}