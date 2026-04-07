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

/**
 * Registers the admin page, enqueues the widget script, and renders the mount div.
 */
class Admin_Page {

	/**
	 * Derive the admin page slug from the per-app option prefix so that
	 * CataSEO and TruSync can coexist on the same WordPress instance.
	 * E.g. "cataseo" → "cataseo-connector", "trusync" → "trusync-connector".
	 *
	 * @return string
	 */
	public static function page_slug() {
		$prefix = Plugin::const_value( 'OPTION_PREFIX' );
		$prefix = $prefix ? $prefix : 'studio1119';
		return $prefix . '-connector';
	}

	/**
	 * Hook into admin_menu and admin_enqueue_scripts.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'standalone_mode_notice' ) );
	}

	/**
	 * Show a notice on product edit screens when standalone mode is active,
	 * explaining where SEO fields are managed.
	 *
	 * @return void
	 */
	public static function standalone_mode_notice() {
		if ( SEO_Plugin_Detector::MODE_STANDALONE !== Plugin::get_detected_mode() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		$menu_title = Plugin::const_value( 'MENU_TITLE' );
		$menu_title = $menu_title ? $menu_title : 'Studio 1119';
		$slug       = self::page_slug();
		$admin_url  = admin_url( "admin.php?page={$slug}" );

		echo '<div class="notice notice-info"><p>';
		echo '<strong>' . esc_html( $menu_title ) . ':</strong> ';
		echo 'No SEO plugin detected. SEO meta tags (title, description, Open Graph) are managed through the ';
		echo '<a href="' . esc_url( $admin_url ) . '">' . esc_html( $menu_title ) . ' dashboard</a>';
		echo ' and injected directly into your product pages.';
		echo '</p></div>';
	}

	/**
	 * Add the top-level admin menu page.
	 *
	 * @return void
	 */
	public static function add_menu() {
		$menu_title = Plugin::const_value( 'MENU_TITLE' );
		$menu_title = $menu_title ? $menu_title : 'Studio 1119';
		$menu_icon  = Plugin::const_value( 'MENU_ICON' );
		$menu_icon  = $menu_icon ? $menu_icon : 'dashicons-admin-generic';

		add_menu_page(
			$menu_title,
			$menu_title,
			'manage_woocommerce',
			self::page_slug(),
			array( __CLASS__, 'render' ),
			$menu_icon,
			56
		);
	}

	/**
	 * Render the admin page HTML mount point.
	 *
	 * @return void
	 */
	public static function render() {
		$root_id = Plugin::const_value( 'ROOT_ID' );
		$root_id = $root_id ? $root_id : 'studio1119-root';
		echo '<div class="wrap"><div id="' . esc_attr( $root_id ) . '"></div></div>';
	}

	/**
	 * Conditionally enqueue the widget script on the connector admin page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public static function maybe_enqueue( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::page_slug() ) ) {
			return;
		}

		$widget_url = Plugin::const_value( 'WIDGET_URL' );
		if ( ! $widget_url ) {
			return;
		}

		$version = Plugin::const_value( 'VERSION' );
		$version = $version ? $version : '0.0.0';

		wp_enqueue_script(
			'studio1119-connector-widget',
			trailingslashit( $widget_url ) . 'widget.js',
			array(),
			$version,
			true
		);

		// Hand the widget everything it needs to talk back to this WP site:
		// REST root, nonce (for authenticated nonce-based requests), site URL,
		// current user info, detected SEO mode, and canonical field → meta key map.
		// The widgetToken is a one-time token verified by the remote backend.
		$current_user = wp_get_current_user();
		$widget_token = Widget_Auth::generate_token();
		$boot_data    = array(
			'restUrl'       => esc_url_raw( rest_url() ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'siteUrl'       => get_site_url(),
			'appUrl'        => trailingslashit( $widget_url ),
			'adminUrl'      => admin_url(),
			'pluginVersion' => $version,
			'widgetToken'   => $widget_token,
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

	/**
	 * Build a map of canonical field names to their meta keys for the current mode.
	 *
	 * @return array<string, string|null>
	 */
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
