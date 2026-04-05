<?php
/**
 * Admin menu registration and widget mount.
 *
 * Registers a top-level admin menu item, renders a page that contains the
 * mount <div>, and enqueues the remote widget.js onto only that page.
 *
 * @package Studio1119\Connector
 */

namespace Studio1119\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Page {

	const PAGE_SLUG = 'studio1119-connector';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	public static function add_menu() {
		$menu_title = Plugin::const_value( 'MENU_TITLE' ) ?: 'Studio 1119';
		$menu_icon  = Plugin::const_value( 'MENU_ICON' ) ?: 'dashicons-admin-generic';

		add_menu_page(
			$menu_title,
			$menu_title,
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' ),
			$menu_icon,
			56
		);
	}

	public static function render() {
		$root_id = Plugin::const_value( 'ROOT_ID' ) ?: 'studio1119-root';
		echo '<div class="wrap"><div id="' . esc_attr( $root_id ) . '"></div></div>';
	}

	public static function maybe_enqueue( $hook_suffix ) {
		if ( strpos( (string) $hook_suffix, self::PAGE_SLUG ) === false ) {
			return;
		}

		$widget_url = Plugin::const_value( 'WIDGET_URL' );
		if ( ! $widget_url ) {
			return;
		}

		wp_enqueue_script(
			'studio1119-connector-widget',
			trailingslashit( $widget_url ) . 'widget.js',
			array(),
			null,
			true
		);

		// Hand the widget everything it needs to talk back to this WP site:
		// REST root, nonce (for authenticated nonce-based requests), site URL,
		// current user info, detected SEO mode, and canonical field → meta key map.
		$current_user = wp_get_current_user();
		$boot_data    = array(
			'restUrl'       => esc_url_raw( rest_url() ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'siteUrl'       => get_site_url(),
			'adminUrl'      => admin_url(),
			'pluginVersion' => Plugin::const_value( 'VERSION' ) ?: '0.0.0',
			'detectedMode'  => Plugin::get_detected_mode(),
			'fieldMap'      => self::build_field_map(),
			'currentUser'   => array(
				'id'    => get_current_user_id(),
				'email' => $current_user->user_email,
				'name'  => $current_user->display_name,
			),
		);

		// Global variable name derived from the build prefix: CataSEO → CATASEOBoot,
		// TruSync → TRUSYNCBoot. The widget reads whichever one matches its build.
		$global_var = Plugin::const_prefix() . 'Boot';

		wp_add_inline_script(
			'studio1119-connector-widget',
			'window.' . $global_var . ' = ' . wp_json_encode( $boot_data ) . ';',
			'before'
		);
	}

	private static function build_field_map() {
		$mode   = Plugin::get_detected_mode();
		$fields = Field_Mapper::canonical_fields();
		$out    = array();
		foreach ( $fields as $field ) {
			$out[ $field ] = Field_Mapper::meta_key( $field, $mode );
		}
		return $out;
	}
}
