<?php
/**
 * Detects which SEO plugin (if any) is active on the site.
 * Returns one of: 'yoast', 'rankmath', 'aioseo', 'standalone'.
 *
 * Precedence if multiple are somehow active simultaneously (rare, unsupported
 * by the SEO plugins themselves but seen in migrations): Yoast > Rank Math >
 * AIOSEO > standalone. This matches the order in which each plugin would
 * "win" the wp_head output race in practice.
 *
 * @package Studio1119\Connector
 */

namespace Studio1119\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects which SEO plugin is active and returns the corresponding mode constant.
 */
class SEO_Plugin_Detector {

	const MODE_YOAST      = 'yoast';
	const MODE_RANK_MATH  = 'rankmath';
	const MODE_AIOSEO     = 'aioseo';
	const MODE_STANDALONE = 'standalone';

	/**
	 * Detect the active SEO plugin.
	 *
	 * @return string One of the MODE_* constants.
	 */
	public static function detect() {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options', false ) ) {
			return self::MODE_YOAST;
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false ) ) {
			return self::MODE_RANK_MATH;
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			return self::MODE_AIOSEO;
		}
		return self::MODE_STANDALONE;
	}

	/**
	 * Whether the site is running in standalone mode (no SEO plugin detected).
	 *
	 * @return bool
	 */
	public static function is_standalone() {
		return self::MODE_STANDALONE === self::detect();
	}
}
