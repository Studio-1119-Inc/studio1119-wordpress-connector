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
	 * Extract HTTP Basic Auth credentials from the request.
	 *
	 * Tries two sources, because PHP exposes Basic Auth differently depending
	 * on the server API:
	 *   1. The raw `Authorization` header ($_SERVER['HTTP_AUTHORIZATION'], or
	 *      REDIRECT_HTTP_AUTHORIZATION behind a rewrite). Present under CGI/
	 *      FastCGI, or when Apache is configured to pass the header through.
	 *   2. $_SERVER['PHP_AUTH_USER'] / ['PHP_AUTH_PW']. Under Apache + mod_php
	 *      the Basic header is consumed by the server and the decoded values
	 *      are exposed here instead, with HTTP_AUTHORIZATION often unset. This
	 *      is the path WooCommerce's own REST authentication reads, which is
	 *      why wc/* calls authenticate even when a header-only check fails.
	 *
	 * @return array{0:string,1:string} [ consumer_key, consumer_secret ]; empty strings if absent.
	 */
	private static function basic_auth_credentials() {
		$auth_header = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth_header = wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and split below; secret compared via hash_equals.
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth_header = wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and split below; secret compared via hash_equals.
		}

		if ( '' !== $auth_header && 0 === strpos( $auth_header, 'Basic ' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$decoded = base64_decode( substr( $auth_header, 6 ) );
			if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
				list( $key, $secret ) = explode( ':', $decoded, 2 );
				return array( sanitize_text_field( $key ), $secret );
			}
		}

		// Apache + mod_php fallback: server-decoded Basic credentials.
		if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			return array(
				sanitize_text_field( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) ),
				wp_unslash( $_SERVER['PHP_AUTH_PW'] ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared via hash_equals below.
			);
		}

		return array( '', '' );
	}

	/**
	 * Validate WooCommerce HTTP Basic Auth credentials.
	 *
	 * Checks the Authorization header against WC's woocommerce_api_keys table.
	 * Used for server-to-server calls from the remote backend. Verifies the
	 * key owner's WordPress capability and the key's permission level.
	 *
	 * @param string $required_permission 'read' or 'write'. Default 'read'.
	 * @return bool True if credentials are valid and sufficient.
	 */
	public static function check_wc_auth( $required_permission = 'read' ) {
		list( $consumer_key, $consumer_secret ) = self::basic_auth_credentials();

		if ( '' === $consumer_key || '' === $consumer_secret ) {
			return false;
		}

		if ( ! function_exists( 'wc_api_hash' ) ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WC provides no public API for verifying consumer keys; caching auth checks would be a security risk.
		$key_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT consumer_secret, permissions, user_id FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
				wc_api_hash( $consumer_key )
			)
		);

		if ( ! $key_data ) {
			return false;
		}

		if ( ! hash_equals( $key_data->consumer_secret, $consumer_secret ) ) {
			return false;
		}

		$user = get_userdata( (int) $key_data->user_id );
		if ( ! $user || ! $user->has_cap( 'manage_woocommerce' ) ) {
			return false;
		}

		if ( 'write' === $required_permission && 'read' === $key_data->permissions ) {
			return false;
		}

		return true;
	}
}
