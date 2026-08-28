<?php 

defined('ABSPATH') || exit;

if(!function_exists('register_order_status')){
    // Register Custom Order Status for Pathaw Courier
    add_action('woocommerce_register_shop_order_post_statuses', 'register_order_status');
    function register_order_status($statuses) {
        $custom_statuses = [
                'wc-ready-to-shipping' => [
                    'label'                     => _x('Ready to send', 'Order status', 'woocommerce'),
                    'public'                    => true,
                    'exclude_from_search'       => false,
                    'show_in_admin_all_list'    => true,
                    'show_in_admin_status_list' => true,
                    'label_count'               => _n_noop('Ready For Shipping <span class="count">(%s)</span>', 'Ready For Shipping <span class="count">(%s)</span>', 'woocommerce'),
                ],
                'wc-pickup_requested' => [
                    'label'                     => _x('Picked Requested', 'Order status', 'woocommerce'),
                    'public'                    => true,
                    'exclude_from_search'       => false,
                    'show_in_admin_all_list'    => true,
                    'show_in_admin_status_list' => true,
                    'label_count'               => _n_noop('Picked Requested (%s)', 'Picked Requested (%s)', 'woocommerce'),
                ],
                'wc-pickup_updated' => [
                    'label'                     => _x('Shipping Updated', 'Order status', 'woocommerce'),
                    'public'                    => true,
                    'exclude_from_search'       => false,
                    'show_in_admin_all_list'    => true,
                    'show_in_admin_status_list' => true,
                    'label_count'               => _n_noop('Shipping Updated (%s)', 'Shipping Updated (%s)', 'woocommerce')
                ],
                'wc-pickup_error' => [
                    'label'                     => _x('PickUp Failed', 'Order status', 'woocommerce'),
                    'public'                    => true,
                    'exclude_from_search'       => false,
                    'show_in_admin_all_list'    => true,
                    'show_in_admin_status_list' => true,
                    'label_count'               => _n_noop('PickUp Failed <span class="count">(%s)</span>', 'PickUp Failed <span class="count">(%s)</span>', 'woocommerce'),
                ],
                'wc-pickup_ok' => [
                    'label'                     => _x('Picked Successfully', 'Order status', 'woocommerce'),
                    'public'                    => true,
                    'exclude_from_search'       => false,
                    'show_in_admin_all_list'    => true,
                    'show_in_admin_status_list' => true,
                    'label_count'               => _n_noop('Picked Successfully <span class="count">(%s)</span>', 'Picked Successfully <span class="count">(%s)</span>', 'woocommerce'),
                ],
                // 'wc-pickup_cancelled' => [
                //     'label'                     => _x('Pickup Cancelled', 'Order status', 'woocommerce'),
                //     'public'                    => true,
                //     'exclude_from_search'       => false,
                //     'show_in_admin_all_list'    => true,
                //     'show_in_admin_status_list' => true,
                //     'label_count'               => _n_noop('Pickup Cancelled <span class="count">(%s)</span>', 'Pickup Cancelled <span class="count">(%s)</span>', 'woocommerce'),
                // ],
                'wc-at_sorting_hub' => [
                    'label'                     => _x('At Sorting Hub', 'Order status', 'woocommerce'),
                    'public'                    => true,
                    'exclude_from_search'       => false,
                    'show_in_admin_all_list'    => true,
                    'show_in_admin_status_list' => true,
                    'label_count'               => _n_noop('At Sorting Hub <span class="count">(%s)</span>', 'At Sorting Hub <span class="count">(%s)</span>', 'woocommerce'),
                ],
                'wc-on_the_way' => [
                    'label'                     => _x('In Transit', 'Order status', 'woocommerce'),
                    'public'                    => true,
                    'exclude_from_search'       => false,
                    'show_in_admin_all_list'    => true,
                    'show_in_admin_status_list' => true,
                    'label_count'               => _n_noop('In Transit <span class="count">(%s)</span>', 'In Transit <span class="count">(%s)</span>', 'woocommerce'),
                ],
                'wc-last_mile_hub' => [
                    'label'                     => _x('At Last Mile Hub', 'Order status', 'woocommerce'),
                    'public'                    => true,
                    'exclude_from_search'       => false,
                    'show_in_admin_all_list'    => true,
                    'show_in_admin_status_list' => true,
                    'label_count'               => _n_noop('At Last Mile Hub <span class="count">(%s)</span>', 'At Last Mile Hub <span class="count">(%s)</span>', 'woocommerce'),
                ],
                'wc-ready_to_delivery' => [
                    'label'                     => _x('Assigned for Delivery', 'Order status', 'woocommerce'),
                    'public'                    => true,
                    'exclude_from_search'       => false,
                    'show_in_admin_all_list'    => true,
                    'show_in_admin_status_list' => true,
                    'label_count'               => _n_noop('Assigned for Delivery <span class="count">(%s)</span>', 'Assigned for Delivery <span class="count">(%s)</span>', 'woocommerce'),
                ],
                'wc-delivery_success' => [
                    'label'                     => _x('Delivered', 'Order status', 'woocommerce'),
                    'public'                    => true,
                    'exclude_from_search'       => false,
                    'show_in_admin_all_list'    => true,
                    'show_in_admin_status_list' => true,
                    'label_count'               => _n_noop('Delivered <span class="count">(%s)</span>', 'Delivered <span class="count">(%s)</span>', 'woocommerce'),
                ],
                'wc-delivery_failed' => [
                    'label'                     => _x('Delivery Failed', 'Order status', 'woocommerce'),
                    'public'                    => true,
                    'exclude_from_search'       => false,
                    'show_in_admin_all_list'    => true,
                    'show_in_admin_status_list' => true,
                    'label_count'               => _n_noop('Delivery Failed <span class="count">(%s)</span>', 'Delivery Failed <span class="count">(%s)</span>', 'woocommerce'),
                ],
                'wc-delivery_hold' => [
                    'label'                     => _x('Delivery on Hold', 'Order status', 'woocommerce'),
                    'public'                    => true,
                    'exclude_from_search'       => false,
                    'show_in_admin_all_list'    => true,
                    'show_in_admin_status_list' => true,
                    'label_count'               => _n_noop('Delivery on Hold <span class="count">(%s)</span>', 'Delivery on Hold <span class="count">(%s)</span>', 'woocommerce'),
                ],
                'wc-returned' => [
                    'label'                     => _x('Returned', 'Order status', 'woocommerce'),
                    'public'                    => true,
                    'exclude_from_search'       => false,
                    'show_in_admin_all_list'    => true,
                    'show_in_admin_status_list' => true,
                    'label_count'               => _n_noop('Returned <span class="count">(%s)</span>', 'Returned <span class="count">(%s)</span>', 'woocommerce'),
                ],
            ];
        return array_merge($statuses, $custom_statuses);
    }
}


