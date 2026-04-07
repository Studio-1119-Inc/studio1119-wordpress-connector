<?php
/**
 * REST bridge: minimal custom REST endpoints the widget uses to read/write
 * SEO meta through the four-mode field mapper, without having to know the
 * underlying meta key schema itself.
 *
 * All endpoints require either a logged-in WP user with `manage_woocommerce`
 * capability (nonce auth from the widget iframe) or valid WC API key Basic
 * Auth credentials (server-to-server calls from the remote backend).
 *
 * Exposed routes (namespace: studio1119/v1):
 *   GET  /seo/status
 *   GET  /seo/product/<id>
 *   POST /seo/product/<id>   body: { page_title?, meta_description?, og_title?, og_description?, meta_keywords? }
 *
 * @package Studio1119\Connector
 */

namespace Studio1119\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides REST API endpoints for reading and writing product SEO meta
 * through the four-mode field mapper.
 */
class Rest_Bridge {

	const NAMESPACE_ROOT = 'studio1119/v1';

	/**
	 * Flag indicating whether the REST bridge is currently writing meta.
	 *
	 * Set to true during update_product_seo() so that SEO_Meta_Notifier
	 * can skip notifications for writes originating from our own widget.
	 *
	 * @var bool
	 */
	private static $writing = false;

	/**
	 * Hook into rest_api_init.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register all REST routes for the SEO bridge.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_ROOT,
			'/seo/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'callback'            => array( __CLASS__, 'get_status' ),
			)
		);

		// Token verification endpoint — used by the remote backend to verify
		// one-time widget tokens. Authenticated via WC HTTP Basic Auth
		// (consumer_key:consumer_secret), not WP nonce.
		register_rest_route(
			self::NAMESPACE_ROOT,
			'/verify-token',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( 'Studio1119\Connector\Widget_Auth', 'check_wc_auth' ),
				'callback'            => array( __CLASS__, 'verify_widget_token' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_ROOT,
			'/seo/product/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array( __CLASS__, 'check_permission' ),
					'callback'            => array( __CLASS__, 'get_product_seo' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $value ) {
								return is_numeric( $value );
							},
						),
					),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => array( __CLASS__, 'check_permission' ),
					'callback'            => array( __CLASS__, 'update_product_seo' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $value ) {
								return is_numeric( $value );
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Whether the REST bridge is currently writing post meta.
	 *
	 * Used by SEO_Meta_Notifier to skip notifications for our own writes.
	 *
	 * @return bool
	 */
	public static function is_writing() {
		return self::$writing;
	}

