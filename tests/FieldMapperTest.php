<?php
/**
 * Tests for the Field_Mapper class.
 *
 * Uses real constants defined in bootstrap.php (TESTAPP_META_PREFIX = '_testmeta')
 * so Plugin::const_value() resolves natively without mocking static methods.
 *
 * @package Studio1119\Connector\Tests
 */

namespace Studio1119\Connector\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Studio1119\Connector\Field_Mapper;
use Studio1119\Connector\SEO_Plugin_Detector;

/**
 * Unit tests for Field_Mapper.
 */
class FieldMapperTest extends TestCase {

	/**
	 * Set up Brain Monkey before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tear down Brain Monkey after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test that canonical_fields returns all five canonical field names.
	 *
	 * @return void
	 */
	public function test_canonical_fields_returns_all_fields(): void {
		$fields = Field_Mapper::canonical_fields();
		$this->assertCount( 5, $fields );
		$this->assertContains( 'page_title', $fields );
		$this->assertContains( 'meta_description', $fields );
		$this->assertContains( 'og_title', $fields );
		$this->assertContains( 'og_description', $fields );
		$this->assertContains( 'meta_keywords', $fields );
	}

	/**
	 * Test Yoast mode returns correct meta keys.
	 *
	 * @return void
	 */
	public function test_yoast_meta_keys(): void {
		$this->assertSame(
			'_yoast_wpseo_title',
			Field_Mapper::meta_key( 'page_title', SEO_Plugin_Detector::MODE_YOAST )
		);
		$this->assertSame(
			'_yoast_wpseo_metadesc',
			Field_Mapper::meta_key( 'meta_description', SEO_Plugin_Detector::MODE_YOAST )
		);
		$this->assertSame(
			'_yoast_wpseo_opengraph-title',
			Field_Mapper::meta_key( 'og_title', SEO_Plugin_Detector::MODE_YOAST )
		);
		$this->assertSame(
			'_yoast_wpseo_opengraph-description',
			Field_Mapper::meta_key( 'og_description', SEO_Plugin_Detector::MODE_YOAST )
		);
		$this->assertSame(
			'_yoast_wpseo_metakeywords',
			Field_Mapper::meta_key( 'meta_keywords', SEO_Plugin_Detector::MODE_YOAST )
		);
	}

	/**
	 * Test Rank Math mode returns correct meta keys and null for meta_keywords.
	 *
	 * @return void
	 */
	public function test_rank_math_meta_keys(): void {
		$this->assertSame(
			'rank_math_title',
			Field_Mapper::meta_key( 'page_title', SEO_Plugin_Detector::MODE_RANK_MATH )
		);
		$this->assertSame(
			'rank_math_description',
			Field_Mapper::meta_key( 'meta_description', SEO_Plugin_Detector::MODE_RANK_MATH )
		);
		$this->assertSame(
			'rank_math_facebook_title',
			Field_Mapper::meta_key( 'og_title', SEO_Plugin_Detector::MODE_RANK_MATH )
		);
		$this->assertSame(
			'rank_math_facebook_description',
			Field_Mapper::meta_key( 'og_description', SEO_Plugin_Detector::MODE_RANK_MATH )
		);
		$this->assertNull(
			Field_Mapper::meta_key( 'meta_keywords', SEO_Plugin_Detector::MODE_RANK_MATH )
		);
	}

	/**
	 * Test AIOSEO mode returns correct meta keys.
	 *
	 * @return void
	 */
	public function test_aioseo_meta_keys(): void {
		$this->assertSame(
			'_aioseo_title',
			Field_Mapper::meta_key( 'page_title', SEO_Plugin_Detector::MODE_AIOSEO )
		);
		$this->assertSame(
			'_aioseo_description',
			Field_Mapper::meta_key( 'meta_description', SEO_Plugin_Detector::MODE_AIOSEO )
		);
		$this->assertSame(
			'_aioseo_og_title',
			Field_Mapper::meta_key( 'og_title', SEO_Plugin_Detector::MODE_AIOSEO )
		);
		$this->assertSame(
			'_aioseo_og_description',
			Field_Mapper::meta_key( 'og_description', SEO_Plugin_Detector::MODE_AIOSEO )
		);
		$this->assertNull(
			Field_Mapper::meta_key( 'meta_keywords', SEO_Plugin_Detector::MODE_AIOSEO )
		);
	}

	/**
	 * Test standalone mode uses the TESTAPP_META_PREFIX constant.
	 *
	 * Bootstrap defines TESTAPP_META_PREFIX = '_testmeta'.
	 *
	 * @return void
	 */
	public function test_standalone_meta_keys(): void {
		$this->assertSame(
			'_testmeta_title',
			Field_Mapper::meta_key( 'page_title', SEO_Plugin_Detector::MODE_STANDALONE )
		);
		$this->assertSame(
			'_testmeta_description',
			Field_Mapper::meta_key( 'meta_description', SEO_Plugin_Detector::MODE_STANDALONE )
		);
		$this->assertSame(
			'_testmeta_og_title',
			Field_Mapper::meta_key( 'og_title', SEO_Plugin_Detector::MODE_STANDALONE )
		);
		$this->assertSame(
			'_testmeta_og_description',
			Field_Mapper::meta_key( 'og_description', SEO_Plugin_Detector::MODE_STANDALONE )
		);
		$this->assertNull(
			Field_Mapper::meta_key( 'meta_keywords', SEO_Plugin_Detector::MODE_STANDALONE )
		);
	}

	/**
	 * Test unknown mode returns null.
	 *
	 * @return void
	 */
	public function test_unknown_mode_returns_null(): void {
		$this->assertNull(
			Field_Mapper::meta_key( 'page_title', 'nonexistent_mode' )
		);
	}

	/**
	 * Test unknown field returns null.
	 *
	 * @return void
	 */
	public function test_unknown_field_returns_null(): void {
		$this->assertNull(
			Field_Mapper::meta_key( 'nonexistent_field', SEO_Plugin_Detector::MODE_YOAST )
		);
	}
}
