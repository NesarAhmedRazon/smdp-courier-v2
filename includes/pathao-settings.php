<?php

/**
 * includes/pathao-settings.php
 *
 * The "Pathao Auth" tab under WooCommerce → Settings: credentials,
 * sandbox toggle, and the Get/Refresh Access Token buttons. Also owns
 * the one recurring background job this plugin has — a daily cron to
 * keep tokens fresh so a store admin never has to click the button
 * manually.
 */

defined('ABSPATH') || exit;

// ---------------------------------------------------------------------
// Settings tab registration
// ---------------------------------------------------------------------

add_filter('woocommerce_settings_tabs_array', 'add_pathao_settings_tab', 50);
function add_pathao_settings_tab($tabs)
{
    $tabs['pathao'] = __('Pathao Auth', SMDP_TEXTDOMAIN);
    return $tabs;
}

add_action('woocommerce_settings_tabs_pathao', 'pathao_settings_tab');
function pathao_settings_tab()
{
    woocommerce_admin_fields(get_pathao_settings());
    pathao_auth_add_token_link();
}

add_action('woocommerce_update_options_pathao', 'update_pathao_settings');
function update_pathao_settings()
{
    woocommerce_update_options(get_pathao_settings());
}

// ---------------------------------------------------------------------
// Field schema
// ---------------------------------------------------------------------

/**
 * Builds the settings field array for both the live and sandbox
 * credential sets, plus the store-ID dropdown (only shown once a valid
 * token exists, since it's populated from a live API call).
 *
 * @return array WooCommerce settings-API field definitions.
 */
