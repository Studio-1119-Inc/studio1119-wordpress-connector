<?php
/**
 * Plugin Name: Allow loopback webhook delivery (test harness)
 * Description: Lets WooCommerce deliver webhooks to localhost on any port. Local test sites only — never shipped.
 *
 * Installed into every site by setup.sh. It weakens a check that exists for
 * good reasons on a real site, which is fine here and nowhere else.
 *
 * WooCommerce delivers webhooks with wp_safe_remote_request()
 * (woocommerce/includes/class-wc-webhook.php:365, and wp_safe_remote_post()
 * for the test ping at :629), which runs wp_http_validate_url(). That
 * function has TWO gates, and a delivery to a local receiver fails both:
 *
 *   1. It rejects any host resolving to 127.x / 10.x / 172.16-31.x /
 *      192.168.x unless the http_request_host_is_external filter says
 *      otherwise.
 *   2. It rejects any port outside http_allowed_safe_ports — 80, 443, 8080 —
 *      whatever the host. This is the one that is easy to miss: open gate 1
 *      and delivery still fails, identically, because the sites here are on
 *      8081-8084 and a receiver is on whatever port you picked.
 *
 * Both fail SILENTLY. The Action Scheduler job that does the delivery
 * (woocommerce_deliver_webhook_async) runs and completes, the webhook's
 * failure_count is never incremented, and nothing arrives — so it reads as
 * "webhooks are broken on this harness" when it is a URL policy. Diagnosed
 * 2026-10-30, having first been written off as a dead end.
 *
 * Two things this is NOT needed for:
 *
 *   - Delivery to a PUBLIC url. Point a webhook at an ngrok tunnel and it
 *     arrives with or without this plugin.
 *   - This connector's own outbound notifications. SEO_Meta_Notifier and
 *     Taxonomy_Notifier use wp_remote_post(), which does not validate the
 *     url at all, so they reach localhost regardless. If those work and WC's
 *     order webhooks do not, this is why.
 *
 * @package studio1119-test-harness
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a host is this machine. The gates below open for these and nothing
 * else, so a misconfigured webhook pointed at a private network address still
 * gets WordPress's protection.
 *
 * @param string $host Host name from the requested URL.
 * @return bool
 */
function studio1119_test_is_loopback_host( $host ) {
	return in_array(
		strtolower( (string) $host ),
		array( '127.0.0.1', 'localhost', '::1', '[::1]' ),
		true
	);
}

// Gate 1 — the private-IP check.
add_filter(
	'http_request_host_is_external',
	static function ( $is_external, $host ) {
		return studio1119_test_is_loopback_host( $host ) ? true : $is_external;
	},
	10,
	2
);

// Gate 2 — the port check. Allow whatever port was asked for rather than
// listing ports, because the receiver's port is whoever is debugging today's
// choice: 8081-8084 for the sites themselves, 3012 for TruSync, something
// else tomorrow. $host and $url are the 2nd and 3rd filter args since WP 5.9.
add_filter(
	'http_allowed_safe_ports',
	static function ( $ports, $host, $url ) {
		$ports = (array) $ports;
		if ( ! studio1119_test_is_loopback_host( $host ) ) {
			return $ports;
		}
		$port = (int) wp_parse_url( $url, PHP_URL_PORT );
		if ( $port > 0 ) {
			$ports[] = $port;
		}
		return $ports;
	},
	10,
	3
);
