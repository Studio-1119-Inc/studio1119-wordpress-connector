<?php
/**
 * Four-mode SEO field mapper.
 *
 * Given a canonical SEO field name and the detected mode, returns the correct
 * WordPress post meta key. The widget's REST calls go through this mapper so
 * the same UI writes into whichever plugin is active.
 *
 * Canonical field names:
 *   - page_title
 *   - meta_description
 *   - og_title
 *   - og_description
 *   - meta_keywords   (Yoast only; dropped for other modes)
 *
 * @package Studio1119\Connector
 */

namespace Studio1119\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Field_Mapper {

	/**
	 * Resolve the post meta key for a canonical field under the given mode.
	 *
	 * @param string $field Canonical field name.
	 * @param string $mode  One of SEO_Plugin_Detector::MODE_*.
	 * @return string|null  Meta key, or null if not stored in this mode.
	 */
	public static function meta_key( $field, $mode ) {
		$map = self::map();
		if ( ! isset( $map[ $mode ] ) ) {
			return null;
		}
		return $map[ $mode ][ $field ] ?? null;
	}

	/**
	 * All canonical fields this mapper knows about.
	 */
	public static function canonical_fields() {
		return array(
			'page_title',
			'meta_description',
			'og_title',
			'og_description',
			'meta_keywords',
		);
	}

	private static function map() {
		$standalone = Plugin::const_value( 'META_PREFIX' ) ?: '_studio1119';

		return array(
			SEO_Plugin_Detector::MODE_YOAST => array(
				'page_title'       => '_yoast_wpseo_title',
				'meta_description' => '_yoast_wpseo_metadesc',
				'og_title'         => '_yoast_wpseo_opengraph-title',
				'og_description'   => '_yoast_wpseo_opengraph-description',
				'meta_keywords'    => '_yoast_wpseo_metakeywords',
			),
			SEO_Plugin_Detector::MODE_RANK_MATH => array(
				'page_title'       => 'rank_math_title',
				'meta_description' => 'rank_math_description',
				'og_title'         => 'rank_math_facebook_title',
				'og_description'   => 'rank_math_facebook_description',
				'meta_keywords'    => null,
			),
			SEO_Plugin_Detector::MODE_AIOSEO => array(
				'page_title'       => '_aioseo_title',
				'meta_description' => '_aioseo_description',
				'og_title'         => '_aioseo_og_title',
				'og_description'   => '_aioseo_og_description',
				'meta_keywords'    => null,
			),
			SEO_Plugin_Detector::MODE_STANDALONE => array(
				'page_title'       => $standalone . '_title',
				'meta_description' => $standalone . '_description',
				'og_title'         => $standalone . '_og_title',
				'og_description'   => $standalone . '_og_description',
				'meta_keywords'    => null,
			),
		);
	}
}
