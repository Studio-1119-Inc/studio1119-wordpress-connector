<?php
/**
 * Widget authentication: one-time token generation and verification.
 *
 * Each time the WP admin page loads, a random token is generated and stored
 * as a WordPress transient (5-minute TTL). The token is included in the
 * iframe URL that loads the remote widget. The remote backend verifies the
 * token by calling back to this site's REST endpoint, ensuring only
 * authenticated WP admins can create widget sessions.
 *
 * @package {{APP_NAMESPACE}}
 */

namespace {{APP_NAMESPACE}};

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates and verifies one-time widget authentication tokens.
 */
class Widget_Auth {

	/**
	 * Transient key prefix for widget tokens.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'studio1119_wt_';

	/**
	 * Token time-to-live in seconds (5 minutes).
	 *
	 * @var int
	 */
	const TOKEN_TTL = 300;

	/**
	 * Generate a one-time token and store it as a WP transient.
	 *
	 * @return string The generated token.
	 */
	public static function generate_token() {
		$token = wp_generate_password( 48, false, false );
		$data  = array(
			'user_id'    => get_current_user_id(),
			'created_at' => time(),
		);

		set_transient( self::TRANSIENT_PREFIX . wp_hash( $token ), $data, self::TOKEN_TTL );

		return $token;
	}

	/**
	 * Verify a one-time token and consume it (delete after use).
	 *
	 * @param string $token The token to verify.
	 * @return array|false The token data (user_id, created_at) on success, false on failure.
	 */
	public static function verify_token( $token ) {
		if ( empty( $token ) ) {
			return false;
		}

		$key  = self::TRANSIENT_PREFIX . wp_hash( $token );
		$data = get_transient( $key );

		if ( false === $data ) {
			return false;
		}

		// Consume the token — one-time use.
		delete_transient( $key );

		return $data;
	}

	/**
	 * Validate WooCommerce HTTP Basic Auth credentials.
	 *
	 * Checks the Authorization header against WC's woocommerce_api_keys table.
	 * Used for server-to-server calls from the remote backend.
	 *
	 * @return bool True if credentials are valid.
	 */
	public static function check_wc_auth() {
		$auth_header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth_header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( empty( $auth_header ) || 0 !== strpos( $auth_header, 'Basic ' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( substr( $auth_header, 6 ) );
		if ( false === strpos( $decoded, ':' ) ) {
			return false;
		}

		list( $consumer_key, $consumer_secret ) = explode( ':', $decoded, 2 );

		if ( ! function_exists( 'wc_api_hash' ) ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WC provides no public API for verifying consumer keys; caching auth checks would be a security risk.
		$key_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT consumer_secret, permissions FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
				wc_api_hash( $consumer_key )
			)
		);

		if ( ! $key_data ) {
			return false;
		}

		return hash_equals( $key_data->consumer_secret, $consumer_secret );
	}
}
