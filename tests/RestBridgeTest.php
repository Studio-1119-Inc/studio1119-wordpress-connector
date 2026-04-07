<?php
/**
 * Tests for the Rest_Bridge class — specifically the loop prevention flag.
 *
 * @package Studio1119\Connector\Tests
 */

namespace Studio1119\Connector\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Studio1119\Connector\Rest_Bridge;

/**
 * Unit tests for Rest_Bridge loop prevention.
 */
class RestBridgeTest extends TestCase {

	/**
	 * Set up Brain Monkey before each test and reset writing flag.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Reset the writing flag via reflection.
		$prop = ( new \ReflectionClass( Rest_Bridge::class ) )->getProperty( 'writing' );
		$prop->setValue( null, false );
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
	 * Test is_writing returns false by default.
	 *
	 * @return void
	 */
	public function test_is_writing_false_by_default(): void {
		$this->assertFalse( Rest_Bridge::is_writing() );
	}

	/**
	 * Test is_writing returns true when flag is set.
	 *
	 * @return void
	 */
	public function test_is_writing_true_when_set(): void {
		$prop = ( new \ReflectionClass( Rest_Bridge::class ) )->getProperty( 'writing' );
		$prop->setValue( null, true );

		$this->assertTrue( Rest_Bridge::is_writing() );
	}
}
