<?php
/**
 * SEO meta change notifier.
 *
 * Monitors post meta changes on WooCommerce products and notifies our
 * backend when a canonical SEO field (page_title, meta_description, etc.)
 * is written by an external SEO plugin (Yoast, Rank Math, AIOSEO) or
 * directly in the WordPress admin.
 *
 * Changes made by our own REST bridge are excluded via a static flag
 * to prevent notification loops: widget writes meta → notifier fires →
 * backend re-syncs → widget writes again.
 *
 * Multiple meta updates within a single request are batched and sent
 * as one notification per product on the `shutdown` hook.
 *
 * @package Studio1119\Connector
 */

namespace Studio1119\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notifies the remote backend when SEO meta changes on a product.
 */
class SEO_Meta_Notifier {

	/**
	 * Pending changes keyed by product ID.
	 *
	 * @var array<int, array<string, string>>
	 */
	private static $pending = array();

	/**
	 * Whether the shutdown delivery hook has been registered.
	 *
	 * @var bool
	 */
	private static $shutdown_registered = false;

	/**
	 * Cached reverse map of meta_key → canonical_field for the current mode.
	 *
	 * @var array<string, string>|null
	 */
	private static $reverse_map = null;

	/**
	 * Hook into post meta write actions.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 4 );
	}

	/**
	 * Called when any post meta is added or updated.
	 *
	 * Filters to only canonical SEO fields on product posts, skipping
	 * writes made by our own REST bridge to prevent notification loops.
	 *
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $object_id  The post ID.
	 * @param string $meta_key   The meta key being written.
	 * @param mixed  $meta_value The new meta value.
	 * @return void
	 */
	public static function on_meta_change( $meta_id, $object_id, $meta_key, $meta_value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		// Skip writes made by our own REST bridge.
		if ( Rest_Bridge::is_writing() ) {
			return;
		}

		// Only care about products.
		if ( 'product' !== get_post_type( $object_id ) ) {
			return;
		}

		// Check if this meta key maps to a canonical SEO field.
		$field = self::reverse_lookup( $meta_key );
		if ( ! $field ) {
			return;
		}

		// Collect the change — multiple fields per product are batched.
		$value = is_string( $meta_value ) ? $meta_value : '';

		self::$pending[ $object_id ][ $field ] = $value;

		// Register shutdown handler once to deliver all batched changes.
		if ( ! self::$shutdown_registered ) {
			add_action( 'shutdown', array( __CLASS__, 'deliver' ) );
			self::$shutdown_registered = true;
		}
	}

	/**
	 * Deliver all batched SEO meta change notifications on shutdown.
	 *
	 * Sends a non-blocking POST to the main app for each product whose
	 * SEO fields changed during this request.
	 *
	 * @return void
	 */
	public static function deliver() {
		if ( empty( self::$pending ) ) {
			return;
		}

		$callback_url = self::get_callback_url();
		if ( ! $callback_url ) {
			return;
		}

		$site_url = get_site_url();
		$mode     = SEO_Plugin_Detector::detect();

		foreach ( self::$pending as $product_id => $fields ) {
			wp_remote_post(
				$callback_url,
				array(
					'body'     => wp_json_encode(
						array(
							'event'      => 'seo.updated',
							'product_id' => (int) $product_id,
							'fields'     => $fields,
							'mode'       => $mode,
							'site_url'   => $site_url,
						)
					),
					'headers'  => array( 'Content-Type' => 'application/json' ),
					'timeout'  => 5,
					'blocking' => false,
				)
			);
		}

		// Clear pending after delivery.
		self::$pending = array();
	}

	/**
	 * Look up the canonical field name for a given meta key in the current mode.
	 *
	 * Builds a reverse map on first call and caches it for the request.
	 *
	 * @param string $meta_key The WordPress meta key.
	 * @return string|null The canonical field name, or null if not a known SEO key.
	 */
	public static function reverse_lookup( $meta_key ) {
		if ( null === self::$reverse_map ) {
			self::$reverse_map = array();
			$mode              = Plugin::get_detected_mode();
			foreach ( Field_Mapper::canonical_fields() as $field ) {
				$key = Field_Mapper::meta_key( $field, $mode );
				if ( $key ) {
					self::$reverse_map[ $key ] = $field;
				}
			}
		}
		return isset( self::$reverse_map[ $meta_key ] ) ? self::$reverse_map[ $meta_key ] : null;
	}

	/**
	 * Build the callback URL from the WIDGET_URL constant.
	 *
	 * @return string|null The full URL to POST notifications to, or null if unavailable.
	 */
	private static function get_callback_url() {
		$widget_url = Plugin::const_value( 'WIDGET_URL' );
		if ( ! $widget_url ) {
			return null;
		}
		return rtrim( $widget_url, '/' ) . '/api/woocommerce/webhooks/seo-meta';
	}

	/**
	 * Reset internal state. Used by tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$pending             = array();
		self::$shutdown_registered = false;
		self::$reverse_map         = null;
	}
}
