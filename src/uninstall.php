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

// Note: We intentionally do NOT delete product meta (SEO titles, descriptions,
// alt text) written by this plugin. That content is the merchant's data and
// must be preserved per WooCommerce Marketplace guidelines.