if(!function_exists('add_order_status_to_wc')){
    // Add Custom Order Status to WooCommerce
    
    add_filter('wc_order_statuses', 'add_order_status_to_wc');
    function add_order_status_to_wc($order_statuses) {
        $new_order_statuses = [];

        // Add new order status after processing
        foreach ($order_statuses as $key => $status) {
            $new_order_statuses[$key] = $status;
            // if the order status is wc-order-processing then only then add the custom order statuses
            if ('wc-order-processing' === $key || 'wc-completed' === $key) {
                $new_order_statuses['wc-ready-to-shipping'] = _x('Ready For Shipping', 'Order status', 'woocommerce');               
                $new_order_statuses['wc-pickup_requested'] = _x('Pickup Requested', 'Order status', 'woocommerce');      
                $new_order_statuses['wc-pickup_updated'] = _x('Shipping Updated', 'Order status', 'woocommerce');       
                $new_order_statuses['wc-pickup_ok'] = _x('Parcel Picked', 'Order status', 'woocommerce'); // Frontend label
                $new_order_statuses['wc-pickup_error'] = _x('Pickup Failed', 'Order status', 'woocommerce'); // Frontend label
                //$new_order_statuses['wc-pickup_cancelled'] = _x('Pickup Cancelled', 'Order status', 'woocommerce'); // Frontend label
                $new_order_statuses['wc-at_sorting_hub'] = _x('At Sorting Hub', 'Order status', 'woocommerce'); // Frontend label
                $new_order_statuses['wc-on_the_way'] = _x('In Transit', 'Order status', 'woocommerce'); // Frontend label
                $new_order_statuses['wc-last_mile_hub'] = _x('At Last Mile Hub', 'Order status', 'woocommerce'); // Frontend label
                $new_order_statuses['wc-ready_to_delivery'] = _x('Assigned for Delivery', 'Order status', 'woocommerce'); // Frontend label
                $new_order_statuses['wc-delivery_success'] = _x('Delivered', 'Order status', 'woocommerce'); // Frontend label
                $new_order_statuses['wc-delivery_failed'] = _x('Delivery Failed', 'Order status', 'woocommerce'); // Frontend label — fixed key (was 'wc-delivery-failed' with a hyphen)
                $new_order_statuses['wc-delivery_hold'] = _x('Delivery on Hold', 'Order status', 'woocommerce'); // Frontend label
                $new_order_statuses['wc-returned'] = _x('Returned', 'Order status', 'woocommerce'); // Frontend label — fixed typo (was 'Returnd')

            }
            
        } 

        return $new_order_statuses;
    }
}