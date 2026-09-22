<?php
/**
 * Tests for the standalone-mode admin notice.
 *
 * Two things this notice has to keep doing, both of which are WordPress.org
 * review items rather than cosmetics: it must be dismissible (guideline 11),
 * and every word of it must pass through the translation functions.
 *
 * @package Studio1119\Connector\Tests
 */

namespace Studio1119\Connector\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Studio1119\Connector\Admin_Page;

/**
 * Unit tests for Admin_Page::standalone_mode_notice().
 */
class StandaloneNoticeTest extends TestCase {

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
		$container = \Mockery::getContainer();
		if ( $container ) {
			$this->addToAssertionCount( $container->mockery_getExpectationCount() );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub the WordPress functions the notice calls.
	 *
	 * __() is stubbed to wrap its input in a marker, so a string that reaches
	 * the output without being translated is visible in the assertions.
	 *
	 * @param bool $has_title Whether the product already has an optimized title.
	 * @return void
	 */
	private function stub_wp( bool $has_title ): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'get_current_screen' )->justReturn(
			(object) array(
				'post_type' => 'product',
				'base'      => 'post',
			)
		);
		Functions\when( 'admin_url' )->alias(
			static function ( $path ) {
				return 'https://shop.example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'get_the_ID' )->justReturn( 94 );
		Functions\when( 'get_post_meta' )->justReturn( $has_title ? 'An optimized title' : '' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( '__' )->alias(
			static function ( $text, $domain = null ) {
				return '[' . $domain . ']' . $text;
			}
		);
	}

	/**
	 * Capture the notice output.
	 *
	 * @param bool $has_title Whether the product already has an optimized title.
	 * @return string
	 */
	private function render( bool $has_title ): string {
		$this->stub_wp( $has_title );
		ob_start();
		Admin_Page::standalone_mode_notice();
		return (string) ob_get_clean();
	}

	/**
	 * The "already optimized" notice must be dismissible.
	 *
	 * @return void
	 */
	public function test_success_notice_is_dismissible(): void {
		$html = $this->render( true );

		$this->assertStringContainsString( 'notice notice-success is-dismissible', $html );
	}

	/**
	 * The "no SEO plugin" notice must be dismissible.
	 *
	 * @return void
	 */
	public function test_info_notice_is_dismissible(): void {
		$html = $this->render( false );

		$this->assertStringContainsString( 'notice notice-info is-dismissible', $html );
	}

	/**
	 * Every sentence in the notice goes through __() with the app text domain.
	 *
	 * The marker the __() stub adds appears once for the notice body and once
	 * for the dashboard link label; prose that was concatenated as a bare
	 * English literal would appear without it.
	 *
	 * @return void
	 */
	public function test_notice_text_is_translated(): void {
		$html = $this->render( true );

		// The marker sits where the format string started, i.e. immediately
		// before the app name that %1$s was replaced with.
		$this->assertStringContainsString( '[testapp]<strong>TestApp</strong> has optimized', $html );
		$this->assertStringContainsString( '[testapp]TestApp dashboard', $html );
	}

	/**
	 * The dashboard link survives the kses pass and points at the plugin page.
	 *
	 * @return void
	 */
	public function test_notice_links_to_the_plugin_page(): void {
		$html = $this->render( false );

		$this->assertStringContainsString( '<a href="https://shop.example.com/wp-admin/admin.php?page=testapp-connector">', $html );
		$this->assertStringContainsString( '<strong>', $html );
	}
}
