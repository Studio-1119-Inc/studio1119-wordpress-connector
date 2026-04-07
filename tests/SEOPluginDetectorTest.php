<?php
/**
 * Tests for the SEO_Plugin_Detector class.
 *
 * @package Studio1119\Connector\Tests
 */

namespace Studio1119\Connector\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Studio1119\Connector\SEO_Plugin_Detector;

/**
 * Unit tests for SEO_Plugin_Detector.
 */
class SEOPluginDetectorTest extends TestCase {

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
	 * Test that mode constants have expected string values.
	 *
	 * @return void
	 */
	public function test_mode_constants(): void {
		$this->assertSame( 'yoast', SEO_Plugin_Detector::MODE_YOAST );
		$this->assertSame( 'rankmath', SEO_Plugin_Detector::MODE_RANK_MATH );
		$this->assertSame( 'aioseo', SEO_Plugin_Detector::MODE_AIOSEO );
		$this->assertSame( 'standalone', SEO_Plugin_Detector::MODE_STANDALONE );
	}

	/**
	 * Test that detect returns standalone when no SEO plugins are loaded.
	 *
	 * In the test environment, none of the SEO plugin constants/classes
	 * are defined, so we should always get standalone.
	 *
	 * @return void
	 */
	public function test_detect_returns_standalone_when_no_seo_plugins(): void {
		$this->assertSame( 'standalone', SEO_Plugin_Detector::detect() );
	}

	/**
	 * Test is_standalone helper.
	 *
	 * @return void
	 */
	public function test_is_standalone(): void {
		// Without any SEO plugin loaded, should return true.
		$this->assertTrue( SEO_Plugin_Detector::is_standalone() );
	}
}
