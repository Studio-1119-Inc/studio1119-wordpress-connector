<?php
/**
 * REST bridge: minimal custom REST endpoints the widget uses to read/write
 * SEO meta through the four-mode field mapper, without having to know the
 * underlying meta key schema itself.
 *
 * All endpoints require `manage_woocommerce` capability. Authentication is
 * via the standard WP REST nonce that Admin_Page injected into the widget
 * boot payload.
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

class Rest_Bridge {

	const NAMESPACE_ROOT = 'studio1119/v1';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

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

	public static function check_permission() {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function get_status( \WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'mode'       => Plugin::get_detected_mode(),
				'site_url'   => get_site_url(),
				'wp_version' => get_bloginfo( 'version' ),
				'wc_version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			)
		);
	}

	public static function get_product_seo( \WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'product' ) {
			return new \WP_Error( 'not_found', 'Product not found', array( 'status' => 404 ) );
		}

		$mode = Plugin::get_detected_mode();
		$out  = array( 'mode' => $mode, 'id' => $post_id );
		foreach ( Field_Mapper::canonical_fields() as $field ) {
			$key            = Field_Mapper::meta_key( $field, $mode );
			$out[ $field ]  = $key ? (string) get_post_meta( $post_id, $key, true ) : null;
		}
		return rest_ensure_response( $out );
	}

	public static function update_product_seo( \WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'product' ) {
			return new \WP_Error( 'not_found', 'Product not found', array( 'status' => 404 ) );
		}

		$mode    = Plugin::get_detected_mode();
		$updated = array();

		foreach ( Field_Mapper::canonical_fields() as $field ) {
			$value = $request->get_param( $field );
			if ( $value === null ) {
				continue;
			}
			$key = Field_Mapper::meta_key( $field, $mode );
			if ( ! $key ) {
				continue; // Field not storable in this mode (e.g. meta_keywords outside Yoast).
			}
			update_post_meta( $post_id, $key, sanitize_text_field( (string) $value ) );
			$updated[ $field ] = $key;
		}

		return rest_ensure_response( array( 'updated' => $updated, 'mode' => $mode ) );
	}
}