	/**
	 * Check whether the request is authorized.
	 *
	 * Accepts either:
	 *   1. A logged-in WP user with `manage_woocommerce` capability (nonce-based, widget iframe).
	 *   2. WC API key Basic Auth (server-to-server calls from the remote backend).
	 *
	 * @return bool
	 */
	public static function check_permission() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		// Fall back to WC API key auth for server-to-server requests.
		return Widget_Auth::check_wc_auth();
	}

	/**
	 * Return connector status: detected SEO mode, site URL, and WP/WC versions.
	 *
	 * @param \WP_REST_Request $request The REST request (unused).
	 * @return \WP_REST_Response
	 */
	public static function get_status( \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return rest_ensure_response(
			array(
				'mode'        => Plugin::get_detected_mode(),
				'site_url'    => get_site_url(),
				'admin_email' => get_option( 'admin_email', '' ),
				'wp_version'  => get_bloginfo( 'version' ),
				'wc_version'  => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			)
		);
	}

	/**
	 * Read SEO meta for a single product.
	 *
	 * @param \WP_REST_Request $request The REST request containing the product ID.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_product_seo( \WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post || 'product' !== $post->post_type ) {
			return new \WP_Error( 'not_found', 'Product not found', array( 'status' => 404 ) );
		}

		$mode = Plugin::get_detected_mode();
		$out  = array(
			'mode' => $mode,
			'id'   => $post_id,
		);

		// AIOSEO stores data in its own table, not post_meta.
		if ( SEO_Plugin_Detector::MODE_AIOSEO === $mode ) {
			$row = self::aioseo_get_row( $post_id );
			$out['page_title']       = $row ? (string) $row->title : null;
			$out['meta_description'] = $row ? (string) $row->description : null;
			$out['og_title']         = $row ? (string) $row->og_title : null;
			$out['og_description']   = $row ? (string) $row->og_description : null;
			// AIOSEO stores keyphrases as JSON: {"focus":{"keyphrase":"...","score":0,...}}
			$out['meta_keywords']    = null;
			if ( $row && ! empty( $row->keyphrases ) ) {
				$kp = json_decode( $row->keyphrases, true );
				if ( isset( $kp['focus']['keyphrase'] ) ) {
					$out['meta_keywords'] = $kp['focus']['keyphrase'];
				}
			}
		} else {
			foreach ( Field_Mapper::canonical_fields() as $field ) {
				$key           = Field_Mapper::meta_key( $field, $mode );
				$out[ $field ] = $key ? (string) get_post_meta( $post_id, $key, true ) : null;
			}
		}

		return rest_ensure_response( $out );
	}

	/**
	 * Write SEO meta for a single product.
	 *
	 * @param \WP_REST_Request $request The REST request containing the product ID and fields.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_product_seo( \WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post || 'product' !== $post->post_type ) {
			return new \WP_Error( 'not_found', 'Product not found', array( 'status' => 404 ) );
		}

		$mode    = Plugin::get_detected_mode();
		$updated = array();

		// Set writing flag so SEO_Meta_Notifier skips our own writes.
		self::$writing = true;

		// AIOSEO stores data in its own table, not post_meta.
		if ( SEO_Plugin_Detector::MODE_AIOSEO === $mode ) {
			$col_map = array(
				'page_title'       => 'title',
				'meta_description' => 'description',
				'og_title'         => 'og_title',
				'og_description'   => 'og_description',
			);
			$data = array();
			foreach ( $col_map as $field => $col ) {
				$value = $request->get_param( $field );
				if ( null !== $value ) {
					$data[ $col ]      = sanitize_text_field( (string) $value );
					$updated[ $field ] = $col;
				}
			}
			// Handle keyphrases (focus keyword) — stored as JSON in AIOSEO.
			$keywords = $request->get_param( 'meta_keywords' );
			if ( null !== $keywords ) {
				$keyphrase = is_array( $keywords ) ? implode( ', ', $keywords ) : sanitize_text_field( (string) $keywords );
				$data['keyphrases'] = wp_json_encode(
					array(
						'focus'      => array(
							'keyphrase' => $keyphrase,
							'score'     => 0,
							'analysis'  => array(),
						),
						'additional' => array(),
					)
				);
				$updated['meta_keywords'] = 'keyphrases';
			}
			if ( ! empty( $data ) ) {
				self::aioseo_upsert_row( $post_id, $data );
			}
		} else {
			foreach ( Field_Mapper::canonical_fields() as $field ) {
				$value = $request->get_param( $field );
				if ( null === $value ) {
					continue;
				}
				$key = Field_Mapper::meta_key( $field, $mode );
				if ( ! $key ) {
					continue; // Field not storable in this mode (e.g. meta_keywords outside Yoast).
				}
				update_post_meta( $post_id, $key, sanitize_text_field( (string) $value ) );
				$updated[ $field ] = $key;
			}
		}

		self::$writing = false;

		return rest_ensure_response(
			array(
				'updated' => $updated,
				'mode'    => $mode,
			)
		);
	}

	/**
	 * Verify a one-time widget token.
	 *
	 * Called by the remote backend to confirm a widget session is legitimate.
	 * The token was generated on admin page load and passed via the iframe URL.
	 *
	 * @param \WP_REST_Request $request The REST request containing the token.
	 * @return \WP_REST_Response
	 */
	public static function verify_widget_token( \WP_REST_Request $request ) {
		$token = $request->get_param( 'token' );

		$data = Widget_Auth::verify_token( $token );
		if ( false === $data ) {
			return rest_ensure_response(
				array(
					'valid' => false,
					'error' => 'Invalid or expired token',
				)
			);
		}

		return rest_ensure_response(
			array(
				'valid'    => true,
				'user_id'  => $data['user_id'],
				'site_url' => get_site_url(),
			)
		);
	}

	// =========================================================================
	// AIOSEO custom table helpers
	// =========================================================================

	/**
	 * Read the AIOSEO row for a given post.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return object|null  Row from wp_aioseo_posts, or null.
	 */
	private static function aioseo_get_row( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT title, description, og_title, og_description, keyphrases FROM {$table} WHERE post_id = %d", $post_id )
		);
	}

	/**
	 * Insert or update the AIOSEO row for a given post.
	 *
	 * @param int   $post_id WordPress post ID.
	 * @param array $data    Column => value pairs to write.
	 * @return void
	 */
	private static function aioseo_upsert_row( $post_id, $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d", $post_id )
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $data, array( 'post_id' => $post_id ) );
		} else {
			$data['post_id'] = $post_id;
			$data['created']  = current_time( 'mysql' );
			$data['updated']  = current_time( 'mysql' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( $table, $data );
		}
	}
}
