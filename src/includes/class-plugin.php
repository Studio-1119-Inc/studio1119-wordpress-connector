<?php
/**
 * Main plugin bootstrap. Wires all subsystems together.
 *
 * The build step substitutes {{APP_CONST_PREFIX}} in the main plugin file with
 * the per-app constant prefix (e.g. CATASEO, TRUSYNC). All other files discover
 * that prefix at runtime via self::const_prefix() so this codebase is fully
 * app-agnostic — adding a new app requires only an apps.json entry, no PHP
 * changes.
 *
 * @package Studio1119\Connector
 */

namespace Studio1119\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap class. Wires subsystems and provides per-app constant helpers.
 */
class Plugin {

	const DETECTED_MODE_OPTION_SUFFIX   = '_detected_seo_mode';
	const MODE_CHECKED_AT_OPTION_SUFFIX = '_mode_checked_at';

	/**
	 * Boot the plugin: load text domain, register hooks and subsystems.
	 *
	 * @return void
	 */
	public static function boot() {
		load_plugin_textdomain(
			self::const_value( 'TEXT_DOMAIN' ),
			false,
			dirname( plugin_basename( self::const_value( 'PLUGIN_FILE' ) ) ) . '/languages'
		);

		// Declare compatibility with WooCommerce HPOS (High-Performance Order Storage).
		// This plugin only reads/writes product SEO meta — it never touches orders.
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_hpos_compatibility' ) );

		// Re-detect active SEO plugin on every admin page load so mode is always current.
		add_action( 'admin_init', array( __CLASS__, 'refresh_detected_mode' ) );

		// Tell WooCommerce to run its REST authentication (consumer key/secret)
		// for our custom namespace. Without this, WC only authenticates requests
		// to its own wc/ namespace, and our studio1119/v1 endpoints fall back to
		// standard WP auth (which rejects server-to-server Basic Auth calls).
		add_filter(
			'woocommerce_rest_is_request_to_rest_api',
			function ( $is_rest ) {
				if ( ! empty( $_SERVER['REQUEST_URI'] ) && false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'studio1119/v1' ) ) {
					return true;
				}
				return $is_rest;
			}
		);

		// In development (ngrok-free.app URLs), add the header that bypasses
		// ngrok's browser interstitial on outbound WP HTTP requests (e.g. the
		// WC OAuth callback to our CataSEO backend).
		$widget_url = self::const_value( 'WIDGET_URL' );
		if ( $widget_url && false !== strpos( $widget_url, 'ngrok-free.app' ) ) {
			add_filter(
				'http_request_args',
				function ( $args ) {
					$args['headers']['ngrok-skip-browser-warning'] = '1';
					return $args;
				}
			);
		}

		Admin_Page::register();
		Rest_Bridge::register();
		Standalone_Head::register();
		SEO_Meta_Notifier::register();
		Taxonomy_Notifier::register();
	}

	/**
	 * Declare HPOS compatibility with WooCommerce.
	 *
	 * @return void
	 */
	public static function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			$plugin_file = self::const_value( 'PLUGIN_FILE' );
			if ( $plugin_file ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', $plugin_file, true );
			}
		}
	}

	/**
	 * Activation hook callback: detect the active SEO mode.
	 *
	 * @return void
	 */
	public static function activate() {
		self::refresh_detected_mode();
	}

	/**
	 * Deactivation hook callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// No scheduled jobs or external state to tear down. Options are preserved until uninstall.
	}

	/**
	 * Uninstall hook callback: remove plugin options.
	 *
	 * @return void
	 */
	public static function uninstall() {
		$prefix = self::const_value( 'OPTION_PREFIX' );
		delete_option( $prefix . self::DETECTED_MODE_OPTION_SUFFIX );
		delete_option( $prefix . self::MODE_CHECKED_AT_OPTION_SUFFIX );
	}

	/**
	 * Re-detect the active SEO plugin and cache the result.
	 *
	 * @return void
	 */
	public static function refresh_detected_mode() {
		$mode   = SEO_Plugin_Detector::detect();
		$prefix = self::const_value( 'OPTION_PREFIX' );
		update_option( $prefix . self::DETECTED_MODE_OPTION_SUFFIX, $mode );
		update_option( $prefix . self::MODE_CHECKED_AT_OPTION_SUFFIX, time() );
	}

	/**
	 * Get the cached detected SEO mode, falling back to live detection.
	 *
	 * @return string One of SEO_Plugin_Detector::MODE_* constants.
	 */
	public static function get_detected_mode() {
		$prefix = self::const_value( 'OPTION_PREFIX' );
		$mode   = get_option( $prefix . self::DETECTED_MODE_OPTION_SUFFIX );
		if ( ! $mode ) {
			$mode = SEO_Plugin_Detector::detect();
		}
		return $mode;
	}

	/**
	 * Return the value of a per-app build constant by its suffix.
	 *
	 * The main plugin file defines <PREFIX>_VERSION, <PREFIX>_PLUGIN_FILE, etc.
	 * This helper resolves <PREFIX> once (by convention: the user-defined
	 * constant whose name ends in _VERSION and has a sibling _PLUGIN_FILE) and
	 * then returns the value of <PREFIX>_<SUFFIX>.
	 *
	 * @param string $suffix e.g. 'VERSION', 'WIDGET_URL', 'MENU_TITLE'.
	 * @return mixed|null    The constant value, or null if undefined.
	 */
	public static function const_value( $suffix ) {
		$prefix = self::const_prefix();
		if ( ! $prefix ) {
			return null;
		}
		$name = $prefix . '_' . $suffix;
		return defined( $name ) ? constant( $name ) : null;
	}

	/**
	 * Discover the per-app constant prefix at runtime.
	 *
	 * Scans user-defined constants for one ending in _VERSION that also has a
	 * sibling _PLUGIN_FILE constant. The prefix is cached for the request.
	 *
	 * @return string The constant prefix (e.g. 'CATASEO'), or empty string if not found.
	 */
	public static function const_prefix() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$user_constants = get_defined_constants( true );
		$user_constants = isset( $user_constants['user'] ) ? $user_constants['user'] : array();
		foreach ( $user_constants as $name => $value ) {
			if ( '_VERSION' === substr( $name, -8 ) && defined( substr( $name, 0, -8 ) . '_PLUGIN_FILE' ) ) {
				$cached = substr( $name, 0, -8 );
				return $cached;
			}
		}
		$cached = '';
		return $cached;
	}
}
