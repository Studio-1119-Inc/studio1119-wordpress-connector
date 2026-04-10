<?php
/**
 * Admin menu registration and native status portal.
 *
 * Two-state UI:
 *   - Not connected: "Connect to CataSEO" button initiates WC OAuth handshake
 *   - Connected: status summary + "Launch Dashboard" button opens external SaaS
 *
 * No iFrames — compliant with WooCommerce Marketplace UX guidelines.
 *
 * @package Studio1119\Connector
 */

namespace Studio1119\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the admin page and renders the native status portal.
 */
class Admin_Page {

	const CONNECTED_OPTION_SUFFIX     = '_connected';
	const CONNECTED_USER_OPTION_SUFFIX = '_connected_user';

	/**
	 * Derive the admin page slug from the per-app option prefix.
	 *
	 * @return string
	 */
	public static function page_slug() {
		$prefix = Plugin::const_value( 'OPTION_PREFIX' );
		$prefix = $prefix ? $prefix : 'studio1119';
		return $prefix . '-connector';
	}

	/**
	 * Hook into admin_menu, admin_init, and admin_notices.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_oauth_return' ) );
		add_action( 'admin_notices', array( __CLASS__, 'standalone_mode_notice' ) );
	}

	/**
	 * Handle the OAuth return redirect.
	 *
	 * After the WC OAuth handshake, the merchant is redirected back to the
	 * WP admin with ?cataseo_connected=1. We detect this, store the connected
	 * state, and redirect to a clean URL so the param isn't bookmarked.
	 *
	 * @return void
	 */
	public static function handle_oauth_return() {
		$prefix = Plugin::const_value( 'OPTION_PREFIX' );
		if ( ! $prefix ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth return redirect, no state change from user input.
		if ( ! isset( $_GET[ $prefix . '_connected' ] ) ) {
			return;
		}

		// Mark as connected and store the display name from the query param if provided.
		update_option( $prefix . self::CONNECTED_OPTION_SUFFIX, '1' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_label = isset( $_GET[ $prefix . '_user' ] )
			? sanitize_text_field( wp_unslash( $_GET[ $prefix . '_user' ] ) )
			: '';
		if ( $user_label ) {
			update_option( $prefix . self::CONNECTED_USER_OPTION_SUFFIX, $user_label );
		}

		// Redirect to clean URL to avoid re-triggering on refresh.
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::page_slug() ) );
		exit;
	}

	/**
	 * Check whether the store is connected to the external SaaS.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$prefix = Plugin::const_value( 'OPTION_PREFIX' );
		if ( ! $prefix ) {
			return false;
		}
		return '1' === get_option( $prefix . self::CONNECTED_OPTION_SUFFIX );
	}

	/**
	 * Get the connected user/account display label (if stored).
	 *
	 * @return string
	 */
	public static function connected_user_label() {
		$prefix = Plugin::const_value( 'OPTION_PREFIX' );
		if ( ! $prefix ) {
			return '';
		}
		return get_option( $prefix . self::CONNECTED_USER_OPTION_SUFFIX, '' );
	}

