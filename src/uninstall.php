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

${{APP_OPTION_PREFIX}}_option_prefix = '{{APP_OPTION_PREFIX}}';

// Plugin options to clean up.
${{APP_OPTION_PREFIX}}_options = array(
	${{APP_OPTION_PREFIX}}_option_prefix . '_connected',
	${{APP_OPTION_PREFIX}}_option_prefix . '_connected_user',
	${{APP_OPTION_PREFIX}}_option_prefix . '_detected_seo_mode',
	${{APP_OPTION_PREFIX}}_option_prefix . '_mode_checked_at',
);

foreach ( ${{APP_OPTION_PREFIX}}_options as ${{APP_OPTION_PREFIX}}_option ) {
	delete_option( ${{APP_OPTION_PREFIX}}_option );
	delete_site_option( ${{APP_OPTION_PREFIX}}_option ); // Multisite compliance.
}

// Clean up widget auth transients (one-time tokens, 5-minute TTL).
// These are keyed as studio1119_wt_{hash} — we can't enumerate them easily,
// but they expire on their own within 5 minutes. No action needed.

// Clean up WooCommerce webhooks registered by this plugin.
// Webhooks are identified by their name prefix (e.g. "TruSync order.created").
global $wpdb;
${{APP_OPTION_PREFIX}}_menu_title = '{{APP_MENU_TITLE}}';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
${{APP_OPTION_PREFIX}}_webhook_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT webhook_id FROM {$wpdb->prefix}wc_webhooks WHERE name LIKE %s",
		${{APP_OPTION_PREFIX}}_menu_title . '%'
	)
);
if ( ! empty( ${{APP_OPTION_PREFIX}}_webhook_ids ) ) {
	${{APP_OPTION_PREFIX}}_placeholders = implode( ',', array_fill( 0, count( ${{APP_OPTION_PREFIX}}_webhook_ids ), '%d' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			"DELETE FROM {$wpdb->prefix}wc_webhooks WHERE webhook_id IN (${{APP_OPTION_PREFIX}}_placeholders)",
			...${{APP_OPTION_PREFIX}}_webhook_ids
		)
	);
}

// Clean up WooCommerce API keys created during OAuth connect.
// Keys are identified by description prefix (e.g. "TruSync - API (date)").
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->prefix}woocommerce_api_keys WHERE description LIKE %s",
		${{APP_OPTION_PREFIX}}_menu_title . ' - API%'
	)
);

// Note: We intentionally do NOT delete product meta (SEO titles, descriptions,
// alt text) written by this plugin. That content is the merchant's data and
// must be preserved per WooCommerce Marketplace guidelines.