function get_pathao_settings()
{
    $current_time = time();

    $live_expiry   = get_option('pathao_access_expires_in', 0);
    $live_token    = get_option('pathao_access_token');
    $live_expired  = !empty($live_expiry) && $current_time > (int) $live_expiry;

    $sandbox         = get_option('pathao_sandbox');
    $sandbox_expiry  = get_option('pathao_sandbox_access_expires_in', 0);
    $sandbox_token   = get_option('pathao_sandbox_access_token');
    $sandbox_expired = !empty($sandbox_expiry) && $current_time > (int) $sandbox_expiry;

    $settings = [
        'section_title' => [
            'name' => __('Pathao Auth Settings', SMDP_TEXTDOMAIN),
            'type' => 'title',
            'desc' => '',
            'id'   => 'pathao_auth_section_title',
        ],
        'client_id' => [
            'name' => __('Client ID', SMDP_TEXTDOMAIN),
            'type' => 'text',
            'desc' => __('Enter your Pathao Client ID.', SMDP_TEXTDOMAIN),
            'id'   => 'pathao_client_id',
        ],
        'client_secret' => [
            'name' => __('Client Secret', SMDP_TEXTDOMAIN),
            'type' => 'text',
            'desc' => __('Enter your Pathao Client Secret.', SMDP_TEXTDOMAIN),
            'id'   => 'pathao_client_secret',
        ],
        'client_email' => [
            'name' => __('Client Email', SMDP_TEXTDOMAIN),
            'type' => 'text',
            'desc' => __('Enter your Pathao Client Email.', SMDP_TEXTDOMAIN),
            'id'   => 'pathao_client_email',
        ],
        'client_password' => [
            'name' => __('Client Password', SMDP_TEXTDOMAIN),
            'type' => 'password',
            'desc' => __('Enter your Pathao Client Password.', SMDP_TEXTDOMAIN),
            'id'   => 'pathao_client_password',
        ],
        'base_url' => [
            'name' => __('Base URL', SMDP_TEXTDOMAIN),
            'type' => 'text',
            'desc' => __('Enter the Base URL.', SMDP_TEXTDOMAIN),
            'id'   => 'pathao_base_url',
        ],
        'access_token' => [
            'name'              => __('Access Token', SMDP_TEXTDOMAIN),
            'type'              => 'textarea',
            'desc'              => __('This is your Pathao Access Token.', SMDP_TEXTDOMAIN)
                . ($live_expired ? ' <span style="color:red;"><strong>(EXPIRED)</strong></span>' : ' <span style="color:green;"><strong>(Valid)</strong></span>')
                . (!empty($live_expiry) ? '<br><strong>Expires:</strong> ' . date('Y-m-d H:i:s', $live_expiry) : ''),
            'desc_tip'          => false,
            'id'                => 'pathao_access_token',
            'custom_attributes' => ['readonly' => 'readonly'],
        ],
        'webhook_secret' => [
            'name' => __('Webhook Secret', SMDP_TEXTDOMAIN),
            'type' => 'password',
            'desc' => __('Enter your Pathao Webhook Secret.', SMDP_TEXTDOMAIN),
            'id'   => 'pathao_webhook_secret',
        ],
        'response_header_secret' => [
            'name' => __('Response Header Secret', SMDP_TEXTDOMAIN),
            'type' => 'password',
            'desc' => __('Enter your Pathao Response Header Secret.', SMDP_TEXTDOMAIN),
            'id'   => 'pathao_response_header_secret',
        ],
    ];

    // Store dropdown only makes sense once we have a working live token.
    if (!empty($live_token) && !$live_expired) {
        $settings['store_id'] = [
            'name'    => __('Store ID', SMDP_TEXTDOMAIN),
            'type'    => 'select',
            'options' => pathao_store_dropdown_options(get_option('pathao_base_url'), $live_token),
            'class'   => 'wc-enhanced-select',
            'desc'    => __('Select your Pathao Store.', SMDP_TEXTDOMAIN),
            'id'      => 'pathao_store_id',
        ];
    }

    $settings = array_merge($settings, [
        'sandbox' => [
            'name'    => __('Sandbox Mode', SMDP_TEXTDOMAIN),
            'type'    => 'radio',
            'default' => 'no',
            'options' => ['yes' => __('Yes', SMDP_TEXTDOMAIN), 'no' => __('No', SMDP_TEXTDOMAIN)],
            'desc'    => __('Enable sandbox mode for testing.', SMDP_TEXTDOMAIN),
            'id'      => 'pathao_sandbox',
        ],
        'sandbox_client_id' => [
            'name' => __('Sandbox Client ID', SMDP_TEXTDOMAIN),
            'type' => 'text',
            'desc' => __('Enter your Pathao Sandbox Client ID.', SMDP_TEXTDOMAIN),
            'id'   => 'pathao_sandbox_client_id',
        ],
        'sandbox_client_secret' => [
            'name' => __('Sandbox Client Secret', SMDP_TEXTDOMAIN),
            'type' => 'text',
            'desc' => __('Enter your Pathao Sandbox Client Secret.', SMDP_TEXTDOMAIN),
            'id'   => 'pathao_sandbox_client_secret',
        ],
        'sandbox_client_email' => [
            'name' => __('Sandbox Client Email', SMDP_TEXTDOMAIN),
            'type' => 'text',
            'desc' => __('Enter your Pathao Sandbox Client Email.', SMDP_TEXTDOMAIN),
            'id'   => 'pathao_sandbox_client_email',
        ],
        'sandbox_client_password' => [
            'name' => __('Sandbox Client Password', SMDP_TEXTDOMAIN),
            'type' => 'password',
            'desc' => __('Enter your Pathao Sandbox Client Password.', SMDP_TEXTDOMAIN),
            'id'   => 'pathao_sandbox_client_password',
        ],
        'sandbox_base_url' => [
            'name' => __('Sandbox Base URL', SMDP_TEXTDOMAIN),
            'type' => 'text',
            'desc' => __('Enter the Sandbox Base URL.', SMDP_TEXTDOMAIN),
            'id'   => 'pathao_sandbox_base_url',
        ],
        'sandbox_access_token' => [
            'name'              => __('Sandbox Access Token', SMDP_TEXTDOMAIN),
            'type'              => 'textarea',
            'desc'              => __('This is your Pathao Sandbox Access Token.', SMDP_TEXTDOMAIN)
                . ($sandbox_expired ? ' <span style="color:red;"><strong>(EXPIRED)</strong></span>' : ' <span style="color:green;"><strong>(Valid)</strong></span>')
                . (!empty($sandbox_expiry) ? '<br><strong>Expires:</strong> ' . date('Y-m-d H:i:s', $sandbox_expiry) : ''),
            'desc_tip'          => false,
            'id'                => 'pathao_sandbox_access_token',
            'custom_attributes' => ['readonly' => 'readonly'],
        ],
    ]);

    if ($sandbox === 'yes' && !empty($sandbox_token) && !$sandbox_expired) {
        $settings['sandbox_store_id'] = [
            'name'    => __('Sandbox Store ID', SMDP_TEXTDOMAIN),
            'type'    => 'select',
            'options' => pathao_store_dropdown_options(get_option('pathao_sandbox_base_url'), $sandbox_token),
            'class'   => 'wc-enhanced-select',
            'desc'    => __('Select your Pathao Sandbox Store.', SMDP_TEXTDOMAIN),
            'id'      => 'pathao_sandbox_store_id',
        ];
    }

    $settings['section_end'] = ['type' => 'sectionend', 'id' => 'pathao_auth_section_end'];

    return $settings;
}

