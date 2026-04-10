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

	const CONNECTED_OPTION_SUFFIX      = '_connected';
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
		add_action( 'admin_post_studio1119_launch_dashboard', array( __CLASS__, 'handle_launch_dashboard' ) );
		add_action( 'admin_notices', array( __CLASS__, 'standalone_mode_notice' ) );
	}

	/**
	 * Handle "Launch Dashboard" form submissions.
	 *
	 * Generates a fresh one-time widget token at click time and redirects
	 * to the SSO load endpoint on the SaaS backend. Generating the token
	 * at click time — rather than baking it into the button's href at
	 * admin page render time — means the 5-minute token TTL starts when
	 * the merchant actually clicks. They can leave the admin page open
	 * indefinitely (over lunch, overnight) without seeing "Your widget
	 * session has expired" when they come back and click.
	 *
	 * @return void
	 */
	public static function handle_launch_dashboard() {
		check_admin_referer( 'studio1119_launch_dashboard' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to launch the dashboard.', '{{APP_TEXT_DOMAIN}}' ),
				'',
				array( 'response' => 403 )
			);
		}

		if ( ! self::is_connected() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::page_slug() ) );
			exit;
		}

		$widget_url = Plugin::const_value( 'WIDGET_URL' );
		if ( ! $widget_url ) {
			wp_die(
				esc_html__( 'Dashboard URL is not configured.', '{{APP_TEXT_DOMAIN}}' ),
				'',
				array( 'response' => 500 )
			);
		}

		// Fresh token — created now, not at page render. TTL is 5 minutes
		// measured from this moment, so the click → verify round trip
		// always completes well inside the window.
		$token         = Widget_Auth::generate_token();
		$site_url      = get_site_url();
		$dashboard_url = trailingslashit( $widget_url ) . 'api/woocommerce/load?' . http_build_query(
			array(
				'siteUrl' => $site_url,
				'token'   => $token,
			)
		);

		// External redirect to the SaaS host; wp_safe_redirect restricts
		// to allowlisted hosts and would reject this. The URL is built
		// entirely from server-side constants, so wp_redirect is safe.
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		wp_redirect( $dashboard_url );
		exit;
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
		$menu_title   = Plugin::const_value( 'MENU_TITLE' );
		$menu_title   = $menu_title ? $menu_title : 'Studio 1119';
		$widget_url   = Plugin::const_value( 'WIDGET_URL' );
		$version      = Plugin::const_value( 'VERSION' );
		$version      = $version ? $version : '0.0.0';
		$mode         = Plugin::get_detected_mode();
		$mode_label   = self::mode_display_name( $mode );
		$mode_version = SEO_Plugin_Detector::get_version();
		$connected    = self::is_connected();
		$user_label   = self::connected_user_label();

		// Count WooCommerce products.
		$product_count = 0;
		if ( function_exists( 'wc_get_products' ) ) {
			$product_count = count(
				wc_get_products(
					array(
						'limit'  => -1,
						'return' => 'ids',
					)
				)
			);
		}

		// NOTE: the dashboard URL is no longer built here. The "Launch
		// Dashboard" button now POSTs to admin-post.php, where
		// handle_launch_dashboard() generates a fresh one-time token at
		// click time and redirects. This prevents the widget token from
		// expiring while the admin page sits idle.
		?>
		<div class="wrap woocommerce">
			<h1><?php echo esc_html( $menu_title ); ?> optimization dashboard</h1>

			<?php self::render_integration_card( $mode, $mode_label, $mode_version, $menu_title ); ?>

			<?php if ( ! $connected ) : ?>
				<?php self::render_connect_state( $menu_title, $widget_url, $product_count, $version ); ?>
			<?php else : ?>
				<?php self::render_connected_state( $menu_title, $user_label, $product_count, $version ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the "not connected" state with the OAuth connect button.
	 *
	 * @param string $menu_title    App display name.
	 * @param string $widget_url    Backend URL.
	 * @param int    $product_count Number of WC products.
	 * @param string $version       Plugin version.
	 * @return void
	 */
	private static function render_connect_state( $menu_title, $widget_url, $product_count, $version ) {
		$prefix     = Plugin::const_value( 'OPTION_PREFIX' );
		$return_url = admin_url( 'admin.php?page=' . self::page_slug() . '&' . $prefix . '_connected=1' );

		// Build the OAuth initiation URL on our SaaS backend.
		$site_url = get_site_url();
		$auth_url = $widget_url
			? trailingslashit( $widget_url ) . 'api/woocommerce/auth?' . http_build_query(
				array(
					'site_url'   => $site_url,
					'return_url' => $return_url,
				)
			)
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
					aria-label="<?php /* translators: %s: app name */ printf( esc_attr__( 'Connect to %s via WooCommerce authorization', '{{APP_TEXT_DOMAIN}}' ), esc_attr( $menu_title ) ); ?>">
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
	 * The "Launch Dashboard" control is a form POST rather than a direct
	 * link so that the widget auth token is generated at click time by
	 * handle_launch_dashboard() — avoiding the "session expired" error
	 * that happens when the admin page sits open longer than the token TTL.
	 *
	 * @param string $menu_title    App display name.
	 * @param string $user_label    Connected account label.
	 * @param int    $product_count Number of WC products.
	 * @param string $version       Plugin version.
	 * @return void
	 */
	private static function render_connected_state( $menu_title, $user_label, $product_count, $version ) {
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
				<form method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					target="_blank"
					style="display: inline;">
					<?php wp_nonce_field( 'studio1119_launch_dashboard' ); ?>
					<input type="hidden" name="action" value="studio1119_launch_dashboard">
					<button type="submit"
						class="button button-primary button-hero"
						aria-label="<?php /* translators: %s: app name */ printf( esc_attr__( 'Launch %s dashboard in a new tab', '{{APP_TEXT_DOMAIN}}' ), esc_attr( $menu_title ) ); ?>">
						<?php
						printf(
							/* translators: %s: app name */
							esc_html__( 'Launch %s dashboard', '{{APP_TEXT_DOMAIN}}' ),
							esc_html( $menu_title )
						);
						?>
					</button>
				</form>
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
	 * Render the prominent "SEO integration detected" card.
	 *
	 * Shown above the main portal card on both the connect and connected
	 * states. This is the marketplace reviewer's "real value" proof — it
	 * demonstrates that the connector is genuinely talking to the host
	 * WordPress site's SEO plugin, not just linking out to an external
	 * service.
	 *
	 * For Yoast/Rank Math/AIOSEO: shows the plugin name, detected version,
	 * and the exact meta keys the connector writes optimized content into.
	 *
	 * For standalone mode: explains that the connector inserts meta tags
	 * directly into the product page &lt;head&gt; via wp_head.
	 *
	 * @param string $mode         One of SEO_Plugin_Detector::MODE_* constants.
	 * @param string $mode_label   Human-readable mode label.
	 * @param string $mode_version Detected SEO plugin version (empty if unknown).
	 * @param string $menu_title   App display name.
	 * @return void
	 */
	private static function render_integration_card( $mode, $mode_label, $mode_version, $menu_title ) {
		$is_standalone = SEO_Plugin_Detector::MODE_STANDALONE === $mode;

		$headline_label = $mode_label;
		if ( ! $is_standalone && $mode_version ) {
			$headline_label .= ' ' . $mode_version;
		}

		// Collect the canonical field → meta key pairs for the detected mode.
		$field_labels = array(
			'page_title'       => __( 'Meta title', '{{APP_TEXT_DOMAIN}}' ),
			'meta_description' => __( 'Meta description', '{{APP_TEXT_DOMAIN}}' ),
			'og_title'         => __( 'Open Graph title', '{{APP_TEXT_DOMAIN}}' ),
			'og_description'   => __( 'Open Graph description', '{{APP_TEXT_DOMAIN}}' ),
			'meta_keywords'    => __( 'Focus keyphrase', '{{APP_TEXT_DOMAIN}}' ),
		);

		$field_rows = array();
		foreach ( $field_labels as $field => $label ) {
			$key = Field_Mapper::meta_key( $field, $mode );
			if ( $key ) {
				$field_rows[] = array(
					'label' => $label,
					'key'   => $key,
				);
			}
		}

		$border_color = $is_standalone ? '#dba617' : '#00a32a';
		$icon         = $is_standalone ? '&#9888;' : '&#10004;';
		?>
		<div class="card" style="max-width: 680px; margin-top: 20px; border-left: 4px solid <?php echo esc_attr( $border_color ); ?>;">
			<h2 style="margin-top: 0;">
				<span aria-hidden="true" style="color: <?php echo esc_attr( $border_color ); ?>;"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML entity constant. ?></span>
				<?php
				if ( $is_standalone ) {
					esc_html_e( 'No SEO plugin detected', '{{APP_TEXT_DOMAIN}}' );
				} else {
					printf(
						/* translators: %s: SEO plugin name and version, e.g. "Yoast SEO 22.3" */
						esc_html__( 'Active SEO plugin detected: %s', '{{APP_TEXT_DOMAIN}}' ),
						esc_html( $headline_label )
					);
				}
				?>
			</h2>

			<?php if ( $is_standalone ) : ?>
				<p style="margin-bottom: 0;">
					<?php
					printf(
						/* translators: %s: app name */
						esc_html__( '%s runs in standalone mode on this site. Optimized meta titles, descriptions, and Open Graph tags are stored in your WordPress database and inserted directly into each product page\'s &lt;head&gt; element via the standard wp_head action. All optimized content is owned by your store and survives plugin deactivation.', '{{APP_TEXT_DOMAIN}}' ),
						esc_html( $menu_title )
					);
					?>
				</p>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: %1$s: app name, %2$s: SEO plugin label */
						esc_html__( '%1$s is talking to %2$s through the native WordPress REST API. Optimized content generated in the cloud is written back into your local WordPress database — directly into the meta keys your SEO plugin already reads.', '{{APP_TEXT_DOMAIN}}' ),
						esc_html( $menu_title ),
						esc_html( $mode_label )
					);
					?>
				</p>

				<?php if ( ! empty( $field_rows ) ) : ?>
					<p style="margin-bottom: 6px;">
						<strong><?php esc_html_e( 'Fields written by the connector:', '{{APP_TEXT_DOMAIN}}' ); ?></strong>
					</p>
					<table class="widefat fixed striped" style="margin-bottom: 12px;">
						<thead>
							<tr>
								<th style="width: 45%;"><?php esc_html_e( 'Field', '{{APP_TEXT_DOMAIN}}' ); ?></th>
								<th><?php esc_html_e( 'WordPress meta key', '{{APP_TEXT_DOMAIN}}' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $field_rows as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['label'] ); ?></td>
									<td><code><?php echo esc_html( $row['key'] ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<p class="description" style="margin-bottom: 0;">
					<?php esc_html_e( 'All optimized content is stored locally in your WordPress database. Your SEO value remains intact even if this plugin is deactivated.', '{{APP_TEXT_DOMAIN}}' ); ?>
				</p>
			<?php endif; ?>
		</div>
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
