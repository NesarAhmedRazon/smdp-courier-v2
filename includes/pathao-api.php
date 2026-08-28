<?php

/**
 * includes/pathao-api.php
 *
 * Everything that talks to Pathao's Aladdin API directly: OAuth token
 * lifecycle (get/refresh/ensure-valid), and the four read endpoints this
 * plugin needs (stores, cities, zones, areas) plus a single order-info
 * lookup used by the settings page.
 *
 * Nothing in this file touches WooCommerce orders — that's
 * includes/consignment.php and includes/webhook.php. This file only
 * knows how to talk to Pathao.
 */

defined('ABSPATH') || exit;

/**
 * Ensure a valid (non-expired) access token exists for the given
 * environment, refreshing it via the refresh-token grant if it's expired
 * or about to expire within 5 minutes.
 *
 * @param bool $is_sandbox
 * @return bool True if a valid token is available after this call.
 */
function pathao_ensure_valid_token($is_sandbox = false)
{
    $prefix = $is_sandbox ? 'pathao_sandbox_' : 'pathao_';
    $env    = $is_sandbox ? 'sandbox' : 'live';

    $token         = get_option($prefix . 'access_token');
    $expiry        = get_option($prefix . 'access_expires_in', 0);
    $refresh_token = get_option($prefix . 'access_refresh_token');

    if (empty($token)) {
        smdp_pathao_log("No token found for {$env}");
        return false;
    }

    $buffer_seconds = 300; // refresh 5 minutes before actual expiry
    if (time() < ($expiry - $buffer_seconds)) {
        smdp_pathao_log("Token valid for {$env}. Expires: " . date('Y-m-d H:i:s', $expiry));
        return true;
    }

    smdp_pathao_log("Token expired/expiring for {$env}, attempting refresh");

    if (empty($refresh_token)) {
        smdp_pathao_log("No refresh token available for {$env}");
        return false;
    }

    $new_token = pathao_auth_get_token([
        'client_id'     => get_option($prefix . 'client_id'),
        'client_secret' => get_option($prefix . 'client_secret'),
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refresh_token,
        'base_url'      => get_option($prefix . 'base_url'),
    ]);

    if (!$new_token) {
        smdp_pathao_log("Token refresh FAILED for {$env}");
        return false;
    }

    $new_expiry = time() + $new_token['expires_in'];
    update_option($prefix . 'access_token', $new_token['access_token']);
    update_option($prefix . 'access_expires_in', $new_expiry);
    update_option($prefix . 'access_refresh_token', $new_token['refresh_token']);

    smdp_pathao_log("Token refreshed for {$env}. Expires: " . date('Y-m-d H:i:s', $new_expiry));
    return true;
}

/**
 * Get a valid access token, refreshing first if necessary.
 *
 * @param bool $is_sandbox
 * @return string|false
 */
function pathao_get_valid_token($is_sandbox = false)
{
    if (!pathao_ensure_valid_token($is_sandbox)) {
        return false;
    }
    $prefix = $is_sandbox ? 'pathao_sandbox_' : 'pathao_';
    return get_option($prefix . 'access_token');
}

/**
 * Request a new token from Pathao (password grant for first-time auth,
 * refresh_token grant for renewals).
 *
 * @param array $data client_id, client_secret, grant_type, base_url, and
 *                     either username+password or refresh_token depending
 *                     on grant_type.
 * @return array{access_token:string,expires_in:int,refresh_token:string}|false
 */
