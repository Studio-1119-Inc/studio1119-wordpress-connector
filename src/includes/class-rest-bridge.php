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
 *   GET  /blog/posts         query: { page?, per_page? }
 *   POST /blog/posts         body: { title, body?, summary?, is_published?, tags?, handle?, meta_description?, meta_keywords? }
 *   POST /blog/posts/<id>    body: any subset of the create fields above (only provided keys are written)
 *
 * Blog posts are native WordPress `post` post-type — no connector-specific
 * storage, unlike product SEO meta. The four-mode field mapper is reused
 * as-is for meta_description/meta_keywords since it is keyed by post ID, not
 * post type (ST1119-784).
 *
 * @package {{APP_NAMESPACE}}
 */

namespace {{APP_NAMESPACE}};

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
		// Token verification endpoint — used by the remote backend to verify
		// one-time widget tokens during SSO. Needed by every app type (SEO and
		// sync), so it is always registered. Authenticated via WC HTTP Basic
		// Auth (consumer_key:consumer_secret), not WP nonce.
		register_rest_route(
			self::NAMESPACE_ROOT,
			'/verify-token',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return Widget_Auth::check_wc_auth( 'write' );
				},
				'callback'            => array( __CLASS__, 'verify_widget_token' ),
			)
		);

		// SEO endpoints — only for SEO-type apps. Their callbacks depend on the
		// SEO subsystems (SEO_Plugin_Detector, Field_Mapper) that plugin.php
		// only loads when APP_TYPE is 'seo'; registering them for a sync app
		// would expose routes that fatal on a missing class when called.
		if ( 'seo' !== Plugin::const_value( 'APP_TYPE' ) ) {
			return;
		}

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

		register_rest_route(
			self::NAMESPACE_ROOT,
			'/blog/posts',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array( __CLASS__, 'check_permission' ),
					'callback'            => array( __CLASS__, 'list_blog_posts' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => array( __CLASS__, 'check_permission' ),
					'callback'            => array( __CLASS__, 'create_blog_post' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_ROOT,
			'/blog/posts/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'callback'            => array( __CLASS__, 'update_blog_post' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $value ) {
							return is_numeric( $value );
						},
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
	 * For WC API key auth, GET requests require at least 'read' permission
	 * and POST requests require 'write' or 'read_write' permission. The key
	 * owner's WordPress user must also have `manage_woocommerce` capability.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return bool
	 */
	public static function check_permission( \WP_REST_Request $request ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$required = 'GET' === $request->get_method() ? 'read' : 'write';
		return Widget_Auth::check_wc_auth( $required );
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
					continue; // Canonical field has no mapping for this mode.
				}
				$safe = is_array( $value ) ? implode( ', ', array_map( 'sanitize_text_field', $value ) ) : sanitize_text_field( (string) $value );
				update_post_meta( $post_id, $key, $safe );
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

	// =========================================================================
	// Blog post methods (ST1119-784)
	// =========================================================================

	/**
	 * List blog posts, newest first.
	 *
	 * @param \WP_REST_Request $request The REST request, with optional page/per_page params.
	 * @return \WP_REST_Response
	 */
	public static function list_blog_posts( \WP_REST_Request $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 50, $per_page ) : 10;

		$query = new \WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$posts = array();
		foreach ( $query->posts as $post ) {
			$posts[] = self::blog_post_to_array( $post );
		}

		return rest_ensure_response(
			array(
				'posts'    => $posts,
				'has_more' => $page < (int) $query->max_num_pages,
			)
		);
	}

	/**
	 * Create a blog post (native WordPress `post` post-type).
	 *
	 * @param \WP_REST_Request $request The REST request containing the post fields.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_blog_post( \WP_REST_Request $request ) {
		$title = $request->get_param( 'title' );
		if ( empty( $title ) ) {
			return new \WP_Error( 'missing_title', 'title is required', array( 'status' => 400 ) );
		}

		$postarr = array(
			'post_type'   => 'post',
			'post_title'  => sanitize_text_field( (string) $title ),
			'post_status' => $request->get_param( 'is_published' ) ? 'publish' : 'draft',
			// WordPress core has no free-text author field — post_author is a
			// user ID. Our interface's `author` is a display-name string
			// (meaningful on Shopify/BigCommerce, which accept one); there is
			// no WP equivalent, so it is intentionally not read here. Every
			// post is authored by the site's first administrator.
			'post_author' => self::default_author_id(),
		);

		$body = $request->get_param( 'body' );
		if ( null !== $body ) {
			$postarr['post_content'] = wp_kses_post( (string) $body );
		}
		$summary = $request->get_param( 'summary' );
		if ( null !== $summary ) {
			$postarr['post_excerpt'] = sanitize_text_field( (string) $summary );
		}
		$handle = $request->get_param( 'handle' );
		if ( ! empty( $handle ) ) {
			// wp_insert_post de-duplicates a colliding post_name on its own
			// (appends -2, -3, ...) — no explicit collision handling needed.
			$postarr['post_name'] = sanitize_title( (string) $handle );
		}

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$tags = $request->get_param( 'tags' );
		if ( is_array( $tags ) ) {
			wp_set_post_tags( $post_id, $tags, false );
		}

		self::write_blog_seo_meta( $post_id, $request );

		return rest_ensure_response( self::blog_post_to_array( get_post( $post_id ) ) );
	}

	/**
	 * Update a blog post's editable fields. Only provided keys are written.
	 *
	 * @param \WP_REST_Request $request The REST request containing the post ID and fields.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_blog_post( \WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new \WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
		}

		$postarr    = array( 'ID' => $post_id );
		$has_update = false;

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$postarr['post_title'] = sanitize_text_field( (string) $title );
			$has_update            = true;
		}
		$body = $request->get_param( 'body' );
		if ( null !== $body ) {
			$postarr['post_content'] = wp_kses_post( (string) $body );
			$has_update              = true;
		}
		$summary = $request->get_param( 'summary' );
		if ( null !== $summary ) {
			$postarr['post_excerpt'] = sanitize_text_field( (string) $summary );
			$has_update              = true;
		}
		$handle = $request->get_param( 'handle' );
		if ( null !== $handle ) {
			$postarr['post_name'] = sanitize_title( (string) $handle );
			$has_update           = true;
		}

		if ( $has_update ) {
			$result = wp_update_post( $postarr, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$tags = $request->get_param( 'tags' );
		if ( is_array( $tags ) ) {
			wp_set_post_tags( $post_id, $tags, false );
		}

		self::write_blog_seo_meta( $post_id, $request );

		return rest_ensure_response( self::blog_post_to_array( get_post( $post_id ) ) );
	}

	/**
	 * Map a WP_Post (post-type `post`) plus its SEO meta into the shape the
	 * SaaS backend's VendorBlogPost expects.
	 *
	 * @param \WP_Post $post The post to map.
	 * @return array
	 */
	private static function blog_post_to_array( \WP_Post $post ) {
		$mode = Plugin::get_detected_mode();

		$meta_description = null;
		$meta_keywords    = null;

		// AIOSEO stores data in its own table, not post_meta.
		if ( SEO_Plugin_Detector::MODE_AIOSEO === $mode ) {
			$row              = self::aioseo_get_row( $post->ID );
			$meta_description = $row ? (string) $row->description : null;
			if ( $row && ! empty( $row->keyphrases ) ) {
				$kp = json_decode( $row->keyphrases, true );
				if ( isset( $kp['focus']['keyphrase'] ) ) {
					$meta_keywords = $kp['focus']['keyphrase'];
				}
			}
		} else {
			$desc_key         = Field_Mapper::meta_key( 'meta_description', $mode );
			$meta_description = $desc_key ? (string) get_post_meta( $post->ID, $desc_key, true ) : null;
			$kw_key           = Field_Mapper::meta_key( 'meta_keywords', $mode );
			$meta_keywords    = $kw_key ? (string) get_post_meta( $post->ID, $kw_key, true ) : null;
		}

		$tags          = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
		$thumbnail_url = get_the_post_thumbnail_url( $post->ID, 'full' );

		return array(
			'id'               => (string) $post->ID,
			'title'            => $post->post_title,
			'url'              => get_permalink( $post ),
			'published_date'   => 'publish' === $post->post_status ? mysql2date( 'c', $post->post_date_gmt, false ) : null,
			'is_published'     => 'publish' === $post->post_status,
			'author'           => get_the_author_meta( 'display_name', $post->post_author ),
			'thumbnail_path'   => $thumbnail_url ? $thumbnail_url : null,
			'body'             => $post->post_content,
			'summary'          => $post->post_excerpt,
			'tags'             => is_array( $tags ) ? $tags : array(),
			'meta_description' => $meta_description,
			'meta_keywords'    => $meta_keywords,
			'preview_url'      => get_preview_post_link( $post ),
			'handle'           => $post->post_name,
		);
	}

	/**
	 * Write meta_description/meta_keywords for a blog post, reusing the same
	 * four-mode field mapper (and AIOSEO table) that product SEO meta uses —
	 * both are keyed by post ID, not post type.
	 *
	 * @param int              $post_id WordPress post ID.
	 * @param \WP_REST_Request $request The REST request containing the fields.
	 * @return void
	 */
	private static function write_blog_seo_meta( $post_id, \WP_REST_Request $request ) {
		$mode = Plugin::get_detected_mode();

		self::$writing = true;

		if ( SEO_Plugin_Detector::MODE_AIOSEO === $mode ) {
			$data        = array();
			$description = $request->get_param( 'meta_description' );
			if ( null !== $description ) {
				$data['description'] = sanitize_text_field( (string) $description );
			}
			$keywords = $request->get_param( 'meta_keywords' );
			if ( null !== $keywords ) {
				$data['keyphrases'] = wp_json_encode(
					array(
						'focus'      => array(
							'keyphrase' => sanitize_text_field( (string) $keywords ),
							'score'     => 0,
							'analysis'  => array(),
						),
						'additional' => array(),
					)
				);
			}
			if ( ! empty( $data ) ) {
				self::aioseo_upsert_row( $post_id, $data );
			}
		} else {
			$description = $request->get_param( 'meta_description' );
			if ( null !== $description ) {
				$key = Field_Mapper::meta_key( 'meta_description', $mode );
				if ( $key ) {
					update_post_meta( $post_id, $key, sanitize_text_field( (string) $description ) );
				}
			}
			$keywords = $request->get_param( 'meta_keywords' );
			if ( null !== $keywords ) {
				$key = Field_Mapper::meta_key( 'meta_keywords', $mode );
				if ( $key ) {
					update_post_meta( $post_id, $key, sanitize_text_field( (string) $keywords ) );
				}
			}
		}

		self::$writing = false;
	}

	/**
	 * Resolve a WordPress user ID to author new blog posts as.
	 *
	 * These REST calls authenticate via WC API keys, not a logged-in WP user
	 * session, so there is no "current user" to default to. The site's first
	 * administrator is used instead, falling back to user ID 1 (present on
	 * every WordPress install) if none is found.
	 *
	 * @return int
	 */
	private static function default_author_id() {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);
		return ! empty( $admins ) ? (int) $admins[0] : 1;
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
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix, not user input.
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
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix, not user input.
			$wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d", $post_id )
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $data, array( 'post_id' => $post_id ) );
		} else {
			$data['post_id'] = $post_id;
			$data['created'] = current_time( 'mysql' );
			$data['updated'] = current_time( 'mysql' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( $table, $data );
		}
	}
}
