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

$option_prefix = '{{APP_OPTION_PREFIX}}';

// Plugin options to clean up.
$options = array(
	$option_prefix . '_connected',
	$option_prefix . '_connected_user',
	$option_prefix . '_detected_seo_mode',
	$option_prefix . '_mode_checked_at',
);

foreach ( $options as $option ) {
	delete_option( $option );
	delete_site_option( $option ); // Multisite compliance.
}

// Clean up widget auth transients (one-time tokens, 5-minute TTL).
// These are keyed as studio1119_wt_{hash} — we can't enumerate them easily,
// but they expire on their own within 5 minutes. No action needed.

// Clean up WooCommerce webhooks registered by this plugin.
// Webhooks are identified by their name prefix (e.g. "TruSync order.created").
global $wpdb;
$menu_title = '{{APP_MENU_TITLE}}';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$webhook_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT webhook_id FROM {$wpdb->prefix}wc_webhooks WHERE name LIKE %s",
		$menu_title . '%'
	)
);
if ( ! empty( $webhook_ids ) ) {
	$placeholders = implode( ',', array_fill( 0, count( $webhook_ids ), '%d' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}wc_webhooks WHERE webhook_id IN ($placeholders)",
			...$webhook_ids
		)
	);
}

// Clean up WooCommerce API keys created during OAuth connect.
// Keys are identified by description prefix (e.g. "TruSync - API (date)").
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->prefix}woocommerce_api_keys WHERE description LIKE %s",
		$menu_title . ' - API%'
	)
);

// Note: We intentionally do NOT delete product meta (SEO titles, descriptions,
// alt text) written by this plugin. That content is the merchant's data and
// must be preserved per WooCommerce Marketplace guidelines.
