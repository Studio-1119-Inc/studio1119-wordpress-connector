<?php
/**
 * Uninstall {{APP_PLUGIN_NAME}}.
 *
 * Removes all plugin options and transients but preserves product meta
 * (AI-generated SEO titles, descriptions, and alt text). Merchants retain
 * the value of the plugin even after uninstall.
 *
 * @package {{APP_NAMESPACE}}
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$cataseo_option_prefix = '{{APP_OPTION_PREFIX}}';

// Plugin options to clean up.
$cataseo_options = array(
	$cataseo_option_prefix . '_connected',
	$cataseo_option_prefix . '_connected_user',
	$cataseo_option_prefix . '_detected_seo_mode',
	$cataseo_option_prefix . '_mode_checked_at',
);

foreach ( $cataseo_options as $cataseo_option ) {
	delete_option( $cataseo_option );
	delete_site_option( $cataseo_option ); // Multisite compliance.
}

// Clean up widget auth transients (one-time tokens, 5-minute TTL).
// These are keyed as studio1119_wt_{hash} — we can't enumerate them easily,
// but they expire on their own within 5 minutes. No action needed.

// Clean up WooCommerce webhooks registered by this plugin.
// Webhooks are identified by their name prefix (e.g. "TruSync order.created").
global $wpdb;
$cataseo_menu_title = '{{APP_MENU_TITLE}}';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$cataseo_webhook_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT webhook_id FROM {$wpdb->prefix}wc_webhooks WHERE name LIKE %s",
		$cataseo_menu_title . '%'
	)
);
if ( ! empty( $cataseo_webhook_ids ) ) {
	$cataseo_placeholders = implode( ',', array_fill( 0, count( $cataseo_webhook_ids ), '%d' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}wc_webhooks WHERE webhook_id IN ($cataseo_placeholders)",
			...$cataseo_webhook_ids
		)
	);
}

// Clean up WooCommerce API keys created during OAuth connect.
// Keys are identified by description prefix (e.g. "TruSync - API (date)").
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->prefix}woocommerce_api_keys WHERE description LIKE %s",
		$cataseo_menu_title . ' - API%'
	)
);

// Note: We intentionally do NOT delete product meta (SEO titles, descriptions,
// alt text) written by this plugin. That content is the merchant's data and
// must be preserved per WooCommerce Marketplace guidelines.
