<?php
/*
 * Plugin Name: WP Data Sync SSO
 * Plugin URI:  https://wpdatasync.com
 * Description: WP Data Sync SSO client for use with WP Ouath Server plugin.
 * Version:     1.0.0
 * Author:      KevinBrent
 * Author URI:  https://wpdatasync.com
 * Text Domain: wpds-sso
 *
 * @package WP_DataSync
*/

namespace WP_DataSync\Sso_Client;

use WP_REST_Response;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get the SSO Oauth server URL
 *
 * @return string
 */
function get_sso_oauth_server_url(): string {
    return defined( 'WPDS_SSO_SERVER_URL' ) ? WPDS_SSO_SERVER_URL : get_option( 'wpds_sso_server_url' , '' );
}

/**
 * Get the SSO Path
 *
 * @return string
 */
function get_sso_login_path(): string {
    return defined( 'WPDS_SSO_LOGIN_PATH' ) ? WPDS_SSO_LOGIN_PATH : get_option( 'wpds_sso_login_path' , '' );
}

/**
 * Get the SSO login URL
 *
 * @return string
 */
function get_sso_login_url(): string {
    return get_sso_oauth_server_url() . get_sso_login_path();
}

/**
 * Get the SSO client ID
 *
 * @return string
 */
function get_sso_client_id(): string {
    return defined( 'WPDS_SSO_CLIENT_ID' ) ? WPDS_SSO_CLIENT_ID : get_option( 'wpds_sso_client_id' , '' );
}

/**
 * Get the SSO login URL
 *
 * @return string
 */

function get_sso_login_href(): string {

    $auth_query = http_build_query( [
        'wpds_sso_login' => 'true',
        'response_type'  => 'code',
        'client_id'      => get_sso_client_id(),
        'redirect_uri'   => rest_url( '/wpds-sso/login' ),
    ] );

    return sprintf( '%s?%s', get_sso_login_url(), $auth_query );
}

/**
 * Get the SSO client secret
 *
 * @return string
 */
function get_sso_client_secret(): string {
    return defined( 'WPDS_SSO_CLIENT_SECRET' ) ? WPDS_SSO_CLIENT_SECRET : get_option( 'wpds_sso_client_secret' , '' );
}

/**
 * Get the SSO redirect WP login
 *
 * @return string
 */
function get_sso_redirect_wp_login(): string {
    if ( is_super_admin() ) {
        return '';
    }

    return defined( 'WPDS_SSO_WP_LOGIN_REDIRECT' ) ? WPDS_SSO_WP_LOGIN_REDIRECT : '';
}

/**
 * Register the SSO login endpoint
 *
 * @return void
 */
add_action( 'rest_api_init', function (): void {
    register_rest_route(
        'wpds-sso',
        '/login',
        [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function ( WP_REST_Request $request ): WP_REST_Response {

                $code = $request->get_param( 'code' );

                if ( ! $code ) {
                    wp_safe_redirect( home_url( '?sso-login-failed=no-code' ) );
                    exit;
                }

                // Exchange code for token
                $response = wp_remote_post( get_sso_oauth_server_url() . '/oauth/token', [
                    'body' => [
                        'grant_type'    => 'authorization_code',
                        'client_id'     => get_sso_client_id(),
                        'client_secret' => get_sso_client_secret(),
                        'redirect_uri'  => rest_url( '/wpds-sso/login' ),
                        'code'          => $code,
                    ],
                ] );

                if ( is_wp_error( $response ) ) {
                    wp_safe_redirect( home_url( '?sso-login-failed=no-token' ) );
                    exit;
                }

                $body = json_decode( wp_remote_retrieve_body( $response ), true );

                if ( empty( $body['access_token'] ) ) {
                    wp_safe_redirect( home_url( '?sso-login-failed=token-empty' ) );
                    exit;
                }

                $response = wp_remote_get( get_sso_oauth_server_url() . '/oauth/me', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $body['access_token'],
                    ],
                ] );

                $user_data = json_decode( wp_remote_retrieve_body( $response ), true );

                if ( empty( $user_data['user_email'] ) ) {
                    wp_safe_redirect( home_url( '?sso-login-failed=email-empty' ) );
                    exit;
                }

                $user = get_user_by( 'email', sanitize_email( $user_data['user_email'] ) );

                if ( ! $user ) {
                    wp_safe_redirect( home_url( '?sso-login-failed=no-user' ) );
                    exit;
                }

                if ( is_multisite() ) {
                    if ( ! is_user_member_of_blog( $user->ID, get_current_blog_id() ) ) {
                        wp_safe_redirect( home_url( '?sso-login-failed=invalid-portal-user' ) );
                        exit;
                    }
                }

                wp_set_current_user( $user->ID );
                wp_set_auth_cookie( $user->ID );

                wp_safe_redirect( admin_url( 'index.php?page=wpds-dashboard' ) );
                exit;

            }
        ] );
} );

