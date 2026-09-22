<?php
/**
 * Tests that nothing leaves the site before the merchant connects the store.
 *
 * WordPress.org guideline 7 forbids contacting external servers without
 * explicit, authorized consent. In this plugin consent is the WooCommerce
 * authorization handshake, recorded by Admin_Page::is_connected(). Until it
 * returns true the outbound notifiers must neither attach their hooks nor
 * deliver anything they somehow collected.
 *
 * @package Studio1119\Connector\Tests
 */

namespace Studio1119\Connector\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Studio1119\Connector\Plugin;
use Studio1119\Connector\Rest_Bridge;
use Studio1119\Connector\SEO_Meta_Notifier;
use Studio1119\Connector\Taxonomy_Notifier;

/**
 * Unit tests for the pre-connection network gate.
 */
class ConnectionGateTest extends TestCase {

	/**
	 * Set up Brain Monkey and reset notifier state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		SEO_Meta_Notifier::reset();
		Taxonomy_Notifier::reset();

		$prop = ( new \ReflectionClass( Rest_Bridge::class ) )->getProperty( 'writing' );
		$prop->setValue( null, false );
	}

	/**
	 * Tear down Brain Monkey after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		SEO_Meta_Notifier::reset();
		Taxonomy_Notifier::reset();

		$container = \Mockery::getContainer();
		if ( $container ) {
			$this->addToAssertionCount( $container->mockery_getExpectationCount() );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub WordPress functions, choosing whether the store reads as connected.
	 *
	 * Admin_Page::is_connected() reads 'testapp_connected'; every other option
	 * read (the cached SEO mode) falls through to its default so detection
	 * runs live and returns 'standalone'.
	 *
	 * @param bool $connected Whether the connected option is set.
	 * @return void
	 */
	private function stub_wp( bool $connected ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( $connected ) {
				if ( 'testapp_connected' === $name ) {
					return $connected ? '1' : false;
				}
				return $default_value;
			}
		);
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_site_url' )->justReturn( 'https://shop.example.com' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	/**
	 * An unconnected store must not POST SEO meta changes anywhere.
	 *
	 * @return void
	 */
	public function test_seo_meta_notifier_sends_nothing_before_connection(): void {
		$this->stub_wp( false );

		SEO_Meta_Notifier::on_meta_change( 1, 42, '_testmeta_title', 'New Title' );

		Functions\expect( 'wp_remote_post' )->never();

		SEO_Meta_Notifier::deliver();
	}

	/**
	 * A connected store still POSTs SEO meta changes — the gate is the only change.
	 *
	 * @return void
	 */
	public function test_seo_meta_notifier_sends_once_connected(): void {
		$this->stub_wp( true );

		SEO_Meta_Notifier::on_meta_change( 1, 42, '_testmeta_title', 'New Title' );

		Functions\expect( 'wp_remote_post' )->once();

		SEO_Meta_Notifier::deliver();
	}

	/**
	 * An unconnected store must not POST taxonomy changes anywhere.
	 *
	 * @return void
	 */
	public function test_taxonomy_notifier_sends_nothing_before_connection(): void {
		$this->stub_wp( false );
		Functions\when( 'get_term' )->justReturn(
			(object) array(
				'taxonomy' => 'product_cat',
				'name'     => 'Hats',
			)
		);

		Taxonomy_Notifier::on_term_created( 7, 9 );

		Functions\expect( 'wp_remote_post' )->never();

		Taxonomy_Notifier::deliver();
	}

	/**
	 * A connected store still POSTs taxonomy changes.
	 *
	 * @return void
	 */
	public function test_taxonomy_notifier_sends_once_connected(): void {
		$this->stub_wp( true );
		Functions\when( 'get_term' )->justReturn(
			(object) array(
				'taxonomy' => 'product_cat',
				'name'     => 'Hats',
			)
		);

		Taxonomy_Notifier::on_term_created( 7, 9 );

		Functions\expect( 'wp_remote_post' )->once();

		Taxonomy_Notifier::deliver();
	}

	/**
	 * Boot must not attach the outbound hooks at all before connection.
	 *
	 * The delivery gate above is the backstop; this is the primary fix. If
	 * these hooks are never added, no merchant edit can even queue a payload.
	 *
	 * @return void
	 */
	public function test_boot_does_not_attach_notifier_hooks_before_connection(): void {
		$this->stub_wp( false );
		$this->stub_boot();

		Actions\expectAdded( 'updated_post_meta' )->never();
		Actions\expectAdded( 'added_post_meta' )->never();
		Actions\expectAdded( 'created_product_cat' )->never();
		Actions\expectAdded( 'edited_product_cat' )->never();
		Actions\expectAdded( 'delete_product_cat' )->never();

		Plugin::boot();
	}

	/**
	 * Boot attaches the outbound hooks once the store is connected.
	 *
	 * @return void
	 */
	public function test_boot_attaches_notifier_hooks_once_connected(): void {
		$this->stub_wp( true );
		$this->stub_boot();

		Actions\expectAdded( 'updated_post_meta' )->once();
		Actions\expectAdded( 'added_post_meta' )->once();
		Actions\expectAdded( 'created_product_cat' )->once();

		Plugin::boot();
	}

	/**
	 * The front-end meta output is local, so it registers either way.
	 *
	 * @return void
	 */
	public function test_boot_attaches_standalone_head_before_connection(): void {
		$this->stub_wp( false );
		$this->stub_boot();

		Actions\expectAdded( 'wp_head' )->once();

		Plugin::boot();
	}

	/**
	 * Stub the WordPress functions Plugin::boot() calls directly.
	 *
	 * @return void
	 */
	private function stub_boot(): void {
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'plugin_basename' )->justReturn( 'testapp/testapp.php' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'register_rest_route' )->justReturn( true );
	}
}
