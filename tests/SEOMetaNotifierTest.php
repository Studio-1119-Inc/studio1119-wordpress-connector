<?php
/**
 * Tests for the SEO_Meta_Notifier class.
 *
 * Uses real constants defined in bootstrap.php so Plugin::const_value()
 * resolves natively. In test environment, no SEO plugins are loaded so
 * detected mode is always 'standalone', and standalone meta keys use
 * the TESTAPP_META_PREFIX ('_testmeta').
 *
 * @package Studio1119\Connector\Tests
 */

namespace Studio1119\Connector\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Studio1119\Connector\Rest_Bridge;
use Studio1119\Connector\SEO_Meta_Notifier;

/**
 * Unit tests for SEO_Meta_Notifier.
 */
class SEOMetaNotifierTest extends TestCase {

	/**
	 * Set up Brain Monkey and reset notifier state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		SEO_Meta_Notifier::reset();

		// Reset Rest_Bridge writing flag via reflection.
		$prop = ( new \ReflectionClass( Rest_Bridge::class ) )->getProperty( 'writing' );
		$prop->setValue( null, false );
	}

	/**
	 * Tear down Brain Monkey after each test.
	 *
	 * Count Mockery expectations as PHPUnit assertions so tests using
	 * Functions\expect() are not marked risky.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$container = \Mockery::getContainer();
		if ( $container ) {
			$this->addToAssertionCount( $container->mockery_getExpectationCount() );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub common WordPress functions needed by the notifier.
	 *
	 * @return void
	 */
	private function stub_wp(): void {
		// get_option is called by Plugin::get_detected_mode().
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_site_url' )->justReturn( 'https://shop.example.com' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/**
	 * Test that on_meta_change collects changes for canonical SEO fields.
	 *
	 * @return void
	 */
	public function test_collects_changes_for_standalone_seo_fields(): void {
		$this->stub_wp();

		// In standalone mode with TESTAPP_META_PREFIX='_testmeta',
		// page_title maps to _testmeta_title.
		SEO_Meta_Notifier::on_meta_change( 1, 42, '_testmeta_title', 'New Title' );

		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				'https://app.cataseo.ai/api/woocommerce/webhooks/seo-meta',
				\Mockery::on(
					function ( $args ) {
						$body = json_decode( $args['body'], true );
						return 'seo.updated' === $body['event']
							&& 42 === $body['product_id']
							&& 'New Title' === $body['fields']['page_title']
							&& 'standalone' === $body['mode']
							&& 'https://shop.example.com' === $body['site_url'];
					}
				)
			);

		SEO_Meta_Notifier::deliver();
	}

	/**
	 * Test that non-SEO meta keys are ignored.
	 *
	 * @return void
	 */
	public function test_ignores_non_seo_meta_keys(): void {
		$this->stub_wp();

		SEO_Meta_Notifier::on_meta_change( 1, 42, '_some_random_key', 'value' );

		Functions\expect( 'wp_remote_post' )->never();

		SEO_Meta_Notifier::deliver();
	}

	/**
	 * Test that non-product post types are ignored.
	 *
	 * @return void
	 */
	public function test_ignores_non_product_post_types(): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'get_post_type' )->justReturn( 'post' );

		SEO_Meta_Notifier::on_meta_change( 1, 42, '_testmeta_title', 'New Title' );

		Functions\expect( 'wp_remote_post' )->never();
		Functions\when( 'get_site_url' )->justReturn( 'https://shop.example.com' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		SEO_Meta_Notifier::deliver();
	}

	/**
	 * Test that changes are skipped when Rest_Bridge is writing (loop prevention).
	 *
	 * @return void
	 */
	public function test_skips_when_rest_bridge_is_writing(): void {
		$this->stub_wp();

		// Simulate Rest_Bridge writing.
		$prop = ( new \ReflectionClass( Rest_Bridge::class ) )->getProperty( 'writing' );
		$prop->setValue( null, true );

		SEO_Meta_Notifier::on_meta_change( 1, 42, '_testmeta_title', 'New Title' );

		Functions\expect( 'wp_remote_post' )->never();

		SEO_Meta_Notifier::deliver();

		// Reset.
		$prop->setValue( null, false );
	}

	/**
	 * Test that multiple fields on the same product are batched into one notification.
	 *
	 * @return void
	 */
	public function test_batches_multiple_fields_per_product(): void {
		$this->stub_wp();

		SEO_Meta_Notifier::on_meta_change( 1, 42, '_testmeta_title', 'New Title' );
		SEO_Meta_Notifier::on_meta_change( 2, 42, '_testmeta_description', 'New Desc' );

		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				\Mockery::type( 'string' ),
				\Mockery::on(
					function ( $args ) {
						$body = json_decode( $args['body'], true );
						return 42 === $body['product_id']
							&& 'New Title' === $body['fields']['page_title']
							&& 'New Desc' === $body['fields']['meta_description'];
					}
				)
			);

		SEO_Meta_Notifier::deliver();
	}

	/**
	 * Test that different products get separate notifications.
	 *
	 * @return void
	 */
	public function test_separate_notifications_per_product(): void {
		$this->stub_wp();

		SEO_Meta_Notifier::on_meta_change( 1, 42, '_testmeta_title', 'Title A' );
		SEO_Meta_Notifier::on_meta_change( 2, 99, '_testmeta_title', 'Title B' );

		Functions\expect( 'wp_remote_post' )->twice();

		SEO_Meta_Notifier::deliver();
	}

	/**
	 * Test that deliver does nothing when no changes are pending.
	 *
	 * @return void
	 */
	public function test_deliver_noop_when_no_changes(): void {
		Functions\expect( 'wp_remote_post' )->never();

		SEO_Meta_Notifier::deliver();
	}

	/**
	 * Test reverse_lookup returns the correct canonical field name.
	 *
	 * @return void
	 */
	public function test_reverse_lookup_returns_canonical_field(): void {
		Functions\when( 'get_option' )->justReturn( false );

		// In standalone mode with TESTAPP_META_PREFIX='_testmeta'.
		$this->assertSame( 'page_title', SEO_Meta_Notifier::reverse_lookup( '_testmeta_title' ) );
		$this->assertSame( 'meta_description', SEO_Meta_Notifier::reverse_lookup( '_testmeta_description' ) );
		$this->assertSame( 'og_title', SEO_Meta_Notifier::reverse_lookup( '_testmeta_og_title' ) );
		$this->assertSame( 'og_description', SEO_Meta_Notifier::reverse_lookup( '_testmeta_og_description' ) );
	}

	/**
	 * Test reverse_lookup returns null for non-SEO meta keys.
	 *
	 * @return void
	 */
	public function test_reverse_lookup_returns_null_for_unknown_keys(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$this->assertNull( SEO_Meta_Notifier::reverse_lookup( '_unrelated_meta_key' ) );
		$this->assertNull( SEO_Meta_Notifier::reverse_lookup( 'post_title' ) );
	}

	/**
	 * Test the non-blocking POST uses correct headers and timeout.
	 *
	 * @return void
	 */
	public function test_deliver_uses_nonblocking_post(): void {
		$this->stub_wp();

		SEO_Meta_Notifier::on_meta_change( 1, 42, '_testmeta_title', 'Title' );

		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				\Mockery::type( 'string' ),
				\Mockery::on(
					function ( $args ) {
						return 'application/json' === $args['headers']['Content-Type']
							&& 5 === $args['timeout']
							&& false === $args['blocking'];
					}
				)
			);

		SEO_Meta_Notifier::deliver();
	}
}