/**
 * @param string $base_url
 * @param string $token
 * @return array<string,string> option value => label, keyed for a WC select field
 */
function pathao_store_dropdown_options($base_url, $token)
{
    $options = ['' => __('Select Store', SMDP_TEXTDOMAIN)];
    foreach (get_pathao_stores($token, $base_url) as $store) {
        $options[$store['store_id']] = $store['store_name'] . ' (' . $store['store_address'] . ')';
    }
    return $options;
}

// ---------------------------------------------------------------------
// Get/Refresh token buttons
// ---------------------------------------------------------------------

function pathao_auth_add_token_link()
{
    // Rendering only — the actual request is handled on admin_init (below),
    // before any HTML is sent, so wp_safe_redirect() works from inside it.
    if (isset($_GET['tab']) && $_GET['tab'] === 'pathao') {
        echo '<div style="margin: 10px 0;">';
        echo '<a class="button button-primary" href="' . esc_url(add_query_arg('action', 'get_token')) . '">Get/Refresh Access Token</a> ';
        echo '<a class="button button-secondary" href="' . esc_url(add_query_arg('action', 'get_sandbox_token')) . '">Get/Refresh Sandbox Token</a>';
        echo '</div>';
    }
}

add_action('admin_init', 'pathao_process_token_request_early');
function pathao_process_token_request_early()
{
    if (!isset($_GET['tab']) || $_GET['tab'] !== 'pathao' || !isset($_GET['action'])) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    if ($_GET['action'] === 'get_token') {
        pathao_handle_token_request(false);
    } elseif ($_GET['action'] === 'get_sandbox_token') {
        pathao_handle_token_request(true);
    }
}

/**
 * Handle a Get/Refresh Token button click. Tries the refresh-token grant
 * first if we have one, falling back to the password grant (email +
 * password) if that fails or if there's no refresh token yet.
 *
 * @param bool $is_sandbox
 */
