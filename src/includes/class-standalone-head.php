<?php
/**
 * Standalone-mode wp_head meta tag output.
 *
 * Only renders when detected mode is 'standalone' (no Yoast/Rank Math/AIOSEO
 * active). When any of those plugins is active, this class is a no-op and
 * they handle the head output. This is how we avoid duplicate tags.
 *
 * Renders only on singular product pages. Other page types are left alone.
 *
 * @package Studio1119\Connector
 */

namespace Studio1119\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Standalone_Head {

	public static function register() {
		add_action( 'wp_head', array( __CLASS__, 'render' ), 1 );
	}

	public static function render() {
		// Double-check: re-detect at render time in addition to using the cached
		// option, so if a merchant activates Yoast/Rank Math/AIOSEO on the same
		// request we still cleanly yield to them without waiting for an admin
		// page load to refresh the cached mode.
		if ( SEO_Plugin_Detector::detect() !== SEO_Plugin_Detector::MODE_STANDALONE ) {
			return;
		}
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		$title            = self::get_meta( $post_id, 'page_title' );
		$description      = self::get_meta( $post_id, 'meta_description' );
		$og_title         = self::get_meta( $post_id, 'og_title' );
		$og_description   = self::get_meta( $post_id, 'og_description' );
		$fallback_title   = get_the_title( $post_id );
		$permalink        = get_permalink( $post_id );
		$featured_img_url = get_the_post_thumbnail_url( $post_id, 'large' );

		$title          = $title ?: $fallback_title;
		$og_title       = $og_title ?: $title;
		$og_description = $og_description ?: $description;

		echo "\n<!-- studio1119-connector: standalone SEO head -->\n";

		if ( $title ) {
			echo '<title>' . esc_html( $title ) . "</title>\n";
		}
		if ( $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . "\" />\n";
		}
		if ( $og_title ) {
			echo '<meta property="og:title" content="' . esc_attr( $og_title ) . "\" />\n";
		}
		if ( $og_description ) {
			echo '<meta property="og:description" content="' . esc_attr( $og_description ) . "\" />\n";
		}
		echo '<meta property="og:type" content="product" />' . "\n";
		if ( $permalink ) {
			echo '<meta property="og:url" content="' . esc_url( $permalink ) . "\" />\n";
		}
		if ( $featured_img_url ) {
			echo '<meta property="og:image" content="' . esc_url( $featured_img_url ) . "\" />\n";
		}

		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		if ( $og_title ) {
			echo '<meta name="twitter:title" content="' . esc_attr( $og_title ) . "\" />\n";
		}
		if ( $og_description ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $og_description ) . "\" />\n";
		}
		if ( $featured_img_url ) {
			echo '<meta name="twitter:image" content="' . esc_url( $featured_img_url ) . "\" />\n";
		}

		echo "<!-- /studio1119-connector -->\n";
	}

	private static function get_meta( $post_id, $field ) {
		$key = Field_Mapper::meta_key( $field, SEO_Plugin_Detector::MODE_STANDALONE );
		if ( ! $key ) {
			return '';
		}
		$value = get_post_meta( $post_id, $key, true );
		return is_string( $value ) ? $value : '';
	}
}
