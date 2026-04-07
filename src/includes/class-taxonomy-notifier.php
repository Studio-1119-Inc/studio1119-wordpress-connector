<?php
/**
 * Taxonomy change notifier.
 *
 * Monitors changes to WooCommerce product categories and brands (product_brand
 * taxonomy) and notifies our backend so it can re-sync.
 *
 * Batches multiple changes within a single request and delivers on shutdown.
 *
 * @package Studio1119\Connector
 */

namespace Studio1119\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notifies the remote backend when product categories or brands change.
 */
class Taxonomy_Notifier {

	/**
	 * Pending taxonomy change events.
	 *
	 * @var array<int, array{taxonomy: string, action: string, term_id: int, name: string}>
	 */
	private static $pending = array();

	/**
	 * Whether the shutdown delivery hook has been registered.
	 *
	 * @var bool
	 */
	private static $shutdown_registered = false;

	/**
	 * Taxonomies we monitor.
	 *
	 * @var string[]
	 */
	private static $watched_taxonomies = array( 'product_cat', 'product_brand' );

	/**
	 * Hook into taxonomy term lifecycle actions.
	 *
	 * @return void
	 */
	public static function register() {
		foreach ( self::$watched_taxonomies as $taxonomy ) {
			add_action( "created_{$taxonomy}", array( __CLASS__, 'on_term_created' ), 10, 3 );
			add_action( "edited_{$taxonomy}", array( __CLASS__, 'on_term_edited' ), 10, 3 );
			add_action( "delete_{$taxonomy}", array( __CLASS__, 'on_term_deleted' ), 10, 4 );
		}
	}

	/**
	 * Called when a term is created.
	 *
	 * @param int   $term_id  Term ID.
	 * @param int   $tt_id    Term taxonomy ID.
	 * @param array $args     Arguments passed to wp_insert_term().
	 * @return void
	 */
	public static function on_term_created( $term_id, $tt_id, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$taxonomy = self::get_current_taxonomy( $term_id );
		if ( ! $taxonomy ) {
			return;
		}
		self::queue_event( $term_id, $taxonomy, 'created' );
	}

	/**
	 * Called when a term is edited.
	 *
	 * @param int   $term_id  Term ID.
	 * @param int   $tt_id    Term taxonomy ID.
	 * @param array $args     Arguments passed to wp_update_term().
	 * @return void
	 */
	public static function on_term_edited( $term_id, $tt_id, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$taxonomy = self::get_current_taxonomy( $term_id );
		if ( ! $taxonomy ) {
			return;
		}
		self::queue_event( $term_id, $taxonomy, 'updated' );
	}

	/**
	 * Called when a term is about to be deleted.
	 *
	 * @param int    $term_id       Term ID.
	 * @param int    $tt_id         Term taxonomy ID.
	 * @param string $taxonomy      Taxonomy slug.
	 * @param mixed  $deleted_term  The deleted term object.
	 * @return void
	 */
	public static function on_term_deleted( $term_id, $tt_id, $taxonomy, $deleted_term = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! in_array( $taxonomy, self::$watched_taxonomies, true ) ) {
			return;
		}
		self::queue_event( $term_id, $taxonomy, 'deleted' );
	}

	/**
	 * Queue a taxonomy change event for delivery on shutdown.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $action   One of 'created', 'updated', 'deleted'.
	 * @return void
	 */
	private static function queue_event( $term_id, $taxonomy, $action ) {
		$term = get_term( $term_id, $taxonomy );
		$name = ( $term && ! is_wp_error( $term ) ) ? $term->name : '';

		self::$pending[] = array(
			'taxonomy' => $taxonomy,
			'action'   => $action,
			'term_id'  => $term_id,
			'name'     => $name,
		);

		if ( ! self::$shutdown_registered ) {
			add_action( 'shutdown', array( __CLASS__, 'deliver' ) );
			self::$shutdown_registered = true;
		}
	}

	/**
	 * Deliver all batched taxonomy change notifications on shutdown.
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

		foreach ( self::$pending as $event ) {
			$type = 'product_brand' === $event['taxonomy'] ? 'brand' : 'category';

			wp_remote_post(
				$callback_url,
				array(
					'body'     => wp_json_encode(
						array(
							'event'    => "{$type}.{$event['action']}",
							'term_id'  => $event['term_id'],
							'name'     => $event['name'],
							'taxonomy' => $event['taxonomy'],
							'type'     => $type,
							'site_url' => $site_url,
						)
					),
					'headers'  => array( 'Content-Type' => 'application/json' ),
					'timeout'  => 5,
					'blocking' => false,
				)
			);
		}

		self::$pending = array();
	}

	/**
	 * Determine the taxonomy for a term from the created/edited hooks.
	 *
	 * @param int $term_id Term ID.
	 * @return string|null Taxonomy slug, or null if not a watched taxonomy.
	 */
	private static function get_current_taxonomy( $term_id ) {
		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		if ( ! in_array( $term->taxonomy, self::$watched_taxonomies, true ) ) {
			return null;
		}
		return $term->taxonomy;
	}

	/**
	 * Build the callback URL from the WIDGET_URL constant.
	 *
	 * @return string|null
	 */
	private static function get_callback_url() {
		$widget_url = Plugin::const_value( 'WIDGET_URL' );
		if ( ! $widget_url ) {
			return null;
		}
		return rtrim( $widget_url, '/' ) . '/api/woocommerce/webhooks/taxonomy';
	}

	/**
	 * Reset internal state. Used by tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$pending             = array();
		self::$shutdown_registered = false;
	}
}