	/**
	 * Show a notice on product edit screens when standalone mode is active.
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

		$post_id   = get_the_ID();
		$has_title = $post_id ? get_post_meta( $post_id, Field_Mapper::meta_key( 'page_title', SEO_Plugin_Detector::MODE_STANDALONE ), true ) : '';

		if ( $has_title ) {
			echo '<div class="notice notice-success"><p>';
			echo '<strong>' . esc_html( $menu_title ) . '</strong> has optimized your SEO meta tags and inserted them directly into this product\'s page. All set! ';
			echo 'Manage your SEO from the <a href="' . esc_url( $admin_url ) . '">' . esc_html( $menu_title ) . ' dashboard</a>.';
			echo '</p></div>';
		} else {
			echo '<div class="notice notice-info"><p>';
			echo '<strong>' . esc_html( $menu_title ) . ':</strong> ';
			echo 'No SEO plugin detected. Optimize this product from the <a href="' . esc_url( $admin_url ) . '">' . esc_html( $menu_title ) . ' dashboard</a> ';
			echo 'and SEO meta tags will be inserted directly into the page.';
			echo '</p></div>';
		}
	}

	/**
	 * Add submenu page under WooCommerce.
	 *
	 * @return void
	 */
	public static function add_menu() {
		$menu_title = Plugin::const_value( 'MENU_TITLE' );
		$menu_title = $menu_title ? $menu_title : 'Studio 1119';

		add_submenu_page(
			'woocommerce',
			$menu_title . ' Dashboard',
			$menu_title,
			'manage_woocommerce',
			self::page_slug(),
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the admin portal page.
	 *
	 * Two states:
	 *   1. Not connected → "Connect to CataSEO" button (initiates OAuth)
	 *   2. Connected → status summary + "Launch Dashboard" button
	 *
	 * @return void
	 */
	public static function render() {
		$menu_title  = Plugin::const_value( 'MENU_TITLE' );
		$menu_title  = $menu_title ? $menu_title : 'Studio 1119';
		$widget_url  = Plugin::const_value( 'WIDGET_URL' );
		$version     = Plugin::const_value( 'VERSION' );
		$version     = $version ? $version : '0.0.0';
		$mode        = Plugin::get_detected_mode();
		$mode_label  = self::mode_display_name( $mode );
		$connected   = self::is_connected();
		$user_label  = self::connected_user_label();

		// Count WooCommerce products.
		$product_count = 0;
		if ( function_exists( 'wc_get_products' ) ) {
			$product_count = count( wc_get_products( array( 'limit' => -1, 'return' => 'ids' ) ) );
		}

		// Build SSO dashboard URL: the /api/woocommerce/load endpoint verifies a
		// one-time token and creates a JWT session, dropping the merchant into
		// the dashboard already authenticated. Without this, clicking "Launch
		// Dashboard" would require the merchant to log in again.
		if ( $connected && $widget_url ) {
			$widget_token  = Widget_Auth::generate_token();
			$site_url      = get_site_url();
			$dashboard_url = trailingslashit( $widget_url ) . 'api/woocommerce/load?' . http_build_query( array(
				'siteUrl' => $site_url,
				'token'   => $widget_token,
			) );
		} else {
			$dashboard_url = $widget_url ? trailingslashit( $widget_url ) : '#';
		}
		?>
		<div class="wrap woocommerce">
			<h1><?php echo esc_html( $menu_title ); ?> optimization dashboard</h1>

			<?php if ( ! $connected ) : ?>
				<?php self::render_connect_state( $menu_title, $widget_url, $mode_label, $product_count, $version ); ?>
			<?php else : ?>
				<?php self::render_connected_state( $menu_title, $dashboard_url, $user_label, $mode_label, $product_count, $version, $widget_url ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the "not connected" state with the OAuth connect button.
	 *
	 * @param string $menu_title    App display name.
	 * @param string $widget_url    Backend URL.
	 * @param string $mode_label    Detected SEO mode label.
	 * @param int    $product_count Number of WC products.
	 * @param string $version       Plugin version.
	 * @return void
	 */
	private static function render_connect_state( $menu_title, $widget_url, $mode_label, $product_count, $version ) {
		$prefix    = Plugin::const_value( 'OPTION_PREFIX' );
		$return_url = admin_url( 'admin.php?page=' . self::page_slug() . '&' . $prefix . '_connected=1' );

		// Build the OAuth initiation URL on our SaaS backend.
		$site_url  = get_site_url();
		$auth_url  = $widget_url
			? trailingslashit( $widget_url ) . 'api/woocommerce/auth?' . http_build_query( array(
				'site_url'   => $site_url,
				'return_url' => $return_url,
			) )
			: '#';
		?>
		<div class="card" style="max-width: 680px; margin-top: 20px;">
			<h2 style="margin-top: 0;">
				<?php esc_html_e( 'Connect your store', '{{APP_TEXT_DOMAIN}}' ); ?>
			</h2>

			<p>
				<?php
				printf(
					/* translators: %s: app name */
					esc_html__( 'Connect your WooCommerce store to %s to start optimizing your product SEO with AI.', '{{APP_TEXT_DOMAIN}}' ),
					esc_html( $menu_title )
				);
				?>
			</p>

			<table class="widefat fixed striped" style="margin: 20px 0;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'SEO plugin mode', '{{APP_TEXT_DOMAIN}}' ); ?></strong></td>
						<td><?php echo esc_html( $mode_label ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Products', '{{APP_TEXT_DOMAIN}}' ); ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( $product_count ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Plugin version', '{{APP_TEXT_DOMAIN}}' ); ?></strong></td>
						<td><?php echo esc_html( $version ); ?></td>
					</tr>
				</tbody>
			</table>

			<p>
				<a href="<?php echo esc_url( $auth_url ); ?>"
				   class="button button-primary button-hero"
				   aria-label="<?php printf( esc_attr__( 'Connect to %s via WooCommerce authorization', '{{APP_TEXT_DOMAIN}}' ), esc_attr( $menu_title ) ); ?>">
					<?php
					printf(
						/* translators: %s: app name */
						esc_html__( 'Connect to %s', '{{APP_TEXT_DOMAIN}}' ),
						esc_html( $menu_title )
					);
					?>
				</a>
			</p>
			<p class="description">
				<?php esc_html_e( 'You will be asked to approve read and write access to your products. This is required for SEO optimization.', '{{APP_TEXT_DOMAIN}}' ); ?>
			</p>
		</div>

		<p style="margin-top: 16px;">
			<a href="{{APP_DOCS_URL}}" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'View documentation', '{{APP_TEXT_DOMAIN}}' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Render the "connected" state with status summary and dashboard link.
	 *
	 * @param string $menu_title    App display name.
	 * @param string $dashboard_url External dashboard URL.
	 * @param string $user_label    Connected account label.
	 * @param string $mode_label    Detected SEO mode label.
	 * @param int    $product_count Number of WC products.
	 * @param string $version       Plugin version.
	 * @param string $widget_url    Backend URL.
	 * @return void
	 */
	private static function render_connected_state( $menu_title, $dashboard_url, $user_label, $mode_label, $product_count, $version, $widget_url ) {
		$connected_label = $user_label ? $user_label : get_bloginfo( 'name' );
		?>
		<div class="card" style="max-width: 680px; margin-top: 20px;">
			<h2 style="margin-top: 0;">
				<?php
				printf(
					/* translators: %s: app name */
					esc_html__( 'Connection active: syncing with %s cloud', '{{APP_TEXT_DOMAIN}}' ),
					esc_html( $menu_title )
				);
				?>
			</h2>

			<table class="widefat fixed striped" style="margin: 20px 0;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Account', '{{APP_TEXT_DOMAIN}}' ); ?></strong></td>
						<td><?php echo esc_html( $connected_label ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'SEO plugin mode', '{{APP_TEXT_DOMAIN}}' ); ?></strong></td>
						<td><?php echo esc_html( $mode_label ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Products', '{{APP_TEXT_DOMAIN}}' ); ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( $product_count ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Plugin version', '{{APP_TEXT_DOMAIN}}' ); ?></strong></td>
						<td><?php echo esc_html( $version ); ?></td>
					</tr>
				</tbody>
			</table>

			<p>
				<a href="<?php echo esc_url( $dashboard_url ); ?>"
				   class="button button-primary button-hero"
				   target="_blank"
				   rel="noopener noreferrer"
				   aria-label="<?php printf( esc_attr__( 'Launch %s dashboard in a new tab', '{{APP_TEXT_DOMAIN}}' ), esc_attr( $menu_title ) ); ?>">
					<?php
					printf(
						/* translators: %s: app name */
						esc_html__( 'Launch %s dashboard', '{{APP_TEXT_DOMAIN}}' ),
						esc_html( $menu_title )
					);
					?>
				</a>
			</p>
			<p class="description">
				<?php esc_html_e( 'Manage SEO optimization, bulk operations, and billing on the external platform.', '{{APP_TEXT_DOMAIN}}' ); ?>
			</p>
		</div>

		<p style="margin-top: 16px;">
			<a href="{{APP_DOCS_URL}}" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'View documentation', '{{APP_TEXT_DOMAIN}}' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Return a human-readable label for the detected SEO mode.
	 *
	 * @param string $mode One of SEO_Plugin_Detector::MODE_* constants.
	 * @return string
	 */
	private static function mode_display_name( $mode ) {
		switch ( $mode ) {
			case SEO_Plugin_Detector::MODE_YOAST:
				return 'Yoast SEO';
			case SEO_Plugin_Detector::MODE_RANK_MATH:
				return 'Rank Math';
			case SEO_Plugin_Detector::MODE_AIOSEO:
				return 'All in One SEO';
			case SEO_Plugin_Detector::MODE_STANDALONE:
				return 'Standalone (no SEO plugin)';
			default:
				return ucfirst( $mode );
		}
	}
}