/**
 * Filter the SSO login URL
 *
 * @return string
 */
add_filter( 'wp_data_sync_sso_login_href', function (): string {
    return get_sso_login_href();
} );

/**
 * Redirect to the SSO login page if not logged in
 *
 * @return void
 */
add_action( 'init', function (): void {
    if ( strpos( $_SERVER['REQUEST_URI'], 'wp-login.php' ) !== false ) {
        if ( isset( $_GET['action'] ) && 'logout' === $_GET['action'] ) {
            return;
        }

        $path = get_sso_redirect_wp_login();

        if ( ! empty( $path ) ) {
            wp_safe_redirect( home_url( $path ) );
            exit;
        }

    }
} );

/**
 * Display SSO login error message
 *
 * @return void
 */
add_action( 'wpds_account_login_notice', function (): void {
    if ( isset( $_GET['sso-login-failed'] ) ) {
        printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Login failed. Please try again.', 'wpds-sso' ) );
    }
} );

/**
 * Add SSO tab to the admin menu
 *
 * @param array $tabs
 *
 * @return array
 */
add_filter( 'wp_data_sync_admin_tabs', function ( array $tabs ): array {
    $tabs['sso'] = [
        'label' => __( 'SSO', 'wpds-sso' ),
    ];

    return $tabs;
} );

/**
 * Add SSO settings to the admin menu
 *
 * @param array $settings
 *
 * @return array
 */
add_filter( 'wp_data_sync_settings', function ( array $settings ): array {
    $settings['sso'] = [
        [
            'key'       => 'wpds_sso_server_url',
            'label'     => __( 'Oauth Server URL', 'wpds-sso' ),
            'callback'  => 'input',
            'no_report' => true,
            'info'      => __( 'URL where aouth server is hosted.', 'wpds-sso' ),
            'args'      => [
                'sanitize_callback' => 'sanitize_url',
                'basename'          => 'text-input',
                'type'              => 'url',
                'class'             => 'regular-text',
                'placeholder'       => 'https://example.com'
            ]
        ],
        [
            'key'       => 'wpds_sso_login_path',
            'label'     => __( 'Logn Path', 'wpds-sso' ),
            'callback'  => 'input',
            'no_report' => true,
            'info'      => __( 'Path to the logon page where ouath server is hosted.', 'wpds-sso' ),
            'args'      => [
                'sanitize_callback' => 'sanitize_url',
                'basename'          => 'text-input',
                'type'              => 'text',
                'class'             => 'regular-text',
                'placeholder'       => '/login/'
            ]
        ],
        [
            'key'       => 'wpds_sso_client_id',
            'label'     => __( 'Client ID', 'wpds-sso' ),
            'callback'  => 'input',
            'no_report' => true,
            'info'      => __( 'Oauth server client ID.', 'wpds-sso' ),
            'args'      => [
                'sanitize_callback' => 'sanitize_text_field',
                'basename'          => 'text-input',
                'type'              => 'password',
                'class'             => 'regular-text',
                'placeholder'       => ''
            ]
        ],
        [
            'key'       => 'wpds_sso_client_secret',
            'label'     => __( 'Client Secret', 'wpds-sso' ),
            'callback'  => 'input',
            'no_report' => true,
            'info'      => __( 'Oauth server client secret.', 'wpds-sso' ),
            'args'      => [
                'sanitize_callback' => 'sanitize_text_field',
                'basename'          => 'text-input',
                'type'              => 'password',
                'class'             => 'regular-text',
                'placeholder'       => ''
            ]
        ],
    ];

    return $settings;
} );

/**
 * Redirect to the Oauth authorization URL.
 *
 * Allows for custom login pages.
 *
 * @return void
 */
add_action( 'init', function(): void {

    if ( is_user_logged_in() && isset( $_GET['wpds_sso_login'] ) ) {

        $auth_query = http_build_query( [
            'response_type' => $_GET['response_type'] ?? '',
            'client_id'     => $_GET['client_id'] ?? '',
            'redirect_uri'  => $_GET['redirect_uri'] ?? '',
        ] );

        $url = home_url( sprintf( '/oauth/authorize?%s', $auth_query ) );

        wp_safe_redirect( $url );
        exit;

    }
});

/**
 * Add SSO login button shortcode
 *
 * @param array $attrs
 *
 * @return string
 */
add_shortcode( 'wpds_sso_login_button', function( array $attrs ): string {

    $attrs = shortcode_atts( [
        'text' => __( 'Login with SSO', 'wpds-sso' )
    ], $attrs );

    return sprintf( '<a href="%s" class="wpds-sso-login-link">%s</a>', get_sso_login_href(), $attrs['text'] );
} );