function pathao_auth_get_token($data)
{
    $url = trailingslashit($data['base_url']) . 'aladdin/api/v1/issue-token';

    $post_data = [
        'client_id'     => $data['client_id'],
        'client_secret' => $data['client_secret'],
        'grant_type'    => $data['grant_type'],
    ];

    if ($data['grant_type'] === 'password') {
        $post_data['username'] = $data['username'];
        $post_data['password'] = $data['password'];
    } elseif ($data['grant_type'] === 'refresh_token') {
        $post_data['refresh_token'] = $data['refresh_token'];
    }

    smdp_pathao_log('Requesting token, grant_type: ' . $data['grant_type']);

    $response = wp_remote_post($url, [
        'headers'  => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
        'body'     => wp_json_encode($post_data),
        'timeout'  => 30,
        'sslverify' => true,
    ]);

    if (is_wp_error($response)) {
        smdp_pathao_log('Token request WP_Error: ' . $response->get_error_message());
        return false;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body      = wp_remote_retrieve_body($response);

    if ($http_code !== 200) {
        smdp_pathao_log("Token request HTTP {$http_code}: {$body}");
        return false;
    }

    $result = json_decode($body, true);

    if (!isset($result['access_token'])) {
        smdp_pathao_log('Token response missing access_token: ' . $body);
        return false;
    }

    return [
        'access_token'  => $result['access_token'],
        'expires_in'    => $result['expires_in'],
        'refresh_token' => $result['refresh_token'] ?? '',
    ];
}

/**
 * Fetch the merchant's Pathao store list. Cached indefinitely in a WP
 * option (store lists change rarely; the settings page doesn't offer a
 * "refresh" action for this on purpose — re-authenticating clears it).
 *
 * @param string $token
 * @param string $base_url
 * @return array
 */
function get_pathao_stores($token, $base_url)
{
    $store_list = get_option('pathao_store_list');
    if (!empty($store_list)) {
        return $store_list;
    }

    $data = pathao_api_get($base_url, 'aladdin/api/v1/stores', $token);
    if ($data === null) {
        return [];
    }

    $store_list = $data['data']['data'] ?? [];
    if (!empty($store_list)) {
        update_option('pathao_store_list', $store_list);
    }
    return $store_list;
}

/**
 * Fetch Pathao's city list. Not cached here — includes/location-picker.php
 * wraps this with a transient, since that's the only caller that needs
 * caching (the settings page calls it rarely, on save/render only).
 *
 * @param string $token
 * @param string $base_url
 * @return array
 */
function get_pathao_cities($token, $base_url)
{
    $data = pathao_api_get($base_url, 'aladdin/api/v1/city-list', $token);
    return $data['data']['data'] ?? [];
}

/**
 * Fetch zones for a given Pathao city ID.
 *
 * @param string $access_token
 * @param string $base_url
 * @param int|string $city
 * @return array
 */
function get_pathao_zones($access_token, $base_url, $city)
{
    if (empty($access_token)) {
        $is_sandbox   = get_option('pathao_sandbox') === 'yes';
        $prefix       = $is_sandbox ? 'pathao_sandbox_' : 'pathao_';
        $base_url     = get_option($prefix . 'base_url');
        $access_token = pathao_get_valid_token($is_sandbox);
    }

    if (empty($access_token)) {
        smdp_pathao_log('No valid token for zone list request');
        return [];
    }

    $data = pathao_api_get($base_url, "aladdin/api/v1/cities/{$city}/zone-list", $access_token);
    return $data['data']['data'] ?? [];
}

/**
 * Fetch areas for a given Pathao zone ID.
 *
 * @param string $access_token
 * @param string $base_url
 * @param int|string $zone
 * @return array
 */
function get_pathao_area($access_token, $base_url, $zone)
{
    $data = pathao_api_get($base_url, "aladdin/api/v1/zones/{$zone}/area-list", $access_token);
    return $data['data']['data'] ?? [];
}

/**
 * Look up a single consignment's current status directly from Pathao
 * (used by the settings/debug tooling, not by the webhook flow — the
 * webhook already pushes status changes to us). Retries once after a
 * token refresh if the first attempt comes back 401.
 *
 * @param string $consignment_id
 * @return array
 */
function get_pathao_order_info($consignment_id)
{
    $is_sandbox   = get_option('pathao_sandbox') === 'yes';
    $access_token = pathao_get_valid_token($is_sandbox);

    if (!$access_token) {
        return ['error' => 'No valid access token available. Please authenticate first.'];
    }

    $prefix   = $is_sandbox ? 'pathao_sandbox_' : 'pathao_';
    $base_url = get_option($prefix . 'base_url');
    $url      = trailingslashit($base_url) . "aladdin/api/v1/orders/{$consignment_id}/info";

    $request_args = static function ($token) {
        return [
            'headers'   => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout'   => 30,
            'sslverify' => true,
        ];
    };

    $response  = wp_remote_get($url, $request_args($access_token));
    $http_code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);

    // Token may have just expired — refresh once and retry.
    if ($http_code === 401 && pathao_ensure_valid_token($is_sandbox)) {
        $access_token = pathao_get_valid_token($is_sandbox);
        $response     = wp_remote_get($url, $request_args($access_token));
    }

    if (is_wp_error($response)) {
        smdp_pathao_log('Order info WP_Error: ' . $response->get_error_message());
        return ['error' => $response->get_error_message()];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($data['data'])) {
        return $data['data'];
    }
    return ['error' => $data['message'] ?? 'Failed to retrieve order info'];
}

/**
 * Shared GET request helper for the Aladdin API. Centralizes the
 * headers/timeout/error-logging boilerplate that used to be duplicated
 * across every single endpoint function.
 *
 * @param string $base_url
 * @param string $path Relative API path, no leading slash.
 * @param string $token
 * @return array|null Decoded JSON body on success, null on transport error.
 */
function pathao_api_get($base_url, $path, $token)
{
    $url = trailingslashit($base_url) . $path;

    $response = wp_remote_get($url, [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
        'timeout'   => 30,
        'sslverify' => true,
    ]);

    if (is_wp_error($response)) {
        smdp_pathao_log("GET {$path} WP_Error: " . $response->get_error_message());
        return null;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body      = wp_remote_retrieve_body($response);

    if ($http_code !== 200) {
        smdp_pathao_log("GET {$path} HTTP {$http_code}: {$body}");
        return null;
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        smdp_pathao_log("GET {$path} returned non-JSON body: {$body}");
        return null;
    }
    return $data;
}