function pathao_handle_token_request($is_sandbox = false)
{
    $prefix = $is_sandbox ? 'pathao_sandbox_' : 'pathao_';
    $label  = $is_sandbox ? 'Sandbox' : 'Live';

    $client_id     = get_option($prefix . 'client_id');
    $client_secret = get_option($prefix . 'client_secret');
    $base_url      = get_option($prefix . 'base_url');

    if (empty($client_id) || empty($client_secret) || empty($base_url)) {
        pathao_set_admin_notice('error', sprintf(__('%s credentials are incomplete. Please fill all required fields.', SMDP_TEXTDOMAIN), $label));
        wp_safe_redirect(remove_query_arg('action'));
        exit;
    }

    $refresh_token = get_option($prefix . 'access_refresh_token');
    $expired       = time() > (int) get_option($prefix . 'access_expires_in', 0);

    $token = false;

    if (!empty($refresh_token) && $expired) {
        $token = pathao_auth_get_token([
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
            'base_url'      => $base_url,
        ]);
    }

    if (!$token) {
        $client_email    = get_option($prefix . 'client_email');
        $client_password = get_option($prefix . 'client_password');

        if (empty($client_email) || empty($client_password)) {
            pathao_set_admin_notice('error', sprintf(__('%s email and password are required for initial authentication (refresh token missing or invalid).', SMDP_TEXTDOMAIN), $label));
            wp_safe_redirect(remove_query_arg('action'));
            exit;
        }

        $token = pathao_auth_get_token([
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'username'      => $client_email,
            'password'      => $client_password,
            'base_url'      => $base_url,
            'grant_type'    => 'password',
        ]);
    }

    if ($token) {
        $new_expiry = time() + $token['expires_in'];
        update_option($prefix . 'access_token', $token['access_token']);
        update_option($prefix . 'access_expires_in', $new_expiry);
        update_option($prefix . 'access_refresh_token', $token['refresh_token']);

        pathao_set_admin_notice('success', sprintf(__('%s token obtained successfully! Expires: %s', SMDP_TEXTDOMAIN), $label, date('Y-m-d H:i:s', $new_expiry)));
    } else {
        pathao_set_admin_notice('error', sprintf(__('Failed to obtain %s token. Please check your credentials.', SMDP_TEXTDOMAIN), $label));
    }

    // Strip ?action=... before redirecting, so the notice actually renders
    // on the next admin_notices pass and reloading doesn't re-trigger the request.
    wp_safe_redirect(remove_query_arg('action'));
    exit;
}

/**
 * One-shot admin notice via transient. Needed because
 * pathao_handle_token_request() runs on admin_init, which fires after
 * WordPress has already processed admin_notices for this request —
 * hooking add_action('admin_notices', ...) at that point is too late.
 */
function pathao_set_admin_notice($type, $message)
{
    set_transient('pathao_admin_notice', ['type' => $type, 'message' => $message], 60);
}

add_action('admin_notices', 'pathao_render_admin_notice');
function pathao_render_admin_notice()
{
    $notice = get_transient('pathao_admin_notice');
    if (!$notice) {
        return;
    }
    delete_transient('pathao_admin_notice');
    $class = $notice['type'] === 'success' ? 'notice-success' : 'notice-error';
    echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($notice['message']) . '</p></div>';
}

add_action('admin_head', 'pathao_admin_styles');
function pathao_admin_styles()
{
    if (isset($_GET['tab']) && $_GET['tab'] === 'pathao') {
        echo '<style>.pathao-token-valid{color:#00a32a;font-weight:bold;}.pathao-token-expired{color:#d63638;font-weight:bold;}</style>';
    }
}

// ---------------------------------------------------------------------
// Daily token-refresh cron — keeps tokens alive without admin interaction
// ---------------------------------------------------------------------

add_action('wp', 'pathao_schedule_token_refresh');
function pathao_schedule_token_refresh()
{
    if (!wp_next_scheduled('pathao_refresh_tokens')) {
        wp_schedule_event(time(), 'daily', 'pathao_refresh_tokens');
    }
}

add_action('pathao_refresh_tokens', 'pathao_daily_token_refresh');
function pathao_daily_token_refresh()
{
    smdp_pathao_log('Running daily token refresh cron');
    pathao_ensure_valid_token(false);
    if (get_option('pathao_sandbox') === 'yes') {
        pathao_ensure_valid_token(true);
    }
}
